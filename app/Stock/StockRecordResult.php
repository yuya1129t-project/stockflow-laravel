<?php

namespace App\Stock;

/** StockTransaction::record の結果 */
final readonly class StockRecordResult
{
    /**
     * @param  list<StockChange>  $changes  商品ごとの記録の前後の在庫（商品 ID の順）
     * @param  list<StockChange>  $fellBelowReorderPoint  「発注点以上 → 発注点未満」に変わった有効な商品（#11 の通知で使う。
     *                                                    通知はトランザクションの確定後に呼び出し側で送る）
     */
    public function __construct(
        public array $changes,
        public array $fellBelowReorderPoint,
    ) {}
}
