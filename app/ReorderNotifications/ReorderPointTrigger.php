<?php

namespace App\ReorderNotifications;

use App\Products\ProductService;
use App\SalesOrders\SalesOrderService;

/**
 * 発注点割れの通知のきっかけになった操作（棚卸調整・受注の出荷。発注の入荷は在庫が増えるだけなので対象外）。
 * キューの Job に入れて保存するので、値はスカラーだけ
 */
final readonly class ReorderPointTrigger
{
    public const string STOCK_ADJUST = 'stock_adjust';

    public const string SALES_ORDER_SHIPPED = 'sales_order_shipped';

    /**
     * @param  self::STOCK_ADJUST|self::SALES_ORDER_SHIPPED  $kind
     * @param  int  $targetId  棚卸調整は商品 ID、出荷は受注 ID（失敗の操作履歴の対象）
     * @param  string|null  $number  受注番号（出荷のときだけ。システムが採番した値）
     */
    private function __construct(
        public string $kind,
        public int $targetId,
        public ?string $number,
    ) {}

    public static function stockAdjust(int $productId): self
    {
        return new self(self::STOCK_ADJUST, $productId, null);
    }

    public static function salesOrderShipped(int $salesOrderId, string $number): self
    {
        return new self(self::SALES_ORDER_SHIPPED, $salesOrderId, $number);
    }

    /** 表示（例「棚卸調整」「受注 SO-20261019-001 の出荷」） */
    public function label(): string
    {
        return $this->kind === self::STOCK_ADJUST ? '棚卸調整' : "受注 {$this->number} の出荷";
    }

    /** 失敗の操作履歴の target_type */
    public function targetType(): string
    {
        return $this->kind === self::STOCK_ADJUST ? ProductService::TARGET_TYPE : SalesOrderService::TARGET_TYPE;
    }
}
