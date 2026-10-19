<?php

namespace App\SalesOrders;

use App\Support\Csv\CsvWriter;

/**
 * 受注の CSV 出力の中身（docs/spec.md §7。1 行 = 受注 1 件。列は Next.js 版と同じ）。発注（App\PurchaseOrders\PurchaseOrderCsvExporter）と同じ作り。
 * 文字列のセルは CsvWriter で無害化する（数式として解釈される先頭文字に ' を付ける）。BOM はコントローラーで付ける
 *
 * @phpstan-import-type SalesOrderListItem from SalesOrderQuery
 */
final class SalesOrderCsvExporter
{
    public const array HEADERS = ['受注番号', '受注日', '納期', '顧客コード', '顧客名', 'ステータス', '明細数', '小計', '消費税', '合計', '備考'];

    /** @param  list<SalesOrderListItem>  $orders */
    public static function build(array $orders): string
    {
        $rows = [self::HEADERS];
        foreach ($orders as $o) {
            $rows[] = [
                $o['number'],
                $o['order_date'],
                $o['due_date'] ?? '',
                $o['customer']['code'],
                $o['customer']['name'],
                $o['status_label'],
                $o['line_count'],
                $o['subtotal'],
                $o['tax'],
                $o['total'],
                $o['note'] ?? '',
            ];
        }

        return CsvWriter::write($rows);
    }
}
