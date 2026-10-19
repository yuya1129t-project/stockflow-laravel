<?php

namespace App\Stock;

/** 商品ごとの記録の前後の在庫 */
final readonly class StockChange
{
    public function __construct(
        public StockProduct $product,
        public int $before,
        public int $after,
    ) {}
}
