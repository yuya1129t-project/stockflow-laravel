<?php

namespace App\Stock;

use RuntimeException;

/**
 * 在庫不足で入出庫を記録できなかった（docs/spec.md §4「1 行でも不足なら全体を中止し、不足する商品と数量を表示」）。
 * StockTransaction::run の中で投げるので、同じトランザクションのほかの書き込み（受注のステータス更新など）も取り消される
 */
final class InsufficientStockException extends RuntimeException
{
    /** @param  list<StockShortage>  $shortages  不足する商品（商品 ID の順） */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('在庫が足りません: '.implode('、', array_map(
            fn (StockShortage $s) => sprintf(
                '%s「%s」（必要 %s%s・在庫 %s%s・不足 %s%s）',
                $s->product->code,
                $s->product->name,
                number_format($s->required),
                $s->product->unit,
                number_format($s->available),
                $s->product->unit,
                number_format($s->shortage),
                $s->product->unit,
            ),
            $shortages,
        )));
    }
}
