<?php

namespace App\SalesOrders;

use App\Enums\DocumentKind;
use App\Enums\PartnerRole;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderAmounts;
use App\Orders\OrderLocks;
use App\Orders\OrderTransaction;
use App\Orders\OrderVersion;
use App\Stock\InsufficientStockException;
use App\Stock\StockEntry;
use App\Stock\StockRecordResult;
use App\Stock\StockTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;

/**
 * 受注の更新系（作成・編集・ステータス変更・出荷）。docs/spec.md §2〜§4。発注（App\PurchaseOrders\PurchaseOrderService）と同じ作り
 *
 * - 権限はルート（can:order.edit）と Policy（Form Request）で検査済み、入力は検証済み（SalesOrderRequest）の前提
 * - 金額は明細から計算する（画面から送られた合計は使わない。OrderAmounts）
 * - 伝票・明細・入出庫・操作履歴（audit_logs）は同じトランザクションで書く（履歴の保存に失敗したら全体を取り消す）
 * - 失敗は ValidationException（項目のエラー、form: 操作できない理由・混み合い、status: 遷移できない理由、conflict: 競合）。
 *   出荷で在庫が足りないときだけ InsufficientStockException（不足する商品と数量を持つ。全体は取り消し済み）
 *
 * 同時実行（ロックの順番。どの組み合わせでも待ちが一周しない。発注と同じ）:
 * - 作成: 顧客 FOR SHARE → 明細の商品 FOR SHARE（id 順）→ 採番の行（DocumentNumber）
 * - 編集: 顧客 FOR SHARE → 受注 FOR UPDATE → 明細の商品 FOR SHARE（id 順）
 * - ステータス変更（出荷以外）: 受注 FOR UPDATE。ロックした後に状態・明細の行数・updated_at を読む
 * - 出荷: StockTransaction::run の中で `UPDATE … WHERE id = ? AND status = 'confirmed' AND updated_at = ?` の件数を見てから、
 *   全明細の出庫を一度に記録する（商品 FOR UPDATE。id 順）。同時に 2 回出荷しても、2 本目は 1 本目の確定を待ってから
 *   WHERE を評価し直して 0 件になるので、二重に出庫しない。1 行でも在庫が足りなければ InsufficientStockException で
 *   受注の更新ごと取り消す（状態・updated_at・入出庫・操作履歴のどれも残らない）
 * - 編集・ステータス変更は、画面を開いた時点の updated_at（OrderVersion）と比べ、変わっていれば行わない（競合）
 *
 * @phpstan-type SalesOrderInput array{customer_id: int, due_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>}
 */
final class SalesOrderService
{
    /** 操作履歴の target_type */
    public const string TARGET_TYPE = 'sales_order';

    /** 操作履歴の action（ステータス変更） */
    public const string STATUS_ACTION = 'status_change';

    public static function conflictMessage(): string
    {
        return OrderVersion::conflictMessage('受注', '保存していません');
    }

    public static function statusConflictMessage(): string
    {
        return OrderVersion::conflictMessage('受注', 'ステータスは変更していません');
    }

    /** 出荷を中止したときのメッセージ（不足する品目の数を含む。Next.js 版と同じ） */
    public static function shortageMessage(InsufficientStockException $e): string
    {
        return '在庫が足りない商品が '.count($e->shortages).' 品目あるため、出荷しませんでした（在庫・ステータスは変わっていません）';
    }

    /**
     * 作成（draft）。受注日は作成した日（Asia/Tokyo）で、番号は SO-受注日-連番
     *
     * @param  SalesOrderInput  $input
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $input): SalesOrder
    {
        $orderDate = DocumentNumber::today();
        self::checkDueDate($orderDate, $input['due_date']);
        $amounts = self::amounts($input['lines']);

        return self::guardBusy('保存できませんでした（受注は作成していません）', fn () => OrderTransaction::run(function () use ($actor, $input, $orderDate, $amounts) {
            $customer = self::lockCustomer($input['customer_id']);
            $products = OrderLocks::lockProductsForLines(array_column($input['lines'], 'product_id'));
            self::throwIfAny(OrderLocks::checkLineProducts($input['lines'], $products));

            // 採番は検査の後（作成が失敗すればトランザクションごと戻るので欠番にならない）
            $number = DocumentNumber::next(DocumentKind::SalesOrder, $orderDate);
            $order = SalesOrder::query()->create([
                'number' => $number,
                'customer_id' => $customer->id,
                'order_date' => $orderDate,
                'due_date' => $input['due_date'],
                'status' => SalesOrderStatus::Draft,
                'note' => $input['note'],
                ...$amounts->toArray(),
            ]);
            self::insertLines($order->id, $input['lines']);
            $this->log($actor, 'create', $order->id, sprintf(
                '受注 %s を作成しました（顧客 %s「%s」・明細 %d 行・合計 %s 円）',
                $number,
                $customer->code,
                $customer->name,
                count($input['lines']),
                number_format($amounts->total),
            ));

            return $order;
        }));
    }

    /**
     * 編集（draft のときだけ）。expectedVersion は画面を開いた時点の updated_at（OrderVersion）で、変わっていれば保存しない（競合）。
     * 変更がなければ何もしない（操作履歴も残さない）
     *
     * @param  SalesOrderInput  $input
     * @return bool 変更したか
     *
     * @throws ValidationException
     */
    public function update(User $actor, int $id, array $input, mixed $expectedVersion): bool
    {
        $expected = OrderVersion::parse($expectedVersion);
        if ($expected === null) {
            throw ValidationException::withMessages(['conflict' => self::conflictMessage()]);
        }
        $amounts = self::amounts($input['lines']);

        return self::guardBusy('保存できませんでした', fn () => OrderTransaction::run(function () use ($actor, $id, $input, $expected, $amounts) {
            $customer = self::lockCustomer($input['customer_id']);
            $order = SalesOrder::query()->whereKey($id)->lockForUpdate()->first();
            if ($order === null) {
                abort(404);
            }
            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages([
                    'form' => "この受注は「{$order->status->label()}」のため編集できません（編集できるのは下書きだけです）",
                ]);
            }
            if (! OrderVersion::matches($order->updated_at, $expected)) {
                throw ValidationException::withMessages(['conflict' => self::conflictMessage()]);
            }
            self::checkDueDate($order->order_date->toDateString(), $input['due_date']);

            $before = SalesOrderLine::query()->where('sales_order_id', $id)->orderBy('id')->get(['product_id', 'quantity', 'unit_price']);
            $products = OrderLocks::lockProductsForLines(array_column($input['lines'], 'product_id'));
            // もとから明細にあった商品は、その後に無効になっていても残せる（新しく選ぶ商品だけ有効を求める）
            self::throwIfAny(OrderLocks::checkLineProducts(
                $input['lines'],
                $products,
                array_values($before->map(fn (SalesOrderLine $l) => $l->product_id)->all()),
            ));

            $beforeCustomer = Partner::query()->findOrFail($order->customer_id, ['id', 'code']);
            $changes = self::describeChanges(
                [
                    'customer' => $beforeCustomer,
                    'due_date' => $order->due_date?->toDateString(),
                    'note' => $order->note,
                    'lines' => array_values($before->map(fn (SalesOrderLine $l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price])->all()),
                    'total' => $order->total,
                ],
                [
                    'customer' => $customer,
                    'due_date' => $input['due_date'],
                    'note' => $input['note'],
                    'lines' => $input['lines'],
                    'total' => $amounts->total,
                ],
            );
            if ($changes === []) {
                return false;
            }

            SalesOrderLine::query()->where('sales_order_id', $id)->delete();
            self::insertLines($id, $input['lines']);
            // updated_at は今の値より必ず大きくする（OrderVersion::next。Eloquent の save は秒の精度で書くので使わない）
            SalesOrder::query()->whereKey($id)->update([
                'customer_id' => $customer->id,
                'due_date' => $input['due_date'],
                'note' => $input['note'],
                ...$amounts->toArray(),
                'updated_at' => OrderVersion::next($order->updated_at),
            ]);
            $this->log($actor, 'update', $id, "受注 {$order->number} を更新しました（".implode('、', $changes).'）');

            return true;
        }));
    }

    /**
     * ステータスを変更する（spec §4）。shipped では全明細の出庫（out）を同じトランザクションで記録する。
     * expectedVersion は詳細画面に表示した時点の updated_at。変わっていれば変更しない（競合。他の人が編集した、
     * 見ていない明細のまま確定・出荷しないため）。遷移できない状態なら、競合より先にその理由を返す。
     * 戻り値の stock は出荷で記録した入出庫の結果（出荷以外は null）。stock->fellBelowReorderPoint が「発注点以上 → 未満」に
     * 変わった有効な商品で、発注点割れの通知（#11）は run の確定後にこれで送る
     *
     * @return array{number: string, from: SalesOrderStatus, to: SalesOrderStatus, stock: StockRecordResult|null}
     *
     * @throws ValidationException
     * @throws InsufficientStockException 出荷で在庫が 1 行でも足りない（受注・在庫・操作履歴は変わっていない）
     */
    public function changeStatus(User $actor, int $id, SalesOrderStatus $to, mixed $expectedVersion): array
    {
        if ($to === SalesOrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => '変更先のステータスが正しくありません']);
        }
        $expected = OrderVersion::parse($expectedVersion);
        if ($expected === null) {
            throw ValidationException::withMessages(['conflict' => self::statusConflictMessage()]);
        }
        if ($to === SalesOrderStatus::Shipped) {
            return self::guardBusy('出荷できませんでした（在庫は変わっていません）', fn () => $this->ship($actor, $id, $expected));
        }

        return self::guardBusy('ステータスを変更できませんでした', fn () => OrderTransaction::run(function () use ($actor, $id, $to, $expected) {
            $order = SalesOrder::query()->whereKey($id)->lockForUpdate()->first(['id', 'number', 'status', 'updated_at']);
            if ($order === null) {
                abort(404);
            }
            $lineCount = SalesOrderLine::query()->where('sales_order_id', $id)->count();
            $error = $order->status->transitionError($to, $lineCount);
            if ($error !== null) {
                throw ValidationException::withMessages(['status' => $error]);
            }
            if (! OrderVersion::matches($order->updated_at, $expected)) {
                throw ValidationException::withMessages(['conflict' => self::statusConflictMessage()]);
            }

            SalesOrder::query()->whereKey($id)->update([
                'status' => $to->value,
                'updated_at' => OrderVersion::next($order->updated_at),
            ]);
            $this->log($actor, self::STATUS_ACTION, $id, self::statusSummary($order->number, $order->status, $to));

            return ['number' => $order->number, 'from' => $order->status, 'to' => $to, 'stock' => null];
        }));
    }

    /**
     * 出荷（confirmed → shipped）。全明細を出庫として一度に記録する。出荷では顧客・商品の有効を問わない
     * （spec §3 の制限は「新規の」受注で選べないこと。発注の入荷・Next.js 版と同じ）
     *
     * @return array{number: string, from: SalesOrderStatus, to: SalesOrderStatus, stock: StockRecordResult}
     *
     * @throws InsufficientStockException
     */
    private function ship(User $actor, int $id, string $expected): array
    {
        return StockTransaction::run(function (StockTransaction $stock) use ($actor, $id, $expected) {
            // 条件付き更新で二重出荷を防ぐ（同時に実行された 2 本目は、1 本目の確定後に WHERE を評価し直して 0 件になる）。
            // 画面に表示した時点の updated_at も条件に入れる（他の人の変更の後なら 0 件 → 競合）。モデルは使わない
            // （再試行でコールバックが 2 回動いても同じ UPDATE を出す）。この UPDATE は商品を参照する列を変えないので、
            // 商品の行に共有ロックを取らない（StockTransaction の契約: lockProducts より前に行ってよい）
            $updated = DB::table('sales_orders')
                ->where('id', $id)
                ->where('status', SalesOrderStatus::Confirmed->value)
                ->where('updated_at', $expected)
                ->update(['status' => SalesOrderStatus::Shipped->value, 'updated_at' => OrderVersion::next($expected)]);
            if ($updated === 0) {
                $current = SalesOrder::query()->find($id, ['id', 'status']);
                if ($current === null) {
                    abort(404);
                }
                $lineCount = SalesOrderLine::query()->where('sales_order_id', $id)->count();
                $error = $current->status->transitionError(SalesOrderStatus::Shipped, $lineCount);
                throw ValidationException::withMessages($error !== null ? ['status' => $error] : ['conflict' => self::statusConflictMessage()]);
            }

            $order = SalesOrder::query()->findOrFail($id, ['id', 'number']);
            $lines = SalesOrderLine::query()->where('sales_order_id', $id)->orderBy('id')->get(['product_id', 'quantity']);
            if ($lines->isEmpty()) {
                // confirmed には明細が 1 行以上ある（確定にするときに検査している）。ここに来たらデータの誤り
                throw new LogicException("受注 {$order->number} に明細がありません");
            }
            // 全明細を一度に渡す（商品のロックは id の順にまとめて取られる）。1 行でも在庫が足りなければ
            // InsufficientStockException が投げられ、上の受注の更新も含めてトランザクションごと取り消される
            $result = $stock->record($actor->id, array_values($lines->map(fn (SalesOrderLine $line) => new StockEntry(
                $line->product_id,
                StockMovementType::Out,
                -$line->quantity,
                "受注 {$order->number} の出荷",
                StockRefType::SalesOrder,
                $id,
            ))->all()));
            $this->log($actor, self::STATUS_ACTION, $id, self::statusSummary($order->number, SalesOrderStatus::Confirmed, SalesOrderStatus::Shipped)."（出庫 {$lines->count()} 品目）");

            return ['number' => $order->number, 'from' => SalesOrderStatus::Confirmed, 'to' => SalesOrderStatus::Shipped, 'stock' => $result];
        });
    }

    /**
     * 変更点の説明（操作履歴の summary 用）。変更がなければ空配列
     *
     * @param  array{customer: Partner, due_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>, total: int}  $before
     * @param  array{customer: Partner, due_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>, total: int}  $after
     * @return list<string>
     */
    public static function describeChanges(array $before, array $after): array
    {
        $changes = [];
        if ($before['customer']->id !== $after['customer']->id) {
            $changes[] = "顧客: {$before['customer']->code} → {$after['customer']->code}";
        }
        if ($before['due_date'] !== $after['due_date']) {
            $changes[] = '納期: '.($before['due_date'] ?? '（なし）').' → '.($after['due_date'] ?? '（なし）');
        }
        if (($before['note'] ?? '') !== ($after['note'] ?? '')) {
            $changes[] = '備考を変更';
        }
        if ($before['lines'] !== $after['lines']) {
            $changes[] = '明細: '.count($before['lines']).' 行 → '.count($after['lines']).' 行';
        }
        if ($before['total'] !== $after['total']) {
            $changes[] = '合計: '.number_format($before['total']).' → '.number_format($after['total']).' 円';
        }

        return $changes;
    }

    private static function statusSummary(string $number, SalesOrderStatus $from, SalesOrderStatus $to): string
    {
        return "受注 {$number} のステータスを「{$from->label()}」から「{$to->label()}」に変更しました";
    }

    /** 顧客の行を FOR SHARE でロックして種別（customer・both）と有効を検査する */
    private static function lockCustomer(int $customerId): Partner
    {
        $customer = OrderLocks::lockPartnerForRole($customerId, PartnerRole::Customer);
        if (is_string($customer)) {
            throw ValidationException::withMessages(['customer_id' => $customer]);
        }

        return $customer;
    }

    /** @param  list<array{product_id: int, quantity: int, unit_price: int}>  $lines */
    private static function amounts(array $lines): OrderAmounts
    {
        // 検証（OrderValidation::checkLines）で上限を確かめているので、通常は null にならない
        return OrderAmounts::calculate($lines)
            ?? throw ValidationException::withMessages(['lines' => '合計金額が上限（'.number_format(OrderAmounts::MAX_TOTAL).' 円）を超えています']);
    }

    /** 納期は受注日以降（spec にはないが、逆転した日付は入力の誤りとして扱う。Next.js 版と同じ） */
    private static function checkDueDate(string $orderDate, ?string $dueDate): void
    {
        if ($dueDate !== null && $dueDate < $orderDate) {
            throw ValidationException::withMessages(['due_date' => "納期は受注日（{$orderDate}）以降の日付にしてください"]);
        }
    }

    /** @param  array<string, string>  $errors */
    private static function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 明細を INSERT する（入力の順。商品の行は FOR SHARE で先にロック済みなので、外部キー検査で新たな待ちは生じない）
     *
     * @param  list<array{product_id: int, quantity: int, unit_price: int}>  $lines
     */
    private static function insertLines(int $orderId, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        SalesOrderLine::query()->insert(array_map(fn (array $line) => [
            'sales_order_id' => $orderId,
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
        ], $lines));
    }

    /**
     * ロック待ちのタイムアウト・やり直しきれなかったデッドロックを「混み合っています」の検証エラーにする（全体は取り消し済み）
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function guardBusy(string $notDone, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (PDOException $e) {
            if (! OrderTransaction::isBusy($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['form' => OrderTransaction::busyMessage($notDone)]);
        }
    }

    private function log(User $actor, string $action, int $orderId, string $summary): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => self::TARGET_TYPE,
            'target_id' => (string) $orderId,
            'summary' => $summary,
        ]);
    }
}
