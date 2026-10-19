<?php

namespace App\Stock;

use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Products\ProductService;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * 棚卸調整: 実数を入力 → 現在庫との差分を adjust として記録する（docs/spec.md §4）。
 *
 * - 権限はルート（can:stock.adjust）とコントローラー（Policy）で検査済み、入力は検証済み（StockAdjustRequest）の前提
 * - 差分は商品の行ロックを取った後の現在庫から求める（画面を開いた後に入出庫があっても、記録後の在庫は入力した実数になる）
 * - 差分が 0 なら何も記録しない（入出庫も操作履歴も残さない）
 * - 入出庫と操作履歴（audit_logs）は同じトランザクションで書く
 */
final class StockAdjustment
{
    use DetectsConcurrencyErrors;

    /** 操作履歴の action（target_type は商品） */
    public const string ACTION = 'stock_adjust';

    /**
     * @return array{recorded: bool, before: int, after: int, delta: int, fellBelowReorderPoint: list<StockChange>}
     *
     * @throws ValidationException ほかの操作との競合（デッドロックなど）を再試行しきれなかった
     */
    public function adjust(User $actor, Product $product, int $actual, string $reason): array
    {
        try {
            return StockTransaction::run(function (StockTransaction $stock) use ($actor, $product, $actual, $reason) {
                $locked = $stock->lockProducts([$product->id])[$product->id] ?? null;
                if ($locked === null) {
                    // ルートモデル結合の後に消えた（商品は物理削除しない運用なので通常は起きない）
                    abort(404);
                }
                $before = $locked->stock;
                $delta = $actual - $before;
                if ($delta === 0) {
                    return ['recorded' => false, 'before' => $before, 'after' => $before, 'delta' => 0, 'fellBelowReorderPoint' => []];
                }

                $result = $stock->record($actor->id, [new StockEntry($product->id, StockMovementType::Adjust, $delta, $reason)]);
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => self::ACTION,
                    'target_type' => ProductService::TARGET_TYPE,
                    'target_id' => (string) $product->id,
                    'summary' => sprintf(
                        '商品 %s の在庫を棚卸で調整しました（%s → %s%s、差分 %s、理由: %s）',
                        $locked->code,
                        number_format($before),
                        number_format($actual),
                        $locked->unit,
                        ($delta > 0 ? '+' : '').number_format($delta),
                        $reason,
                    ),
                ]);

                return ['recorded' => true, 'before' => $before, 'after' => $actual, 'delta' => $delta, 'fellBelowReorderPoint' => $result->fellBelowReorderPoint];
            });
        } catch (PDOException $e) {
            // デッドロック・ロック待ちのタイムアウトを再試行しきれなかった（全体はロールバック済み）
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }
            throw ValidationException::withMessages([
                'actual_quantity' => 'ほかの操作と同時に在庫を更新しようとしたため、調整できませんでした（記録していません）。もう一度実行してください',
            ]);
        }
    }
}
