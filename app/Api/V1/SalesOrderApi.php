<?php

namespace App\Api\V1;

use App\Http\Requests\SalesOrderRequest;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Orders\OrderValidation;
use App\SalesOrders\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * REST API の受注の登録（POST /api/v1/sales-orders）。Next.js 版 src/lib/api/v1/sales-orders.ts と同じ流れ。
 *
 * 本文（SalesOrderCreateBody で検査済み）の顧客コード・商品コードを ID に解決し、単価を商品マスタの売価にしてから、
 * 画面と同じ入力の検証（SalesOrderRequest の rules・after。明細の重複・合計の上限・備考の制御文字など）と、
 * 画面と同じ受注のサービス（SalesOrderService::create。顧客・商品の行ロックと種別・有効の再検査、採番、金額の計算、
 * 操作履歴）で作成する。画面の項目のキー（customer_id・lines.N.product_id など）は本文の JSON Pointer に読み替える。
 *
 * コードの解決はトランザクションの外で行う（存在の確認と売価の読み取りだけ）。種別・有効はサービスがトランザクションの中で
 * 行をロックしてから検査し直すので、その間に無効化されても作成されない。売価だけは読み取りから作成までの間の変更が
 * 反映されない（リクエストを受けた時点の売価になる。Next.js 版と同じ）
 *
 * @phpstan-import-type FieldError from ApiResponse
 * @phpstan-import-type CreateInput from SalesOrderCreateBody
 */
final class SalesOrderApi
{
    public function __construct(private readonly SalesOrderService $service) {}

    /**
     * 受注を作成する。本文・業務の検証の誤りは 400 の応答
     *
     * @param  CreateInput  $body
     */
    public function create(User $actor, int $apiTokenId, array $body): SalesOrder|JsonResponse
    {
        $customerCode = strtoupper(mb_trim($body['customerCode']));
        $productCodes = array_map(fn (array $line) => strtoupper(mb_trim($line['productCode'])), $body['lines']);

        $errors = [];
        $customer = Partner::query()->where('code', $customerCode)->first(['id', 'code']);
        if ($customer === null || strtoupper($customer->code) !== $customerCode) {
            $errors[] = ['pointer' => '#/customerCode', 'detail' => "顧客「{$customerCode}」が見つかりません"];
        }
        $products = Product::query()->whereIn('code', array_values(array_unique($productCodes)))->get(['id', 'code', 'sales_price'])
            ->keyBy(fn (Product $p) => strtoupper($p->code));
        $lines = [];
        foreach ($body['lines'] as $index => $line) {
            $product = $products->get($productCodes[$index]);
            if ($product === null) {
                $errors[] = ['pointer' => ApiJsonBody::pointer(['lines', $index, 'productCode']), 'detail' => "商品「{$productCodes[$index]}」が見つかりません"];

                continue;
            }
            $lines[] = ['product_id' => (string) $product->id, 'quantity' => (string) $line['quantity'], 'unit_price' => (string) $product->sales_price];
        }
        if ($errors !== [] || $customer === null) {
            return ApiResponse::validation($errors);
        }

        // 画面の作成と同じ検証（SalesOrderRequest の prepareForValidation と同じ正規化をしてから）
        $form = new SalesOrderRequest;
        $validator = Validator::make([
            'customer_id' => (string) $customer->id,
            'due_date' => $body['dueDate'],
            'note' => OrderValidation::normalizeNote($body['note']),
            'lines' => $lines,
        ], $form->rules(), $form->messages(), $form->attributes())->after($form->after());
        if ($validator->fails()) {
            return ApiResponse::validation(self::fieldErrors($validator->errors()->toArray()));
        }
        /** @var array{customer_id: string, due_date?: string|null, note?: string|null, lines: list<array<string, string>>} $validated */
        $validated = $validator->validated();

        try {
            return $this->service->create($actor, [
                'customer_id' => $customer->id,
                'due_date' => $validated['due_date'] ?? null,
                'note' => $validated['note'] ?? null,
                'lines' => OrderValidation::toLines($validated['lines']),
            ], $apiTokenId);
        } catch (ValidationException $e) {
            $errors = self::fieldErrors($e->errors());
            // 項目に結びつかない失敗（混み合っている＝ロック待ちのタイムアウトなど）は detail に入れる
            $form = $e->errors()['form'][0] ?? null;

            return $errors === [] && is_string($form) ? ApiResponse::problem(400, $form) : ApiResponse::validation($errors);
        }
    }

    /**
     * 画面の項目のキー → 本文の JSON Pointer（項目に結びつかないもの（form）は含めない）
     *
     * - customer_id → #/customerCode、due_date → #/dueDate、note → #/note、lines → #/lines
     * - lines.N.product_id・lines.N.unit_price → #/lines/N/productCode（単価は商品マスタの売価から決まるので、商品の誤りとして示す）
     * - lines.N.quantity → #/lines/N/quantity
     */
    public static function pointerFor(string $key): ?string
    {
        $parts = explode('.', $key);
        $field = match ($parts[0]) {
            'customer_id' => 'customerCode',
            'due_date' => 'dueDate',
            'note' => 'note',
            'lines' => 'lines',
            default => null,
        };
        if ($field === null) {
            return null;
        }
        $path = [$field];
        if (isset($parts[1])) {
            $path[] = ctype_digit($parts[1]) ? (int) $parts[1] : $parts[1];
        }
        if (isset($parts[2])) {
            $path[] = $parts[2] === 'quantity' ? 'quantity' : 'productCode';
        }

        return ApiJsonBody::pointer($path);
    }

    /**
     * @param  array<array-key, mixed>  $messages  項目のキー → エラー文の一覧
     * @return list<FieldError>
     */
    private static function fieldErrors(array $messages): array
    {
        $errors = [];
        foreach ($messages as $key => $list) {
            $pointer = self::pointerFor((string) $key);
            if ($pointer === null || ! is_array($list)) {
                continue;
            }
            foreach ($list as $detail) {
                if (is_string($detail)) {
                    $errors[] = ['pointer' => $pointer, 'detail' => $detail];
                }
            }
        }

        return $errors;
    }
}
