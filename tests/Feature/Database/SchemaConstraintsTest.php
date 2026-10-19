<?php

use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Database\AppTables;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB 側で守る制約（CHECK・外部キー・一意制約）が MySQL で効いていること。docs/spec.md §3
 */
beforeEach(function () {
    $product = Product::factory()->create();
    $customer = Partner::factory()->customer()->create();
    $supplier = Partner::factory()->supplier()->create();
    $salesOrder = SalesOrder::factory()->for($customer, 'customer')->create();
    $purchaseOrder = PurchaseOrder::factory()->for($supplier, 'supplier')->create();

    // 各テーブルの正しい 1 行（不正な値はこれを上書きして作る）
    $this->valid = [
        'users' => ['name' => 'サンプル利用者', 'email' => 'check@example.com', 'password' => 'x', 'role' => 'viewer', 'is_active' => true],
        'partners' => ['code' => 'C-8001', 'name' => 'サンプル取引先', 'kind' => 'customer', 'postal_code' => '0000001'],
        'products' => ['code' => 'P-8001', 'name' => 'サンプル商品', 'category' => '文具', 'unit' => '個', 'sales_price' => 100, 'purchase_price' => 70, 'reorder_point' => 10],
        'sales_orders' => ['number' => 'SO-20261019-801', 'customer_id' => $customer->id, 'order_date' => '2026-10-19', 'status' => 'draft', 'subtotal' => 1000, 'tax' => 100, 'total' => 1100],
        'purchase_orders' => ['number' => 'PO-20261019-801', 'supplier_id' => $supplier->id, 'order_date' => '2026-10-19', 'status' => 'draft', 'subtotal' => 1000, 'tax' => 100, 'total' => 1100],
        'sales_order_lines' => ['sales_order_id' => $salesOrder->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100],
        'purchase_order_lines' => ['purchase_order_id' => $purchaseOrder->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100],
        'stock_movements' => ['product_id' => $product->id, 'type' => 'adjust', 'quantity' => 5, 'ref_type' => null, 'ref_id' => null],
        'document_sequences' => ['kind' => 'SO', 'date' => '2026-10-19', 'last_number' => 1],
    ];
});

test('正しい値なら各テーブルに入る（下の CHECK のテストの前提）', function () {
    foreach ($this->valid as $table => $row) {
        DB::table($table)->insert($row);
    }

    expect(DB::table('stock_movements')->count())->toBe(1);
});

test('CHECK 制約が不正な値を拒否する', function (string $table, array $overrides, string $constraint) {
    $row = [...$this->valid[$table], ...$overrides];

    expect(fn () => DB::table($table)->insert($row))->toThrow(QueryException::class, $constraint);
})->with([
    'ロールが定義外' => ['users', ['role' => 'owner'], 'users_role_check'],
    'ロールの大文字小文字違い' => ['users', ['role' => 'ADMIN'], 'users_role_check'],
    'ロールの末尾に空白' => ['users', ['role' => 'admin '], 'users_role_check'],
    '取引先の種類の末尾に空白' => ['partners', ['kind' => 'customer '], 'partners_kind_check'],
    '受注ステータスの末尾に空白' => ['sales_orders', ['status' => 'draft '], 'sales_orders_status_check'],
    '発注ステータスの末尾に空白' => ['purchase_orders', ['status' => 'draft  '], 'purchase_orders_status_check'],
    '入出庫の種類の末尾に空白' => ['stock_movements', ['type' => 'in ', 'quantity' => 5], "Check constraint 'stock_movements_"],
    '参照元の種類の末尾に空白' => ['stock_movements', ['ref_type' => 'sales_order ', 'ref_id' => 1], 'stock_movements_ref_type_check'],
    '採番の種類の末尾に空白' => ['document_sequences', ['kind' => 'S '], 'document_sequences_kind_check'],
    '取引先の種類が定義外' => ['partners', ['kind' => 'vendor'], 'partners_kind_check'],
    '郵便番号にハイフン' => ['partners', ['postal_code' => '000-001'], 'partners_postal_code_check'],
    '郵便番号が 6 桁' => ['partners', ['postal_code' => '000001'], 'partners_postal_code_check'],
    '売価が負' => ['products', ['sales_price' => -1], 'products_sales_price_check'],
    '仕入単価が負' => ['products', ['purchase_price' => -1], 'products_purchase_price_check'],
    '発注点が負' => ['products', ['reorder_point' => -1], 'products_reorder_point_check'],
    '受注ステータスが定義外' => ['sales_orders', ['status' => 'received'], 'sales_orders_status_check'],
    '受注の合計 ≠ 小計＋税' => ['sales_orders', ['total' => 1099], 'sales_orders_amounts_check'],
    '受注の小計が負' => ['sales_orders', ['subtotal' => -100, 'tax' => 0, 'total' => -100], 'sales_orders_amounts_check'],
    '発注ステータスが定義外' => ['purchase_orders', ['status' => 'shipped'], 'purchase_orders_status_check'],
    '発注の合計 ≠ 小計＋税' => ['purchase_orders', ['tax' => 99], 'purchase_orders_amounts_check'],
    '受注明細の数量が 0' => ['sales_order_lines', ['quantity' => 0], 'sales_order_lines_quantity_check'],
    '受注明細の単価が負' => ['sales_order_lines', ['unit_price' => -1], 'sales_order_lines_unit_price_check'],
    '発注明細の数量が負' => ['purchase_order_lines', ['quantity' => -1], 'purchase_order_lines_quantity_check'],
    '発注明細の単価が負' => ['purchase_order_lines', ['unit_price' => -1], 'purchase_order_lines_unit_price_check'],
    // 種類が定義外だと数量の CHECK（種類ごとの符号）にも違反し、MySQL はどちらか一方の名前を報告する
    '入出庫の種類が定義外' => ['stock_movements', ['type' => 'move'], "Check constraint 'stock_movements_"],
    '入庫の数量が負' => ['stock_movements', ['type' => 'in', 'quantity' => -1], 'stock_movements_quantity_check'],
    '出庫の数量が正' => ['stock_movements', ['type' => 'out', 'quantity' => 1], 'stock_movements_quantity_check'],
    '棚卸調整の数量が 0' => ['stock_movements', ['type' => 'adjust', 'quantity' => 0], 'stock_movements_quantity_check'],
    '参照元の種類だけある' => ['stock_movements', ['ref_type' => 'sales_order'], 'stock_movements_ref_check'],
    '参照元の ID だけある' => ['stock_movements', ['ref_id' => 1], 'stock_movements_ref_check'],
    '参照元の種類が定義外' => ['stock_movements', ['ref_type' => 'invoice', 'ref_id' => 1], 'stock_movements_ref_type_check'],
    '採番の種類が定義外' => ['document_sequences', ['kind' => 'XX'], 'document_sequences_kind_check'],
    '採番の番号が負' => ['document_sequences', ['last_number' => -1], 'document_sequences_last_number_check'],
]);

test('入出庫の符号は種類に合っていれば入る（出庫は負、棚卸調整は負も可）', function () {
    $base = $this->valid['stock_movements'];
    DB::table('stock_movements')->insert([
        [...$base, 'type' => 'in', 'quantity' => 10],
        [...$base, 'type' => 'out', 'quantity' => -3],
        [...$base, 'type' => 'adjust', 'quantity' => -2],
    ]);

    expect(DB::table('stock_movements')->sum('quantity'))->toEqual(5);
});

test('一意制約（コード・番号・メール、明細の（伝票, 商品））', function (string $table, array $overrides) {
    $row = [...$this->valid[$table], ...$overrides];
    DB::table($table)->insert($row);

    expect(fn () => DB::table($table)->insert($row))->toThrow(QueryException::class, 'Duplicate entry');
})->with([
    'ユーザーのメール' => ['users', []],
    '取引先コード' => ['partners', []],
    '商品コード' => ['products', []],
    '受注番号' => ['sales_orders', []],
    '発注番号' => ['purchase_orders', []],
    '受注明細の（受注, 商品）' => ['sales_order_lines', ['quantity' => 2]],
    '発注明細の（発注, 商品）' => ['purchase_order_lines', ['quantity' => 2]],
    '採番の（種類, 日付）' => ['document_sequences', []],
]);

test('伝票を消すと明細も消える（cascade）', function () {
    $order = SalesOrder::factory()->create();
    SalesOrderLine::factory()->for($order)->count(2)->create();

    $order->delete();

    expect(SalesOrderLine::where('sales_order_id', $order->id)->count())->toBe(0);
});

test('参照されているマスタ・ユーザーは消せない（restrict）', function () {
    $line = SalesOrderLine::factory()->create();
    $user = User::factory()->create();
    StockMovement::factory()->for($user)->create();
    $auditUser = User::factory()->create();
    DB::table('audit_logs')->insert(['user_id' => $auditUser->id, 'action' => 'login', 'summary' => 'ログイン']);

    expect(fn () => Product::whereKey($line->product_id)->delete())->toThrow(QueryException::class, 'foreign key constraint')
        ->and(fn () => Partner::whereKey($line->salesOrder->customer_id)->delete())->toThrow(QueryException::class, 'foreign key constraint')
        ->and(fn () => $user->delete())->toThrow(QueryException::class, 'foreign key constraint')
        ->and(fn () => $auditUser->delete())->toThrow(QueryException::class, 'foreign key constraint');
});

test('親の ID を変えると参照が追従する（ON UPDATE CASCADE。Next.js 版と同じ）', function () {
    $salesLine = SalesOrderLine::factory()->create();
    $purchaseLine = PurchaseOrderLine::factory()->for(Product::find($salesLine->product_id))->create();
    $user = User::factory()->create();
    $movement = StockMovement::factory()->for(Product::find($salesLine->product_id))->for($user)->create();
    DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'login', 'summary' => 'ログイン']);
    $customerId = $salesLine->salesOrder->customer_id;
    $supplierId = $purchaseLine->purchaseOrder->supplier_id;

    DB::table('products')->where('id', $salesLine->product_id)->update(['id' => 900001]);
    DB::table('sales_orders')->where('id', $salesLine->sales_order_id)->update(['id' => 900002]);
    DB::table('purchase_orders')->where('id', $purchaseLine->purchase_order_id)->update(['id' => 900003]);
    DB::table('partners')->where('id', $customerId)->update(['id' => 900004]);
    DB::table('partners')->where('id', $supplierId)->update(['id' => 900005]);
    DB::table('users')->where('id', $user->id)->update(['id' => 900006]);

    $sales = DB::table('sales_order_lines')->find($salesLine->id);
    $purchase = DB::table('purchase_order_lines')->find($purchaseLine->id);
    $stock = DB::table('stock_movements')->find($movement->id);
    expect([$sales->product_id, $sales->sales_order_id])->toBe([900001, 900002])
        ->and([$purchase->product_id, $purchase->purchase_order_id])->toBe([900001, 900003])
        ->and(DB::table('sales_orders')->where('id', 900002)->value('customer_id'))->toBe(900004)
        ->and(DB::table('purchase_orders')->where('id', 900003)->value('supplier_id'))->toBe(900005)
        ->and([$stock->product_id, $stock->user_id])->toBe([900001, 900006])
        ->and(DB::table('audit_logs')->where('user_id', 900006)->count())->toBe(1);
});

test('DB の既定値（CURRENT_TIMESTAMP）で入る日時がアプリのタイムゾーン（日本時間）とずれない', function () {
    expect(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+09:00');

    DB::table('audit_logs')->insert(['action' => 'test', 'summary' => '既定の日時']);
    DB::table('stock_movements')->insert($this->valid['stock_movements']);

    $now = CarbonImmutable::now();
    foreach (['audit_logs', 'stock_movements'] as $table) {
        $stored = CarbonImmutable::parse((string) DB::table($table)->value('created_at'));
        expect(abs($stored->diffInSeconds($now)))->toBeLessThan(60, "{$table}: {$stored} / {$now}");
    }
});

test('シードで消すテーブルの一覧（AppTables）がマイグレーションとずれていない', function () {
    $tables = array_column(Schema::getTables(DB::connection()->getDatabaseName()), 'name');
    sort($tables);
    $expected = [...AppTables::ALL, ...AppTables::FRAMEWORK_TABLES];
    sort($expected);

    expect($tables)->toBe($expected);
});
