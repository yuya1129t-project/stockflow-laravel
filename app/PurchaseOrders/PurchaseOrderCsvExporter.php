<?php

namespace App\PurchaseOrders;

use App\Support\Csv\CsvWriter;

/**
 * 発注の CSV 出力の中身（docs/spec.md §7。1 行 = 発注 1 件。列は Next.js 版と同じ）。
 * 文字列のセルは CsvWriter で無害化する（数式として解釈される先頭文字に ' を付ける）。BOM はコントローラーで付ける
 *
 * @phpstan-import-type PurchaseOrderListItem from PurchaseOrderQuery
 */
final class PurchaseOrderCsvExporter
{
    public const array HEADERS = ['発注番号', '発注日', '入荷予定日', '仕入先コード', '仕入先名', 'ステータス', '明細数', '小計', '消費税', '合計', '備考'];

    /** @param  list<PurchaseOrderListItem>  $orders */
    public static function build(array $orders): string
    {
        $rows = [self::HEADERS];
        foreach ($orders as $o) {
            $rows[] = [
                $o['number'],
                $o['order_date'],
                $o['expected_date'] ?? '',
                $o['supplier']['code'],
                $o['supplier']['name'],
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
