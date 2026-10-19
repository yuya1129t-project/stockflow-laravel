<?php

namespace App\Api\V1;

use App\Models\SalesOrder;
use App\Products\ProductQuery;
use App\SalesOrders\SalesOrderQuery;
use Carbon\CarbonInterface;

/**
 * DB から読んだ値を REST API（/api/v1）の応答の形（docs/openapi.json の components.schemas）にする。
 * Next.js 版 src/lib/api/v1/serialize.ts と同じ形。
 *
 * 内部の ID（商品・取引先・受注の id）は出さず、商品コード・取引先コード・受注番号で表す（3 実装で同じ応答にするため）。
 * 金額・数量は整数、日付は YYYY-MM-DD、日時は ISO 8601（UTC。ミリ秒まで。Next.js 版の toISOString と同じ形）
 *
 * @phpstan-import-type ProductItem from ProductQuery
 * @phpstan-import-type SalesOrderListItem from SalesOrderQuery
 * @phpstan-import-type SalesOrderDetail from SalesOrderQuery
 */
final class ApiSerializer
{
    /**
     * Product（商品。現在庫を含む）
     *
     * @param  ProductItem  $item
     * @return array<string, mixed>
     */
    public static function product(array $item): array
    {
        return [
            'code' => $item['code'],
            'name' => $item['name'],
            'category' => $item['category'],
            'unit' => $item['unit'],
            'salesPrice' => $item['sales_price'],
            'purchasePrice' => $item['purchase_price'],
            'reorderPoint' => $item['reorder_point'],
            'isActive' => $item['is_active'],
            'stock' => $item['stock'],
            'belowReorderPoint' => $item['is_low_stock'],
        ];
    }

    /**
     * ProductStock（商品の現在庫と発注点）
     *
     * @param  ProductItem  $item
     * @return array<string, mixed>
     */
    public static function productStock(array $item): array
    {
        return [
            'code' => $item['code'],
            'name' => $item['name'],
            'unit' => $item['unit'],
            'isActive' => $item['is_active'],
            'stock' => $item['stock'],
            'reorderPoint' => $item['reorder_point'],
            'belowReorderPoint' => $item['is_low_stock'],
        ];
    }

    /**
     * SalesOrderSummary（受注一覧の 1 件。明細は含まない）
     *
     * @param  SalesOrderListItem  $item
     * @return array<string, mixed>
     */
    public static function salesOrderSummary(array $item): array
    {
        return [
            'number' => $item['number'],
            'orderDate' => $item['order_date'],
            'dueDate' => $item['due_date'],
            'status' => $item['status'],
            'customer' => ['code' => $item['customer']['code'], 'name' => $item['customer']['name']],
            'subtotal' => $item['subtotal'],
            'tax' => $item['tax'],
            'total' => $item['total'],
            'note' => $item['note'],
            'lineCount' => $item['line_count'],
        ];
    }

    /**
     * SalesOrder（受注。明細を含む）。detail は SalesOrderQuery::detail の結果、order は作成・更新日時を読む受注
     *
     * @param  SalesOrderDetail  $detail
     * @return array<string, mixed>
     */
    public static function salesOrder(SalesOrder $order, array $detail): array
    {
        return [
            'number' => $detail['number'],
            'orderDate' => $detail['order_date'],
            'dueDate' => $detail['due_date'],
            'status' => $detail['status'],
            'customer' => ['code' => $detail['customer']['code'], 'name' => $detail['customer']['name']],
            'note' => $detail['note'],
            'subtotal' => $detail['subtotal'],
            'tax' => $detail['tax'],
            'total' => $detail['total'],
            'lines' => array_map(fn (array $line) => [
                'product' => ['code' => $line['product_code'], 'name' => $line['product_name'], 'unit' => $line['unit']],
                'quantity' => $line['quantity'],
                'unitPrice' => $line['unit_price'],
                'amount' => $line['amount'],
            ], $detail['lines']),
            'createdAt' => self::dateTime($order->created_at),
            'updatedAt' => self::dateTime($order->updated_at),
        ];
    }

    /** ISO 8601（UTC。例 2026-10-19T01:02:03.456Z）。受注の作成・更新日時は必ず入っている（null は null のまま。契約テストで検出する） */
    public static function dateTime(?CarbonInterface $value): ?string
    {
        return $value?->toImmutable()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
