<?php

use App\Dashboard\DashboardQuery;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * ダッシュボード（docs/spec.md §5-2）。集計は App\Dashboard\DashboardQuery。
 *
 * 各テストは自分で作ったデータだけを使う（RefreshDatabase でテストごとに巻き戻すので、テストどうしは独立。
 * 共通のデータは beforeEach で毎回作る）。基準日時は JST 2000-03-01 00:30（UTC ではまだ 2000-02-29 15:30）
 */
function dashboardNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2000-03-01T00:30:00+09:00');
}

function dashboardSalesOrder(SalesOrderStatus $status, string $orderDate, int $subtotal = 1000, ?string $dueDate = null): SalesOrder
{
    // 税 10%（受注額は税込の total で集計するので、subtotal と total が違う値になるようにする）
    $tax = intdiv($subtotal, 10);

    return SalesOrder::factory()->create([
        'customer_id' => test()->customer->id,
        'status' => $status,
        'order_date' => $orderDate,
        'due_date' => $dueDate,
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $subtotal + $tax,
    ]);
}

function dashboardPurchaseOrder(PurchaseOrderStatus $status, ?string $expectedDate): PurchaseOrder
{
    return PurchaseOrder::factory()->create([
        'supplier_id' => test()->supplier->id,
        'status' => $status,
        'order_date' => '2000-02-01',
        'expected_date' => $expectedDate,
        'subtotal' => 1000,
        'tax' => 100,
        'total' => 1100,
    ]);
}

function dashboardProduct(int $stock, int $reorderPoint, bool $isActive = true): Product
{
    $product = Product::factory()->create(['reorder_point' => $reorderPoint, 'is_active' => $isActive]);
    if ($stock !== 0) {
        StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::Adjust, 'quantity' => $stock]);
    }

    return $product;
}

/** @return array<string, mixed> */
function dashboardData(?DateTimeInterface $now = null, int $limit = DashboardQuery::LIST_LIMIT): array
{
    return app(DashboardQuery::class)->get($now ?? dashboardNow(), $limit);
}

beforeEach(function () {
    $this->customer = Partner::factory()->customer()->create();
    $this->supplier = Partner::factory()->supplier()->create();
});

describe('画面', function () {
    test('未ログインはログイン画面へ', function () {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    });

    test('ログインすると集計を渡したダッシュボードを開ける（基準は現在の日時）', function () {
        $this->travelTo(dashboardNow());
        dashboardSalesOrder(SalesOrderStatus::Shipped, '2000-03-01', 2000);
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-10', 1000, '2000-02-20');
        dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, '2000-02-29');
        dashboardProduct(1, 5);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('today', '2000-03-01')
                ->where('sales.current.key', '2000-03')
                ->where('sales.current.total', 2200)
                ->where('sales.previous.total', 1100)
                ->where('sales.change.diff', 1100)
                ->where('sales.change.rate', 100)
                ->has('sales.trend', 6)
                ->where('unshipped.count', 1)
                ->where('unshipped.overdue_count', 1)
                ->has('unshipped.overdue', 1)
                ->where('low_stock.count', 1)
                ->where('low_stock.items.0.shortage', 4)
                ->where('awaiting_receipt.count', 1)
                ->where('awaiting_receipt.overdue_count', 1)
                ->where('awaiting_receipt.items.0.overdue', true));
    });

    test('PHP・アプリの既定のタイムゾーンが UTC でも、今日・今月は JST で決める', function () {
        $original = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        try {
            // UTC 2000-02-29 15:30 = JST 2000-03-01 00:30
            $this->travelTo(CarbonImmutable::parse('2000-02-29 15:30:00', 'UTC'));
            dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-03-01', 1000);

            $this->actingAs(User::factory()->create())
                ->get(route('dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('today', '2000-03-01')
                    ->where('sales.current.key', '2000-03')
                    ->where('sales.current.total', 1100));
        } finally {
            date_default_timezone_set($original);
        }
    });

    test('集計の問い合わせの回数はデータの量によらない（N+1 にしない）', function () {
        $this->travelTo(dashboardNow());
        $this->actingAs(User::factory()->create());
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('dashboard'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100, '2000-02-10');
        dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, '2000-02-10');
        dashboardProduct(1, 5);
        $few = $countQueries();

        foreach (range(1, 8) as $i) {
            // 顧客・仕入先も別々にする（取引先の名前を件ごとに引くと増える）
            $this->customer = Partner::factory()->customer()->create();
            $this->supplier = Partner::factory()->supplier()->create();
            dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100, sprintf('2000-02-%02d', 10 + $i));
            dashboardSalesOrder(SalesOrderStatus::Shipped, sprintf('2000-01-%02d', $i), 100);
            dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, sprintf('2000-02-%02d', 10 + $i));
            dashboardProduct($i, 20);
        }
        $many = $countQueries();

        expect($many)->toBe($few);
    });
});

describe('今月の受注額・前月比・月別', function () {
    // 金額は税抜（subtotal）。集計は税込（total = subtotal + 10%）
    beforeEach(function () {
        // 今月（2000-03）: 月初と月末
        dashboardSalesOrder(SalesOrderStatus::Shipped, '2000-03-01', 2000);
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-03-31', 400);
        // 今月の下書き・取消は含めない
        dashboardSalesOrder(SalesOrderStatus::Draft, '2000-03-15', 50_000);
        dashboardSalesOrder(SalesOrderStatus::Cancelled, '2000-03-15', 70_000);
        // 前月（2000-02）の月末（うるう日）
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-29', 1000);
        dashboardSalesOrder(SalesOrderStatus::Cancelled, '2000-02-10', 90_000);
        // 翌月の 1 日は含めない
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-04-01', 8000);
        // 6 か月の最初の月（1999-10）の月初と、その前日（範囲外）
        dashboardSalesOrder(SalesOrderStatus::Shipped, '1999-10-01', 300);
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '1999-09-30', 900);
    });

    test('JST の暦月・受注日基準で confirmed と shipped の税込 total を合計する', function () {
        $data = dashboardData();

        expect($data['today'])->toBe('2000-03-01')
            // 2200 + 440（税抜の合計 2400 ではない）
            ->and($data['sales']['current'])->toMatchArray(['key' => '2000-03', 'start' => '2000-03-01', 'end' => '2000-04-01', 'total' => 2640])
            ->and($data['sales']['previous'])->toMatchArray(['key' => '2000-02', 'total' => 1100])
            ->and($data['sales']['change'])->toBe(['diff' => 1540, 'rate' => 140.0])
            ->and(array_map(fn (array $m) => [$m['key'], $m['total']], $data['sales']['trend']))->toBe([
                ['1999-10', 330],
                ['1999-11', 0],
                ['1999-12', 0],
                ['2000-01', 0],
                ['2000-02', 1100],
                ['2000-03', 2640],
            ]);
    });

    test('JST と UTC の境界: UTC で 3/1 になる前の JST 2/29 23:59 は、今月が 2 月', function () {
        // UTC 2000-02-29 14:59:59 = JST 2000-02-29 23:59:59
        $data = dashboardData(CarbonImmutable::parse('2000-02-29T14:59:59Z'));

        expect($data['today'])->toBe('2000-02-29')
            ->and($data['sales']['current'])->toMatchArray(['key' => '2000-02', 'total' => 1100])
            ->and($data['sales']['previous'])->toMatchArray(['key' => '2000-01', 'total' => 0])
            ->and($data['sales']['change'])->toBe(['diff' => 1100, 'rate' => null]);
    });

    test('年またぎ: 今月が 1 月なら前月は前年の 12 月', function () {
        dashboardSalesOrder(SalesOrderStatus::Shipped, '1999-12-31', 500);
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-01-01', 1000);

        $data = dashboardData(CarbonImmutable::parse('2000-01-10T12:00:00+09:00'));

        expect($data['sales']['current'])->toMatchArray(['key' => '2000-01', 'total' => 1100])
            ->and($data['sales']['previous'])->toMatchArray(['key' => '1999-12', 'total' => 550])
            ->and($data['sales']['change'])->toBe(['diff' => 550, 'rate' => 100.0])
            ->and(array_column($data['sales']['trend'], 'key'))->toBe(['1999-08', '1999-09', '1999-10', '1999-11', '1999-12', '2000-01']);
    });

    test('前月が 0 円なら増減率は null', function () {
        // 1999-12 を今月とすると、今月・前月（1999-11）とも受注なし
        $data = dashboardData(CarbonImmutable::parse('1999-12-15T12:00:00+09:00'));

        expect($data['sales']['current']['total'])->toBe(0)
            ->and($data['sales']['previous']['total'])->toBe(0)
            ->and($data['sales']['change'])->toBe(['diff' => 0, 'rate' => null]);
    });

    test('今月が 0 円・前月に受注があれば -100%', function () {
        // 1999-11 を今月とすると、前月（1999-10）は 330
        $data = dashboardData(CarbonImmutable::parse('1999-11-01T00:00:00+09:00'));

        expect($data['sales']['current'])->toMatchArray(['key' => '1999-11', 'total' => 0])
            ->and($data['sales']['change'])->toBe(['diff' => -330, 'rate' => -100.0]);
    });

    test('DB のセッションのタイムゾーンを変えても同じ値になる（date 列と日付の文字列で比べる）', function () {
        $configured = config('database.connections.mysql.timezone');
        try {
            foreach (['+00:00', '-10:00', '+14:00'] as $tz) {
                DB::statement('SET time_zone = ?', [$tz]);
                $data = dashboardData();

                expect($data['sales']['current']['total'])->toBe(2640)
                    ->and($data['sales']['previous']['total'])->toBe(1100)
                    ->and($data['sales']['trend'][0])->toMatchArray(['key' => '1999-10', 'total' => 330]);
            }
        } finally {
            DB::statement('SET time_zone = ?', [$configured]);
        }
    });
});

describe('未出荷の受注', function () {
    test('confirmed の件数。納期切れは JST の今日より前の納期（今日・納期なし・他のステータスは含めない）', function () {
        $old = dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100, '2000-02-20');
        $leapDay = dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100, '2000-02-29');
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100, '2000-03-01'); // 今日が納期
        dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-02-01', 100); // 納期なし
        dashboardSalesOrder(SalesOrderStatus::Draft, '2000-02-01', 100, '2000-02-01');
        dashboardSalesOrder(SalesOrderStatus::Shipped, '2000-02-01', 100, '2000-02-01');
        dashboardSalesOrder(SalesOrderStatus::Cancelled, '2000-02-01', 100, '2000-02-01');

        $unshipped = dashboardData()['unshipped'];

        expect($unshipped['count'])->toBe(4)
            ->and($unshipped['overdue_count'])->toBe(2)
            ->and($unshipped['overdue'])->toBe([
                ['id' => $old->id, 'number' => $old->number, 'partner_name' => $this->customer->name, 'date' => '2000-02-20', 'overdue' => true],
                ['id' => $leapDay->id, 'number' => $leapDay->number, 'partner_name' => $this->customer->name, 'date' => '2000-02-29', 'overdue' => true],
            ]);
    });

    test('納期切れの一覧は既定で 5 件（納期の古い順）。件数は全件', function () {
        foreach (['2000-02-07', '2000-02-01', '2000-02-05', '2000-02-03', '2000-02-02', '2000-02-06', '2000-02-04'] as $due) {
            dashboardSalesOrder(SalesOrderStatus::Confirmed, '2000-01-15', 100, $due);
        }

        $unshipped = dashboardData()['unshipped'];

        expect($unshipped['overdue_count'])->toBe(7)
            ->and(array_column($unshipped['overdue'], 'date'))->toBe(['2000-02-01', '2000-02-02', '2000-02-03', '2000-02-04', '2000-02-05']);
    });

    test('未出荷がなければ 0 件', function () {
        expect(dashboardData()['unshipped'])->toBe(['count' => 0, 'overdue_count' => 0, 'overdue' => []]);
    });
});

describe('入荷待ちの発注', function () {
    test('ordered の件数。入荷予定日の近い順（予定日なしは最後）で、今日より前の予定日に印を付ける', function () {
        $noDate = dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, null);
        $today = dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, '2000-03-01');
        $overdue = dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, '2000-02-29');
        $later = dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, '2000-03-10');
        dashboardPurchaseOrder(PurchaseOrderStatus::Draft, '2000-02-01');
        dashboardPurchaseOrder(PurchaseOrderStatus::Received, '2000-02-01');
        dashboardPurchaseOrder(PurchaseOrderStatus::Cancelled, '2000-02-01');

        $awaiting = dashboardData()['awaiting_receipt'];

        expect($awaiting['count'])->toBe(4)
            ->and($awaiting['overdue_count'])->toBe(1)
            ->and(array_map(fn (array $o) => [$o['id'], $o['date'], $o['overdue']], $awaiting['items']))->toBe([
                [$overdue->id, '2000-02-29', true],
                [$today->id, '2000-03-01', false],
                [$later->id, '2000-03-10', false],
                [$noDate->id, null, false],
            ])
            ->and($awaiting['items'][0])->toMatchArray(['number' => $overdue->number, 'partner_name' => $this->supplier->name]);
    });

    test('一覧の件数を超える分は件数にだけ入る', function () {
        foreach (range(1, 7) as $day) {
            dashboardPurchaseOrder(PurchaseOrderStatus::Ordered, sprintf('2000-03-%02d', $day));
        }

        $awaiting = dashboardData(limit: 3)['awaiting_receipt'];

        expect($awaiting['count'])->toBe(7)
            ->and($awaiting['overdue_count'])->toBe(0)
            ->and(array_column($awaiting['items'], 'date'))->toBe(['2000-03-01', '2000-03-02', '2000-03-03']);
    });
});

describe('発注点を下回る商品', function () {
    test('有効な商品で 現在庫 < 発注点。不足の大きい順で、件数は現在庫一覧の「発注点割れのみ」と一致する', function () {
        $large = dashboardProduct(1, 1_000_000);
        $small = dashboardProduct(2, 10);
        $noMovement = dashboardProduct(0, 3);
        dashboardProduct(10, 10); // 発注点と同じ
        dashboardProduct(0, 0); // 発注点 0
        dashboardProduct(0, 2_000_000, isActive: false); // 無効な商品は含めない

        $lowStock = dashboardData()['low_stock'];

        expect($lowStock['count'])->toBe(3)
            ->and(array_map(fn (array $p) => [$p['id'], $p['stock'], $p['reorder_point'], $p['shortage']], $lowStock['items']))->toBe([
                [$large->id, 1, 1_000_000, 999_999],
                [$small->id, 2, 10, 8],
                [$noMovement->id, 0, 3, 3],
            ])
            ->and($lowStock['items'][0])->toMatchArray(['code' => $large->code, 'name' => $large->name, 'unit' => $large->unit]);

        $this->actingAs(User::factory()->create())
            ->get(route('stock.index', ['level' => 'low']))
            ->assertInertia(fn (Assert $page) => $page->where('pagination.total', $lowStock['count']));
    });

    test('不足が同じなら商品コードの順。一覧の件数を超える分は件数にだけ入る', function () {
        $b = Product::factory()->create(['code' => 'P-9002', 'reorder_point' => 5]);
        $a = Product::factory()->create(['code' => 'P-9001', 'reorder_point' => 5]);
        Product::factory()->create(['code' => 'P-9003', 'reorder_point' => 1]);

        $lowStock = dashboardData(limit: 2)['low_stock'];

        expect($lowStock['count'])->toBe(3)
            ->and(array_column($lowStock['items'], 'id'))->toBe([$a->id, $b->id]);
    });
});
