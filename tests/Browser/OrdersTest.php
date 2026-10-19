<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use Tests\Browser\Support\Browser;

/**
 * 受注・発注のステータス遷移と在庫（staff。画面から操作し、在庫は在庫画面の表示で確かめる）
 */
beforeEach(function () {
    $this->staff = Browser::user(Role::Staff);
});

/** 受注を画面から作る（下書き）。作った受注の詳細画面を表示した状態で返す */
function createSalesOrderInBrowser(mixed $page, int $customerId, int $productId, int $quantity): SalesOrder
{
    Browser::open($page, '/sales-orders/create');
    $page->select('customer_id', (string) $customerId)
        ->select('[aria-label="1 行目の商品"]', (string) $productId)
        // 単価には商品の売価が入る
        ->assertValue('[aria-label="1 行目の単価"]', '1200')
        ->fill('[aria-label="1 行目の数量"]', (string) $quantity)
        ->click('@sales-order-submit');
    Browser::assertText($page, '[data-test="sales-order-status"]', '下書き');
    $order = SalesOrder::query()->sole();
    $page->assertPathIs("/sales-orders/{$order->id}");

    return $order;
}

/** 詳細画面のボタンでステータスを変える（確認のダイアログで実行する） */
function changeStatusInBrowser(mixed $page, string $kind, string $to): void
{
    $page->click("@{$kind}-order-to-{$to}");
    $page->click("@{$kind}-order-status-confirm");
}

test('受注を作成 → 確定 → 出荷すると、在庫画面の在庫が減り、入出庫履歴に出荷の出庫が残る', function () {
    $customer = Browser::customer();
    $product = Browser::product(['code' => 'P-0001', 'name' => 'テスト商品A']);
    Browser::stock($product, 10);

    $page = Browser::login($this->staff);
    $order = createSalesOrderInBrowser($page, $customer->id, $product->id, 3);
    Browser::assertText($page, '[data-test="sales-order-total"]', '3,960 円');

    changeStatusInBrowser($page, 'sales', 'confirmed');
    Browser::assertText($page, '[data-test="sales-order-status"]', '確定');
    changeStatusInBrowser($page, 'sales', 'shipped');
    Browser::assertText($page, '[data-test="sales-order-status"]', '出荷済');
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Shipped);

    Browser::open($page, "/stock/{$product->id}");
    Browser::assertText($page, '[data-test="stock-current"]', '7 個');
    // 新しい順: 出荷の出庫（-3）、初期在庫の入庫（+10）。列は 種別・数量・理由・参照元（日時・記録者は除く）
    Browser::assertRows($page, '[data-test="stock-movement-row"]', [
        ['出庫', '-3', "受注 {$order->number} の出荷", "受注 {$order->number}"],
        ['入庫', '+10', '初期在庫（テスト）', '—'],
    ], [1, 2, 3, 4]);

    // 在庫の一覧でも同じ在庫
    Browser::open($page, '/stock');
    Browser::assertRows($page, '[data-test="stock-row"]', [['P-0001', 'テスト商品A', '7']], [0, 1, 5]);
});

test('発注を作成 → 発注済 → 入荷すると、在庫画面の在庫が増える', function () {
    $supplier = Browser::supplier();
    $product = Browser::product(['code' => 'P-0002', 'name' => 'テスト商品B']);

    $page = Browser::login($this->staff);
    Browser::open($page, "/stock/{$product->id}");
    Browser::assertText($page, '[data-test="stock-current"]', '0 個');

    Browser::open($page, '/purchase-orders/create');
    $page->select('supplier_id', (string) $supplier->id)
        ->select('[aria-label="1 行目の商品"]', (string) $product->id)
        // 単価には商品の仕入単価が入る
        ->assertValue('[aria-label="1 行目の単価"]', '800')
        ->fill('[aria-label="1 行目の数量"]', '12')
        ->click('@purchase-order-submit');
    Browser::assertText($page, '[data-test="purchase-order-status"]', '下書き');
    $order = PurchaseOrder::query()->sole();
    $page->assertPathIs("/purchase-orders/{$order->id}");

    changeStatusInBrowser($page, 'purchase', 'ordered');
    Browser::assertText($page, '[data-test="purchase-order-status"]', '発注済');
    changeStatusInBrowser($page, 'purchase', 'received');
    Browser::assertText($page, '[data-test="purchase-order-status"]', '入荷済');
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);

    Browser::open($page, "/stock/{$product->id}");
    Browser::assertText($page, '[data-test="stock-current"]', '12 個');
    Browser::assertRows($page, '[data-test="stock-movement-row"]', [
        ['入庫', '+12', "発注 {$order->number} の入荷", "発注 {$order->number}"],
    ], [1, 2, 3, 4]);
});

test('在庫が足りないと出荷せず、不足（必要数・現在庫・不足数）を表示し、ステータスと在庫は変わらない', function () {
    $customer = Browser::customer();
    $product = Browser::product(['code' => 'P-0003', 'name' => 'テスト商品C']);
    Browser::stock($product, 2);

    $page = Browser::login($this->staff);
    $order = createSalesOrderInBrowser($page, $customer->id, $product->id, 5);
    changeStatusInBrowser($page, 'sales', 'confirmed');
    Browser::assertText($page, '[data-test="sales-order-status"]', '確定');
    $version = $order->fresh()->updated_at;

    changeStatusInBrowser($page, 'sales', 'shipped');
    $page->assertSee('在庫が足りない商品が 1 品目あるため、出荷しませんでした（在庫・ステータスは変わっていません）');
    Browser::assertRows($page, '[data-test="sales-order-shortages"] tbody tr', [
        ['P-0003 テスト商品C', '5 個', '2 個', '3 個'],
    ]);

    // ダイアログを閉じて開き直しても確定のまま。在庫も変わらない
    $page->click('キャンセル');
    Browser::open($page, "/sales-orders/{$order->id}");
    Browser::assertText($page, '[data-test="sales-order-status"]', '確定');
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and($order->fresh()->updated_at?->eq($version))->toBeTrue();
    Browser::open($page, "/stock/{$product->id}");
    Browser::assertText($page, '[data-test="stock-current"]', '2 個');
    Browser::assertRows($page, '[data-test="stock-movement-row"]', [['入庫', '+2']], [1, 2]);
});
