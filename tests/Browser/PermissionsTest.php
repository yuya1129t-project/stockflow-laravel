<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Tests\Browser\Support\Browser;

/**
 * viewer（閲覧者）の制限（ブラウザ）。編集の操作が画面に出ないことは、同じ画面を staff で開いて「ある」ことを先に確かめてから
 * viewer で「ない」ことを確かめる（ボタンの名前や作りが変わったときに、viewer の側だけ素通りしないように）。
 * 直接 URL を開いたときの 403 も、同じ URL を staff が開けることと並べて確かめる
 */
beforeEach(function () {
    $this->product = Browser::product(['code' => 'P-0001', 'name' => 'テスト商品']);
    Browser::stock($this->product, 5);
    $customer = Browser::customer();
    $supplier = Browser::supplier();

    $this->salesDraft = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Draft]);
    $this->salesConfirmed = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed]);
    foreach ([$this->salesDraft, $this->salesConfirmed] as $order) {
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1200]);
    }
    $this->purchaseDraft = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Draft]);
    $this->purchaseOrdered = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'status' => PurchaseOrderStatus::Ordered]);
    foreach ([$this->purchaseDraft, $this->purchaseOrdered] as $order) {
        PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 800]);
    }
});

test('viewer には編集の操作が出ない（同じ画面を staff で開くと出る）', function () {
    $p = $this->product->id;
    // [画面, 表示されたことの目印, 編集の操作]
    $screens = [
        ['/products', '[data-test="product-row"]', ['a[href$="/products/create"]', 'a[href$="/products/import"]']],
        ["/products/{$p}", '[data-test="product-stock"]', ["a[href\$=\"/products/{$p}/edit\"]"]],
        ['/partners', '[data-test="partner-row"]', ['[data-test="partner-create"]']],
        ['/sales-orders', '[data-test="sales-order-row"]', ['a[href$="/sales-orders/create"]']],
        ["/sales-orders/{$this->salesDraft->id}", '[data-test="sales-order-status"]', ['[data-test="sales-order-edit"]', '[data-test="sales-order-to-confirmed"]', '[data-test="sales-order-to-cancelled"]']],
        ["/sales-orders/{$this->salesConfirmed->id}", '[data-test="sales-order-status"]', ['[data-test="sales-order-to-shipped"]', '[data-test="sales-order-to-cancelled"]']],
        ['/purchase-orders', '[data-test="purchase-order-row"]', ['a[href$="/purchase-orders/create"]']],
        ["/purchase-orders/{$this->purchaseDraft->id}", '[data-test="purchase-order-status"]', ['[data-test="purchase-order-edit"]', '[data-test="purchase-order-to-ordered"]']],
        ["/purchase-orders/{$this->purchaseOrdered->id}", '[data-test="purchase-order-status"]', ['[data-test="purchase-order-to-received"]']],
        ["/stock/{$p}", '[data-test="stock-current"]', ['[data-test="stock-adjust-submit"]']],
    ];

    $staff = Browser::login(Browser::user(Role::Staff));
    $viewer = Browser::login(Browser::user(Role::Viewer));
    foreach ($screens as [$path, $ready, $actions]) {
        $staff->navigate($path);
        $staff->assertPresent($ready);
        $viewer->navigate($path);
        // 画面が表示されてから数える（表示の前に数えると 0 件で素通りする）
        $viewer->assertPresent($ready);
        foreach ($actions as $selector) {
            $staff->assertCount($selector, 1);
            $viewer->assertCount($selector, 0);
        }
    }
});

test('viewer が編集の画面の URL を直接開くと 403 の画面になる（staff は開ける）', function () {
    $paths = [
        '/products/create',
        "/products/{$this->product->id}/edit",
        '/products/import',
        '/partners/create',
        '/sales-orders/create',
        "/sales-orders/{$this->salesDraft->id}/edit",
        '/purchase-orders/create',
        "/purchase-orders/{$this->purchaseDraft->id}/edit",
    ];

    $staff = Browser::login(Browser::user(Role::Staff));
    $viewer = Browser::login(Browser::user(Role::Viewer));
    foreach ($paths as $path) {
        $staff->navigate($path);
        $staff->assertPathIs($path);
        $staff->assertDontSee('この画面を表示する権限がありません');
        expect(responseStatus($staff, $path))->toBe(200);

        $viewer->navigate($path);
        $viewer->assertSee('この画面を表示する権限がありません');
        $viewer->assertPathIs($path);
        expect(responseStatus($viewer, $path))->toBe(403);
    }

    // admin だけの画面（ユーザー管理・操作履歴）は staff・viewer とも 403
    foreach (['/users', '/audit-logs'] as $path) {
        foreach ([$staff, $viewer] as $page) {
            $page->navigate($path);
            $page->assertSee('この画面を表示する権限がありません');
            expect(responseStatus($page, $path))->toBe(403);
        }
    }
});

/** ログイン中のブラウザ（Cookie つき）で path を GET したときの HTTP ステータス */
function responseStatus(mixed $page, string $path): int
{
    $pathJson = json_encode($path, JSON_THROW_ON_ERROR);

    return (int) $page->script("fetch({$pathJson}, { credentials: 'same-origin' }).then(function (r) { return r.status; })");
}
