<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\PurchaseOrders\PurchaseOrderCsvExporter;
use App\PurchaseOrders\PurchaseOrderListParams;
use App\PurchaseOrders\PurchaseOrderQuery;
use App\Support\Csv\CsvWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * 発注の CSV 出力（GET /purchase-orders/export?q=&supplier=&status=&from=&to=&sort=&order=。docs/spec.md §7）。
 * 商品の CSV 出力（ProductExportController）と同じ作り:
 *
 * - 一覧画面と同じ検索条件（URL のクエリ）を反映する。ページ送りはせず、条件に合う全件を出力する
 * - 上限（PurchaseOrderQuery::EXPORT_MAX_ROWS）を超える場合は出力せず 413 と日本語のメッセージを返す（黙って切り捨てない）
 * - UTF-8（BOM 付き）・見出し行あり。数式として解釈される文字で始まるセルは無害化する（App\Support\Csv\CsvFormula）
 * - 読み取りだけで副作用はない
 */
class PurchaseOrderExportController extends Controller
{
    public function __invoke(Request $request, PurchaseOrderQuery $orders): Response
    {
        Gate::authorize('export', PurchaseOrder::class);

        $items = $orders->forExport(PurchaseOrderListParams::fromQuery($request->query()));
        if ($items === null) {
            return response(
                '件数が多すぎます。絞り込んでから出力してください（上限 '.number_format(PurchaseOrderQuery::EXPORT_MAX_ROWS).' 件）',
                413,
                ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'],
            );
        }

        $fileName = 'purchase_orders_'.now()->format('Ymd').'.csv';

        return response(CsvWriter::BOM.PurchaseOrderCsvExporter::build($items), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'no-store',
        ]);
    }
}
