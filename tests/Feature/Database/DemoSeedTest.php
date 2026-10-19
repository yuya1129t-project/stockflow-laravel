<?php

use App\Enums\PartnerKind;
use App\Enums\Role;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * デモ用シード（database/seeders/DatabaseSeeder.php）を MySQL に入れたあとの不変条件。docs/spec.md §9
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Tokyo'));
    $this->seed();
});

/** 伝票と明細・入出庫の比較用（[商品 ID, 数量] の並び） */
function seedLinePairs(iterable $rows, int $sign = 1): array
{
    $pairs = [];
    foreach ($rows as $row) {
        $pairs[] = [(int) $row->product_id, $sign * (int) $row->quantity];
    }
    sort($pairs);

    return $pairs;
}

test('件数が仕様どおり（商品 50・取引先 20（顧客 12・仕入先 8）・受注 80・発注 40・デモユーザー 3）', function () {
    expect(Product::count())->toBe(50)
        ->and(Partner::count())->toBe(20)
        ->and(Partner::where('kind', PartnerKind::Customer)->count())->toBe(12)
        ->and(Partner::where('kind', PartnerKind::Supplier)->count())->toBe(8)
        ->and(SalesOrder::count())->toBe(80)
        ->and(PurchaseOrder::count())->toBe(40)
        ->and(User::count())->toBe(3)
        ->and(StockMovement::count())->toBe(303)
        ->and(DB::table('sales_order_lines')->count())->toBeGreaterThanOrEqual(80)
        ->and(DB::table('purchase_order_lines')->count())->toBeGreaterThanOrEqual(40);
});

test('どの時点でも在庫が負にならない（入出庫を時系列に足し上げる）', function () {
    $stock = [];
    foreach (DB::table('stock_movements')->orderBy('created_at')->orderBy('id')->cursor() as $movement) {
        $stock[$movement->product_id] = ($stock[$movement->product_id] ?? 0) + $movement->quantity;
        expect($stock[$movement->product_id])->toBeGreaterThanOrEqual(0, "商品 {$movement->product_id}（入出庫 {$movement->id}）");
    }
    expect($stock)->toHaveCount(50);
});

test('伝票の金額は明細から計算した値（税は 10%・1 件ごとに切り捨て）', function (string $orders, string $lines, string $foreignKey) {
    $sums = DB::table($lines)
        ->selectRaw("{$foreignKey} as order_id, SUM(quantity * unit_price) as subtotal, COUNT(*) as line_count")
        ->groupBy($foreignKey)
        ->pluck('subtotal', 'order_id');

    foreach (DB::table($orders)->get() as $order) {
        $subtotal = (int) ($sums[$order->id] ?? 0);
        expect($subtotal)->toBeGreaterThan(0, $order->number)
            ->and((int) $order->subtotal)->toBe($subtotal, $order->number)
            ->and((int) $order->tax)->toBe(intdiv($subtotal * 10, 100), $order->number)
            ->and((int) $order->total)->toBe($subtotal + intdiv($subtotal * 10, 100), $order->number);
    }
})->with([
    '受注' => ['sales_orders', 'sales_order_lines', 'sales_order_id'],
    '発注' => ['purchase_orders', 'purchase_order_lines', 'purchase_order_id'],
]);

test('出荷済みの受注には明細どおりの出庫が、それ以外の受注には入出庫がない', function () {
    foreach (SalesOrder::with('lines', 'stockMovements')->get() as $order) {
        $movements = $order->stockMovements;
        if ($order->status->value === 'shipped') {
            expect(seedLinePairs($movements))->toBe(seedLinePairs($order->lines, -1), $order->number)
                ->and($movements->every(fn ($m) => $m->type->value === 'out'))->toBeTrue($order->number)
                ->and($movements->every(fn ($m) => $m->created_at->toDateString() >= $order->order_date->toDateString()))->toBeTrue($order->number);
        } else {
            expect($movements)->toBeEmpty($order->number);
        }
    }
});

test('入荷済みの発注には明細どおりの入庫が、それ以外の発注には入出庫がない', function () {
    foreach (PurchaseOrder::with('lines', 'stockMovements')->get() as $order) {
        $movements = $order->stockMovements;
        if ($order->status->value === 'received') {
            expect(seedLinePairs($movements))->toBe(seedLinePairs($order->lines), $order->number)
                ->and($movements->every(fn ($m) => $m->type->value === 'in'))->toBeTrue($order->number);
        } else {
            expect($movements)->toBeEmpty($order->number);
        }
    }
});

test('入出庫の参照先が存在し（孤立参照なし）、種類と参照元が対応している', function () {
    $orphans = DB::table('stock_movements as m')
        ->leftJoin('sales_orders as so', fn ($join) => $join->on('so.id', '=', 'm.ref_id')->where('m.ref_type', 'sales_order'))
        ->leftJoin('purchase_orders as po', fn ($join) => $join->on('po.id', '=', 'm.ref_id')->where('m.ref_type', 'purchase_order'))
        ->whereNotNull('m.ref_type')
        ->whereNull('so.id')
        ->whereNull('po.id')
        ->count();

    expect($orphans)->toBe(0)
        ->and(DB::table('stock_movements')->where('ref_type', 'sales_order')->where('type', '<>', 'out')->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('ref_type', 'purchase_order')->where('type', '<>', 'in')->count())->toBe(0)
        ->and(DB::table('stock_movements')->where('type', 'adjust')->whereNotNull('ref_type')->count())->toBe(0)
        ->and(DB::table('stock_movements')->whereNull('user_id')->count())->toBe(0);
});

test('発注点を下回る有効な商品が 1 件以上ある（ダッシュボード・通知の確認用）', function () {
    $belowReorderPoint = DB::table('products as p')
        ->join('stock_movements as m', 'm.product_id', '=', 'p.id')
        ->where('p.is_active', true)
        ->groupBy('p.id', 'p.reorder_point')
        ->havingRaw('SUM(m.quantity) < p.reorder_point')
        ->pluck('p.id');

    expect($belowReorderPoint)->not->toBeEmpty()
        ->and(SalesOrder::where('status', 'confirmed')->count())->toBeGreaterThan(0)
        ->and(PurchaseOrder::where('status', 'ordered')->count())->toBeGreaterThan(0);
});

test('受注の相手は顧客、発注の相手は仕入先', function () {
    expect(SalesOrder::whereHas('customer', fn ($q) => $q->whereNotIn('kind', ['customer', 'both']))->count())->toBe(0)
        ->and(PurchaseOrder::whereHas('supplier', fn ($q) => $q->whereNotIn('kind', ['supplier', 'both']))->count())->toBe(0);
});

test('デモユーザー 3 人のロール・パスワードが Next.js 版と同じ', function () {
    $users = User::orderBy('id')->get();

    expect($users->pluck('email')->all())->toBe(['admin@example.com', 'staff@example.com', 'viewer@example.com'])
        ->and($users->pluck('role')->all())->toBe([Role::Admin, Role::Staff, Role::Viewer]);
    foreach ($users as $user) {
        expect(Hash::check(DemoData::PASSWORD, $user->password))->toBeTrue($user->email)
            ->and($user->is_active)->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();
    }
    expect(DemoData::PASSWORD)->toBe('demo-pass-1234');
});

test('取引先は架空（名前にデモかサンプル、メールは example.com／example.jp、電話・郵便番号は 000 始まり）', function () {
    foreach (Partner::all() as $partner) {
        expect($partner->name)->toMatch('/デモ|サンプル/u')
            ->and($partner->email)->toMatch('/@example\.(com|jp)$/')
            ->and($partner->phone)->toStartWith('000')
            ->and($partner->postal_code)->toStartWith('000');
    }
});

test('日付は基準日（JST）から過去約 90 日に分布し、未来の記録はない', function () {
    $now = CarbonImmutable::now();

    foreach (['sales_orders', 'purchase_orders'] as $table) {
        expect(DB::table($table)->min('order_date'))->toBeGreaterThanOrEqual($now->subDays(DemoData::PERIOD_DAYS)->toDateString())
            ->and(DB::table($table)->max('order_date'))->toBe('2026-10-11')
            ->and(DB::table($table)->where('created_at', '>', $now)->count())->toBe(0);
    }
    expect(DB::table('stock_movements')->where('created_at', '>', $now)->count())->toBe(0)
        ->and(DB::table('stock_movements')->min('created_at'))->toStartWith($now->subDays(DemoData::PERIOD_DAYS)->toDateString());
});

test('採番テーブルの最後の番号が、その日の伝票の件数・最大番号と一致する', function () {
    $sequences = DB::table('document_sequences')->get();
    expect($sequences->sum('last_number'))->toBe(120);

    foreach ($sequences as $sequence) {
        $table = $sequence->kind === 'SO' ? 'sales_orders' : 'purchase_orders';
        $numbers = DB::table($table)->where('order_date', $sequence->date)->pluck('number');
        expect($numbers)->toHaveCount((int) $sequence->last_number)
            ->and($numbers->max())->toBe(sprintf('%s-%s-%03d', $sequence->kind, str_replace('-', '', $sequence->date), $sequence->last_number));
    }
});

test('何度実行しても同じ状態になる（全データを消して入れ直す）', function () {
    $snapshot = fn () => [
        DB::table('sales_orders')->orderBy('id')->get(['id', 'number', 'status', 'total', 'created_at', 'updated_at'])->toArray(),
        DB::table('purchase_orders')->orderBy('id')->get(['id', 'number', 'status', 'total'])->toArray(),
        DB::table('stock_movements')->orderBy('id')->get(['product_id', 'type', 'quantity', 'ref_type', 'ref_id', 'created_at'])->map(fn ($m) => (array) $m)->all(),
        DB::table('users')->pluck('email')->all(),
    ];
    $before = $snapshot();

    $this->seed();

    expect($snapshot())->toEqual($before)
        ->and(User::count())->toBe(3);
});

test('ローカル以外の DB には、許可の環境変数がなければシードしない（データを消さない）', function () {
    config(['database.connections.mysql.host' => 'db.example.com']);

    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'ローカル以外');
    expect(Product::count())->toBe(50)
        ->and(User::count())->toBe(3);
});
