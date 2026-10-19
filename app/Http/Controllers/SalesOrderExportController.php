<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\SalesOrders\SalesOrderCsvExporter;
use App\SalesOrders\SalesOrderListParams;
use App\SalesOrders\SalesOrderQuery;
use App\Support\Csv\CsvWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 受注の CSV 出力（GET /sales-orders/export?q=&customer=&status=&from=&to=&sort=&order=。docs/spec.md §7）。
 * 商品の CSV 出力（ProductExportController）と同じ作り:
 *
 * - 一覧画面と同じ検索条件（URL のクエリ）を反映する。ページ送りはせず、条件に合う全件を出力する
 * - 上限（SalesOrderQuery::EXPORT_MAX_ROWS）を超える場合は出力せず 413 と日本語のメッセージを返す（黙って切り捨てない）
 * - UTF-8（BOM 付き）・見出し行あり。数式として解釈される文字で始まるセルは無害化する（App\Support\Csv\CsvFormula）
 * - 読み取りだけで副作用はない
 */
class SalesOrderExportController extends Controller
{
    public function __invoke(Request $request, SalesOrderQuery $orders): Response
    {
        Gate::authorize('export', SalesOrder::class);

        $items = $orders->forExport(SalesOrderListParams::fromQuery($request->query()));
        if ($items === null) {
            return response(
                '件数が多すぎます。絞り込んでから出力してください（上限 '.number_format(SalesOrderQuery::EXPORT_MAX_ROWS).' 件）',
                413,
                ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'],
            );
        }

        $fileName = 'sales_orders_'.now()->format('Ymd').'.csv';

        return response(CsvWriter::BOM.SalesOrderCsvExporter::build($items), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-store',
        ]);
    }
}
