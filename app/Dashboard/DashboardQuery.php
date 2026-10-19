<?php

namespace App\Dashboard;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Products\ProductQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * ダッシュボードの集計（docs/spec.md §5-2。Next.js 版の src/lib/dashboard/queries.ts と同じ集計）。
 *
 * 集計はすべて DB 側で行い、件ごとに問い合わせない（N+1 にしない。データの量によらず 7 本）
 * 1. 直近 6 か月の月別受注額（confirmed＋shipped の税込 total、受注日基準）。今月・前月の受注額もここから取る
 * 2. 未出荷の受注（confirmed）の件数・納期切れの件数と、納期切れの上位（納期の古い順）
 * 3. 発注点割れの商品（有効な商品のみ。ProductQuery::lowStock＝商品一覧・現在庫一覧と同じ定義）の件数と、不足の大きい順の上位
 * 4. 入荷待ちの発注（ordered）の件数・予定日超過の件数と、入荷予定日の近い順の上位（予定日なしは最後）
 *
 * 月・日の境界は Asia/Tokyo（DashboardPeriod）。date 列とは YYYY-MM-DD の文字列で比べる（DB の time_zone に依存しない）。
 * 納期・予定日が今日のものは超過に入れない（今日より前だけ）
 *
 * @phpstan-import-type MonthRange from DashboardPeriod
 * @phpstan-import-type MonthOverMonth from DashboardPeriod
 *
 * @phpstan-type MonthlySales array{year: int, month: int, key: string, start: string, end: string, total: int}
 * @phpstan-type DashboardOrderItem array{id: int, number: string, partner_name: string, date: string|null, overdue: bool}
 * @phpstan-type DashboardData array{
 *     today: string,
 *     sales: array{current: MonthlySales, previous: MonthlySales, change: MonthOverMonth, trend: list<MonthlySales>},
 *     unshipped: array{count: int, overdue_count: int, overdue: list<DashboardOrderItem>},
 *     low_stock: array{count: int, items: list<array{id: int, code: string, name: string, unit: string, reorder_point: int, stock: int, shortage: int}>},
 *     awaiting_receipt: array{count: int, overdue_count: int, items: list<DashboardOrderItem>},
 * }
 */
final class DashboardQuery
{
    /** 月別受注額のグラフに出す月数（今月を含む） */
    public const int SALES_TREND_MONTHS = 6;

    /** 各カードに一覧で出す件数の既定値 */
    public const int LIST_LIMIT = 5;

    public function __construct(private readonly ProductQuery $products) {}

    /**
     * ダッシュボードの集計をまとめて取る
     *
     * @param  DateTimeInterface|null  $now  基準の日時（既定は現在）
     * @return DashboardData
     */
    public function get(?DateTimeInterface $now = null, int $limit = self::LIST_LIMIT): array
    {
        $now ??= CarbonImmutable::now();
        $today = DashboardPeriod::jstDate($now);
        $trend = $this->monthlySales(DashboardPeriod::recentMonths($now, self::SALES_TREND_MONTHS));
        $current = $trend[count($trend) - 1];
        $previous = $trend[count($trend) - 2];

        return [
            'today' => $today,
            'sales' => [
                'current' => $current,
                'previous' => $previous,
                'change' => DashboardPeriod::monthOverMonth($current['total'], $previous['total']),
                'trend' => $trend,
            ],
            'unshipped' => $this->unshippedOrders($today, $limit),
            'low_stock' => $this->products->lowStock($limit),
            'awaiting_receipt' => $this->awaitingPurchaseOrders($today, $limit),
        ];
    }

    /**
     * 月別の受注額（1 本の GROUP BY）。受注のない月は 0
     *
     * @param  list<MonthRange>  $months
     * @return list<MonthlySales>
     */
    private function monthlySales(array $months): array
    {
        $totals = DB::table('sales_orders')
            ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::Shipped->value])
            ->where('order_date', '>=', $months[0]['start'])
            ->where('order_date', '<', $months[count($months) - 1]['end'])
            ->groupByRaw("DATE_FORMAT(order_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(order_date, '%Y-%m') AS month")
            ->selectRaw('CAST(SUM(total) AS SIGNED) AS total')
            ->pluck('total', 'month');

        return array_map(fn (array $m): array => [...$m, 'total' => (int) ($totals[$m['key']] ?? 0)], $months);
    }

    /** @return array{count: int, overdue_count: int, overdue: list<DashboardOrderItem>} */
    private function unshippedOrders(string $today, int $limit): array
    {
        $counts = DB::table('sales_orders')
            ->where('status', SalesOrderStatus::Confirmed->value)
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('CAST(COALESCE(SUM(due_date < ?), 0) AS SIGNED) AS overdue_count', [$today])
            ->first();

        $rows = DB::table('sales_orders AS o')
            ->join('partners AS c', 'c.id', '=', 'o.customer_id')
            ->where('o.status', SalesOrderStatus::Confirmed->value)
            ->where('o.due_date', '<', $today)
            ->orderBy('o.due_date')
            ->orderBy('o.number')
            ->limit($limit)
            ->get(['o.id', 'o.number', 'c.name AS partner_name', 'o.due_date AS date']);

        return [
            'count' => (int) ($counts->count ?? 0),
            'overdue_count' => (int) ($counts->overdue_count ?? 0),
            'overdue' => self::toOrderItems($rows, $today),
        ];
    }

    /** @return array{count: int, overdue_count: int, items: list<DashboardOrderItem>} */
    private function awaitingPurchaseOrders(string $today, int $limit): array
    {
        $counts = DB::table('purchase_orders')
            ->where('status', PurchaseOrderStatus::Ordered->value)
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('CAST(COALESCE(SUM(expected_date < ?), 0) AS SIGNED) AS overdue_count', [$today])
            ->first();

        $rows = DB::table('purchase_orders AS o')
            ->join('partners AS s', 's.id', '=', 'o.supplier_id')
            ->where('o.status', PurchaseOrderStatus::Ordered->value)
            // 入荷予定日の近い順。予定日なしは最後
            ->orderByRaw('o.expected_date IS NULL')
            ->orderBy('o.expected_date')
            ->orderBy('o.number')
            ->limit($limit)
            ->get(['o.id', 'o.number', 's.name AS partner_name', 'o.expected_date AS date']);

        return [
            'count' => (int) ($counts->count ?? 0),
            'overdue_count' => (int) ($counts->overdue_count ?? 0),
            'items' => self::toOrderItems($rows, $today),
        ];
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<DashboardOrderItem>
     */
    private static function toOrderItems(iterable $rows, string $today): array
    {
        $items = [];
        foreach ($rows as $row) {
            /** @var object{id: int|string, number: string, partner_name: string, date: string|null} $row */
            $date = $row->date === null ? null : (string) $row->date;
            $items[] = [
                'id' => (int) $row->id,
                'number' => $row->number,
                'partner_name' => $row->partner_name,
                'date' => $date,
                // 今日（JST）より前の日付なら超過。今日・日付なしは超過にしない
                'overdue' => $date !== null && $date < $today,
            ];
        }

        return $items;
    }
}
