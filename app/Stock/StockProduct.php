<?php

namespace App\Stock;

/**
 * 行ロックを取った時点の商品の状態と現在庫（StockTransaction::lockProducts の結果）。
 * 在庫を記録するトランザクションで商品の有効などを確かめるときは、この値を使う（別のロックを取り直さない）
 */
final readonly class StockProduct
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $unit,
        public int $reorderPoint,
        public bool $isActive,
        /** 現在庫（入出庫の合計） */
        public int $stock,
    ) {}

    /** 発注点割れ（有効な商品で 在庫 < 発注点。商品一覧の強調表示と同じ定義） */
    public function isBelowReorderPoint(int $stock): bool
    {
        return $this->isActive && $stock < $this->reorderPoint;
    }
}
