<?php

namespace App\Stock;

use LogicException;

/**
 * 入出庫をまとめて記録したときの結果を求める（DB には触れない純粋な計算。docs/spec.md §3・§4・§6-3）。
 *
 * - 同じ商品の入出庫が複数あれば合計して判定する（明細が 2 行に分かれていても、合計で足りるかを見る）
 * - 記録後に在庫が負になる商品が 1 つでもあれば shortages に入れる（呼び出し側は全体を記録しない）
 * - 「発注点以上 → 発注点未満」に変わった有効な商品を fellBelowReorderPoint に入れる（無効の商品は通知しない。spec §6-3）
 * - 結果は商品 ID の順（入力の順番によらず一定）
 */
final readonly class StockPlan
{
    /**
     * @param  list<StockChange>  $changes
     * @param  list<StockShortage>  $shortages
     * @param  list<StockChange>  $fellBelowReorderPoint
     */
    private function __construct(
        public array $changes,
        public array $shortages,
        public array $fellBelowReorderPoint,
    ) {}

    /**
     * @param  array<int, StockProduct>  $products  対象の全商品（商品 ID → 行ロック後の状態）
     * @param  list<StockEntry>  $entries
     */
    public static function make(array $products, array $entries): self
    {
        $net = [];
        foreach ($entries as $entry) {
            if (! isset($products[$entry->productId])) {
                throw new LogicException("商品 {$entry->productId} の在庫を読んでいません");
            }
            $net[$entry->productId] = ($net[$entry->productId] ?? 0) + $entry->quantity;
        }
        ksort($net);

        $changes = [];
        $shortages = [];
        $fell = [];
        foreach ($net as $productId => $delta) {
            $product = $products[$productId];
            $before = $product->stock;
            $after = $before + $delta;
            if ($after < 0) {
                $shortages[] = new StockShortage($product, $before, -$delta, -$after);

                continue;
            }
            $change = new StockChange($product, $before, $after);
            $changes[] = $change;
            if (! $product->isBelowReorderPoint($before) && $product->isBelowReorderPoint($after)) {
                $fell[] = $change;
            }
        }

        return new self($changes, $shortages, $fell);
    }
}
