<?php

namespace App\Stock;

/** 在庫が足りない商品（記録を中止して画面に出す） */
final readonly class StockShortage
{
    public function __construct(
        public StockProduct $product,
        /** 現在庫 */
        public int $available,
        /** 必要な数（この記録で減らす数） */
        public int $required,
        /** 不足する数 */
        public int $shortage,
    ) {}
}
