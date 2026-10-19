<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Carbon\CarbonImmutable;
use Tests\Browser\Support\Browser;

/**
 * ダッシュボードの値・商品の CSV 出力の中身・スマホ幅の表示（ブラウザ）
 */
test('ダッシュボードに、今月の受注額と前月比・未出荷・発注点割れ・入荷待ちの値が出る', function () {
    $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();
    $customer = Browser::customer();
    $supplier = Browser::supplier();
    $low = Browser::product(['code' => 'P-0001', 'name' => '不足する商品', 'reorder_point' => 10]);
    Browser::stock($low, 3);
    $enough = Browser::product(['code' => 'P-0002', 'name' => '足りている商品', 'reorder_point' => 5]);
    Browser::stock($enough, 5);
    // 無効な商品は発注点を下回っていても数えない
    Browser::product(['code' => 'P-0003', 'name' => '無効な商品', 'reorder_point' => 10, 'is_active' => false]);

    // 受注: 今月（今日）の確定 3,960 円・前月（1 日）の出荷済 2,000 円・2 か月以上前の確定で納期切れ（今月・前月の額には入らない）・
    // 今日の下書き（額に入らない）
    $salesOrder = fn (SalesOrderStatus $status, CarbonImmutable $date, int $total, ?CarbonImmutable $due = null) => SalesOrder::factory()->create([
        'customer_id' => $customer->id, 'status' => $status, 'order_date' => $date->toDateString(), 'due_date' => $due?->toDateString(),
        'subtotal' => intdiv($total * 10, 11), 'tax' => $total - intdiv($total * 10, 11), 'total' => $total,
    ]);
    $salesOrder(SalesOrderStatus::Confirmed, $today, 3960);
    $salesOrder(SalesOrderStatus::Shipped, $today->subMonthNoOverflow()->startOfMonth(), 2000);
    $overdue = $salesOrder(SalesOrderStatus::Confirmed, $today->subDays(70), 1100, $today->subDays(60));
    $salesOrder(SalesOrderStatus::Draft, $today, 99_000);
    foreach (SalesOrder::all() as $order) {
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $enough->id, 'quantity' => 1, 'unit_price' => 1000]);
    }
    // 発注: 発注済 2 件（入荷予定日が昨日 = 予定日超過、明日）・下書き 1 件（数えない）
    $purchaseOrder = fn (PurchaseOrderStatus $status, ?CarbonImmutable $expected) => PurchaseOrder::factory()->create([
        'supplier_id' => $supplier->id, 'status' => $status, 'order_date' => $today->subDays(3)->toDateString(), 'expected_date' => $expected?->toDateString(),
    ]);
    $late = $purchaseOrder(PurchaseOrderStatus::Ordered, $today->subDay());
    $soon = $purchaseOrder(PurchaseOrderStatus::Ordered, $today->addDay());
    $purchaseOrder(PurchaseOrderStatus::Draft, null);
    foreach (PurchaseOrder::all() as $order) {
        PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'product_id' => $enough->id, 'quantity' => 1, 'unit_price' => 800]);
    }

    $page = Browser::login(Browser::user(Role::Viewer));
    Browser::assertText($page, '[data-test="dashboard-sales"] > p', '3,960 円');
    Browser::assertTextContains($page, '[data-test="dashboard-sales"]', ['前月比', '+1,960 円（+98.0%）']);
    Browser::assertText($page, '[data-test="dashboard-unshipped"] > p', '2 件');
    Browser::assertTextContains($page, '[data-test="dashboard-unshipped"]', ['うち納期切れ 1 件']);
    Browser::assertText($page, '[data-test="dashboard-low-stock"] > p', '1 件');
    Browser::assertText($page, '[data-test="dashboard-awaiting"] > p', '2 件');
    Browser::assertTextContains($page, '[data-test="dashboard-awaiting"]', ['うち予定日超過 1 件']);

    // 一覧: 発注点割れの商品（在庫画面へのリンク）・納期切れの受注・入荷待ちの発注（予定日の近い順）
    $page->assertCount('li:has(a[href^="/stock/"])', 1);
    Browser::assertTextContains($page, "li:has(a[href=\"/stock/{$low->id}\"])", ['P-0001', '不足する商品', '在庫 3 / 発注点 10', '不足 7個']);
    $page->assertCount('li:has(a[href^="/sales-orders/"])', 1);
    Browser::assertTextContains($page, "li:has(a[href=\"/sales-orders/{$overdue->id}\"])", [$overdue->number, 'サンプル商店', '納期切れ']);
    $page->assertScript(
        'function () { return JSON.stringify([...document.querySelectorAll(\'li a[href^="/purchase-orders/"]\')].map(function (a) { return a.innerText.trim(); })); }',
        json_encode([$late->number, $soon->number], JSON_THROW_ON_ERROR),
    );
    Browser::assertTextContains($page, "li:has(a[href=\"/purchase-orders/{$late->id}\"])", ['サンプル卸', '予定日超過']);
});

test('商品の CSV 出力は、一覧の絞り込みのとおりの商品を、BOM 付き UTF-8・見出し行つきで出す（値まで一致）', function () {
    $active = Browser::product(['code' => 'P-0001', 'name' => 'カンマ,と"引用符"の商品', 'category' => '文具', 'unit' => '箱', 'sales_price' => 1200, 'purchase_price' => 800, 'reorder_point' => 7]);
    Browser::stock($active, 4);
    Browser::product(['code' => 'P-0002', 'name' => '無効な商品', 'category' => '日用品', 'unit' => '個', 'sales_price' => 300, 'purchase_price' => 150, 'reorder_point' => 0, 'is_active' => false]);

    // viewer も CSV を出力できる（csv.export）。状態「すべて」で絞り込んだ一覧の「CSV 出力」のリンクから取得する
    $page = Browser::login(Browser::user(Role::Viewer));
    Browser::open($page, '/products?status=all');
    $page->assertCount('[data-test="product-row"]', 2);
    $href = $page->script('function () { return document.querySelector(\'[data-test="product-export"]\').getAttribute("href"); }');
    expect($href)->toBeString()->toContain('/products/export')->toContain('status=all');

    // ブラウザの fetch（ログイン中の Cookie つき）で取得し、バイト列のまま受け取る（BOM を確かめるため）
    $response = $page->script('function () { return fetch('.json_encode($href, JSON_THROW_ON_ERROR).', { credentials: "same-origin" }).then(function (r) { '
        .'return r.arrayBuffer().then(function (buffer) { return { status: r.status, type: r.headers.get("content-type"), '
        .'disposition: r.headers.get("content-disposition"), bytes: Array.from(new Uint8Array(buffer)) }; }); }); }');
    expect($response['status'])->toBe(200)
        ->and($response['type'])->toBe('text/csv; charset=utf-8')
        ->and($response['disposition'])->toMatch('/^attachment; filename="products_\d{8}\.csv"$/');

    $bytes = implode('', array_map(chr(...), $response['bytes']));
    expect(substr($bytes, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(str_ends_with($bytes, "\r\n"))->toBeTrue();
    $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), explode("\r\n", substr($bytes, 3, -2)));
    expect($rows)->toBe([
        ['商品コード', '商品名', 'カテゴリ', '単位', '売価', '仕入単価', '発注点', '現在庫', '状態'],
        ['P-0001', 'カンマ,と"引用符"の商品', '文具', '箱', '1200', '800', '7', '4', '有効'],
        ['P-0002', '無効な商品', '日用品', '個', '300', '150', '0', '0', '無効'],
    ]);
});

test('スマホ幅（375px）で主な画面に横スクロールが出ない', function () {
    $customer = Browser::customer();
    $product = Browser::product(['code' => 'P-0001', 'name' => 'テスト商品']);
    Browser::stock($product, 5);
    $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed]);
    SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200]);
    $staff = Browser::user(Role::Staff);

    // on()->mobile() は呼ぶたびに新しいページを開くので、最初の呼び出しが返したページ（AwaitableWebpage）を使い続ける
    $page = visit('/login')->on()->mobile()->assertPresent('@login-button');
    $page->fill('email', $staff->email)->fill('password', Browser::PASSWORD)->click('@login-button');
    $page->assertPathIs('/dashboard');
    expect($page->script('function () { return window.innerWidth; }'))->toBe(375);

    // [画面, 表示されたことの目印]
    foreach ([
        ['/dashboard', '[data-test="dashboard-sales"]'],
        ['/products', '[data-test="product-row"]'],
        ['/sales-orders', '[data-test="sales-order-row"]'],
        ['/sales-orders/create', '[data-test="order-line"]'],
        ["/sales-orders/{$order->id}", '[data-test="sales-order-line"]'],
        ['/stock', '[data-test="stock-row"]'],
        ["/stock/{$product->id}", '[data-test="stock-movement-row"]'],
    ] as [$path, $ready]) {
        Browser::open($page, $path);
        $page->assertPresent($ready);
        Browser::assertNoHorizontalScroll($page);
    }
});
