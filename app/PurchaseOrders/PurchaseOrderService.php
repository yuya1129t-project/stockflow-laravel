<?php

namespace App\PurchaseOrders;

use App\Enums\DocumentKind;
use App\Enums\PartnerRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderAmounts;
use App\Orders\OrderLocks;
use App\Orders\OrderTransaction;
use App\Orders\OrderVersion;
use App\Stock\StockEntry;
use App\Stock\StockRecordResult;
use App\Stock\StockTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;

/**
 * 発注の更新系（作成・編集・ステータス変更・入荷）。docs/spec.md §2〜§4
 *
 * - 権限はルート（can:order.edit）と Policy（Form Request）で検査済み、入力は検証済み（PurchaseOrderRequest）の前提
 * - 金額は明細から計算する（画面から送られた合計は使わない。OrderAmounts）
 * - 伝票・明細・入出庫・操作履歴（audit_logs）は同じトランザクションで書く（履歴の保存に失敗したら全体を取り消す）
 * - 失敗は ValidationException（項目のエラー、form: 操作できない理由・混み合い、status: 遷移できない理由、conflict: 競合）
 *
 * 同時実行（ロックの順番。どの組み合わせでも待ちが一周しない）:
 * - 作成: 仕入先 FOR SHARE → 明細の商品 FOR SHARE（id 順）→ 採番の行（DocumentNumber）
 * - 編集: 仕入先 FOR SHARE → 発注 FOR UPDATE → 明細の商品 FOR SHARE（id 順）。
 *   仕入先を発注より先にロックする（取引先の編集は「取引先 FOR UPDATE → その取引先の発注を FOR SHARE で数える」ので、
 *   発注を先に持ったまま取引先を待つと、待ちが一周する）
 * - ステータス変更（入荷以外）: 発注 FOR UPDATE。ロックした後に状態・明細の行数・updated_at を読む
 * - 入荷: StockTransaction::run の中で `UPDATE … WHERE id = ? AND status = 'ordered' AND updated_at = ?` の件数を見てから、
 *   全明細の入庫を一度に記録する（商品 FOR UPDATE。id 順）。同時に 2 回入荷しても、2 本目は 1 本目の確定を待ってから
 *   WHERE を評価し直して 0 件になるので、二重に入庫しない
 * - 編集・ステータス変更は、画面を開いた時点の updated_at（OrderVersion）と比べ、変わっていれば行わない（競合）
 *
 * @phpstan-type PurchaseOrderInput array{supplier_id: int, expected_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>}
 */
final class PurchaseOrderService
{
    /** 操作履歴の target_type */
    public const string TARGET_TYPE = 'purchase_order';

    /** 操作履歴の action（ステータス変更） */
    public const string STATUS_ACTION = 'status_change';

    public static function conflictMessage(): string
    {
        return OrderVersion::conflictMessage('発注', '保存していません');
    }

    public static function statusConflictMessage(): string
    {
        return OrderVersion::conflictMessage('発注', 'ステータスは変更していません');
    }

    /**
     * 作成（draft）。発注日は作成した日（Asia/Tokyo）で、番号は PO-発注日-連番
     *
     * @param  PurchaseOrderInput  $input
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $input): PurchaseOrder
    {
        $orderDate = DocumentNumber::today();
        self::checkExpectedDate($orderDate, $input['expected_date']);
        $amounts = self::amounts($input['lines']);

        return self::guardBusy('保存できませんでした（発注は作成していません）', fn () => OrderTransaction::run(function () use ($actor, $input, $orderDate, $amounts) {
            $supplier = self::lockSupplier($input['supplier_id']);
            $products = OrderLocks::lockProductsForLines(array_column($input['lines'], 'product_id'));
            self::throwIfAny(OrderLocks::checkLineProducts($input['lines'], $products));

            // 採番は検査の後（作成が失敗すればトランザクションごと戻るので欠番にならない）
            $number = DocumentNumber::next(DocumentKind::PurchaseOrder, $orderDate);
            $order = PurchaseOrder::query()->create([
                'number' => $number,
                'supplier_id' => $supplier->id,
                'order_date' => $orderDate,
                'expected_date' => $input['expected_date'],
                'status' => PurchaseOrderStatus::Draft,
                'note' => $input['note'],
                ...$amounts->toArray(),
            ]);
            self::insertLines($order->id, $input['lines']);
            $this->log($actor, 'create', $order->id, sprintf(
                '発注 %s を作成しました（仕入先 %s「%s」・明細 %d 行・合計 %s 円）',
                $number,
                $supplier->code,
                $supplier->name,
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
     * @param  PurchaseOrderInput  $input
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
            $supplier = self::lockSupplier($input['supplier_id']);
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->first();
            if ($order === null) {
                abort(404);
            }
            if (! $order->status->isEditable()) {
                throw ValidationException::withMessages([
                    'form' => "この発注は「{$order->status->label()}」のため編集できません（編集できるのは下書きだけです）",
                ]);
            }
            if (! OrderVersion::matches($order->updated_at, $expected)) {
                throw ValidationException::withMessages(['conflict' => self::conflictMessage()]);
            }
            self::checkExpectedDate($order->order_date->toDateString(), $input['expected_date']);

            $before = PurchaseOrderLine::query()->where('purchase_order_id', $id)->orderBy('id')->get(['product_id', 'quantity', 'unit_price']);
            $products = OrderLocks::lockProductsForLines(array_column($input['lines'], 'product_id'));
            // もとから明細にあった商品は、その後に無効になっていても残せる（新しく選ぶ商品だけ有効を求める）
            self::throwIfAny(OrderLocks::checkLineProducts(
                $input['lines'],
                $products,
                array_values($before->map(fn (PurchaseOrderLine $l) => $l->product_id)->all()),
            ));

            $beforeSupplier = Partner::query()->findOrFail($order->supplier_id, ['id', 'code']);
            $changes = self::describeChanges(
                [
                    'supplier' => $beforeSupplier,
                    'expected_date' => $order->expected_date?->toDateString(),
                    'note' => $order->note,
                    'lines' => array_values($before->map(fn (PurchaseOrderLine $l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price])->all()),
                    'total' => $order->total,
                ],
                [
                    'supplier' => $supplier,
                    'expected_date' => $input['expected_date'],
                    'note' => $input['note'],
                    'lines' => $input['lines'],
                    'total' => $amounts->total,
                ],
            );
            if ($changes === []) {
                return false;
            }

            PurchaseOrderLine::query()->where('purchase_order_id', $id)->delete();
            self::insertLines($id, $input['lines']);
            // updated_at は今の値より必ず大きくする（OrderVersion::next。Eloquent の save は秒の精度で書くので使わない）
            PurchaseOrder::query()->whereKey($id)->update([
                'supplier_id' => $supplier->id,
                'expected_date' => $input['expected_date'],
                'note' => $input['note'],
                ...$amounts->toArray(),
                'updated_at' => OrderVersion::next($order->updated_at),
            ]);
            $this->log($actor, 'update', $id, "発注 {$order->number} を更新しました（".implode('、', $changes).'）');

            return true;
        }));
    }

    /**
     * ステータスを変更する（spec §4）。received では全明細の入庫（in）を同じトランザクションで記録する。
     * expectedVersion は詳細画面に表示した時点の updated_at。変わっていれば変更しない（競合。他の人が編集した、
     * 見ていない明細のまま発注済にしないため）。遷移できない状態なら、競合より先にその理由を返す。
     * 戻り値の stock は入荷で記録した入出庫の結果（入荷以外は null。発注点割れの通知（#11）は確定後にこれで送る）
     *
     * @return array{number: string, from: PurchaseOrderStatus, to: PurchaseOrderStatus, stock: StockRecordResult|null}
     *
     * @throws ValidationException
     */
    public function changeStatus(User $actor, int $id, PurchaseOrderStatus $to, mixed $expectedVersion): array
    {
        if ($to === PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => '変更先のステータスが正しくありません']);
        }
        $expected = OrderVersion::parse($expectedVersion);
        if ($expected === null) {
            throw ValidationException::withMessages(['conflict' => self::statusConflictMessage()]);
        }
        if ($to === PurchaseOrderStatus::Received) {
            return self::guardBusy('入荷を記録できませんでした（在庫は変わっていません）', fn () => $this->receive($actor, $id, $expected));
        }

        return self::guardBusy('ステータスを変更できませんでした', fn () => OrderTransaction::run(function () use ($actor, $id, $to, $expected) {
            $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->first(['id', 'number', 'status', 'updated_at']);
            if ($order === null) {
                abort(404);
            }
            $lineCount = PurchaseOrderLine::query()->where('purchase_order_id', $id)->count();
            $error = $order->status->transitionError($to, $lineCount);
            if ($error !== null) {
                throw ValidationException::withMessages(['status' => $error]);
            }
            if (! OrderVersion::matches($order->updated_at, $expected)) {
                throw ValidationException::withMessages(['conflict' => self::statusConflictMessage()]);
            }

            PurchaseOrder::query()->whereKey($id)->update([
                'status' => $to->value,
                'updated_at' => OrderVersion::next($order->updated_at),
            ]);
            $this->log($actor, self::STATUS_ACTION, $id, self::statusSummary($order->number, $order->status, $to));

            return ['number' => $order->number, 'from' => $order->status, 'to' => $to, 'stock' => null];
        }));
    }

    /**
     * 入荷（ordered → received）。全明細を入庫として記録する
     *
     * @return array{number: string, from: PurchaseOrderStatus, to: PurchaseOrderStatus, stock: StockRecordResult}
     */
    private function receive(User $actor, int $id, string $expected): array
    {
        return StockTransaction::run(function (StockTransaction $stock) use ($actor, $id, $expected) {
            // 条件付き更新で二重入荷を防ぐ（同時に実行された 2 本目は、1 本目の確定後に WHERE を評価し直して 0 件になる）。
            // 画面に表示した時点の updated_at も条件に入れる（他の人の変更の後なら 0 件 → 競合）。モデルは使わない
            // （再試行でコールバックが 2 回動いても同じ UPDATE を出す）
            $updated = DB::table('purchase_orders')
                ->where('id', $id)
                ->where('status', PurchaseOrderStatus::Ordered->value)
                ->where('updated_at', $expected)
                ->update(['status' => PurchaseOrderStatus::Received->value, 'updated_at' => OrderVersion::next($expected)]);
            if ($updated === 0) {
                $current = PurchaseOrder::query()->find($id, ['id', 'status']);
                if ($current === null) {
                    abort(404);
                }
                $lineCount = PurchaseOrderLine::query()->where('purchase_order_id', $id)->count();
                $error = $current->status->transitionError(PurchaseOrderStatus::Received, $lineCount);
                throw ValidationException::withMessages($error !== null ? ['status' => $error] : ['conflict' => self::statusConflictMessage()]);
            }

            $order = PurchaseOrder::query()->findOrFail($id, ['id', 'number']);
            $lines = PurchaseOrderLine::query()->where('purchase_order_id', $id)->orderBy('id')->get(['product_id', 'quantity']);
            if ($lines->isEmpty()) {
                // ordered には明細が 1 行以上ある（発注済にするときに検査している）。ここに来たらデータの誤り
                throw new LogicException("発注 {$order->number} に明細がありません");
            }
            // 全明細を一度に渡す（商品のロックは id の順にまとめて取られる）
            $result = $stock->record($actor->id, array_values($lines->map(fn (PurchaseOrderLine $line) => new StockEntry(
                $line->product_id,
                StockMovementType::In,
                $line->quantity,
                "発注 {$order->number} の入荷",
                StockRefType::PurchaseOrder,
                $id,
            ))->all()));
            $this->log($actor, self::STATUS_ACTION, $id, self::statusSummary($order->number, PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Received)."（入庫 {$lines->count()} 品目）");

            return ['number' => $order->number, 'from' => PurchaseOrderStatus::Ordered, 'to' => PurchaseOrderStatus::Received, 'stock' => $result];
        });
    }

    /**
     * 変更点の説明（操作履歴の summary 用）。変更がなければ空配列
     *
     * @param  array{supplier: Partner, expected_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>, total: int}  $before
     * @param  array{supplier: Partner, expected_date: string|null, note: string|null, lines: list<array{product_id: int, quantity: int, unit_price: int}>, total: int}  $after
     * @return list<string>
     */
    public static function describeChanges(array $before, array $after): array
    {
        $changes = [];
        if ($before['supplier']->id !== $after['supplier']->id) {
            $changes[] = "仕入先: {$before['supplier']->code} → {$after['supplier']->code}";
        }
        if ($before['expected_date'] !== $after['expected_date']) {
            $changes[] = '入荷予定日: '.($before['expected_date'] ?? '（なし）').' → '.($after['expected_date'] ?? '（なし）');
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

    private static function statusSummary(string $number, PurchaseOrderStatus $from, PurchaseOrderStatus $to): string
    {
        return "発注 {$number} のステータスを「{$from->label()}」から「{$to->label()}」に変更しました";
    }

    /** 仕入先の行を FOR SHARE でロックして種別（supplier・both）と有効を検査する */
    private static function lockSupplier(int $supplierId): Partner
    {
        $supplier = OrderLocks::lockPartnerForRole($supplierId, PartnerRole::Supplier);
        if (is_string($supplier)) {
            throw ValidationException::withMessages(['supplier_id' => $supplier]);
        }

        return $supplier;
    }

    /** @param  list<array{product_id: int, quantity: int, unit_price: int}>  $lines */
    private static function amounts(array $lines): OrderAmounts
    {
        // 検証（OrderValidation::checkLines）で上限を確かめているので、通常は null にならない
        return OrderAmounts::calculate($lines)
            ?? throw ValidationException::withMessages(['lines' => '合計金額が上限（'.number_format(OrderAmounts::MAX_TOTAL).' 円）を超えています']);
    }

    /** 入荷予定日は発注日以降（spec にはないが、逆転した日付は入力の誤りとして扱う。Next.js 版と同じ） */
    private static function checkExpectedDate(string $orderDate, ?string $expectedDate): void
    {
        if ($expectedDate !== null && $expectedDate < $orderDate) {
            throw ValidationException::withMessages(['expected_date' => "入荷予定日は発注日（{$orderDate}）以降の日付にしてください"]);
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
        PurchaseOrderLine::query()->insert(array_map(fn (array $line) => [
            'purchase_order_id' => $orderId,
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
