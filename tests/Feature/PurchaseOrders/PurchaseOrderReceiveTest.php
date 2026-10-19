<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Orders\OrderLocks;
use App\Orders\OrderVersion;
use App\PurchaseOrders\PurchaseOrderService;
use App\Stock\StockQuery;
use App\Stock\StockTransaction;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommitsTransactions;

/**
 * 発注の入荷（ordered → received で全明細を入庫。docs/spec.md §4）。
 * 入荷は StockTransaction::run（最も外側のトランザクションとしてだけ動く）を使うので、テストのトランザクションは張らない
 */
uses(CommitsTransactions::class);

function receiveTestOrder(PurchaseOrderStatus $status, array $lines): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create(['status' => $status]);
    foreach ($lines as [$product, $quantity]) {
        PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    return $order;
}

function receiveVersion(PurchaseOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
    $this->a = Product::factory()->create();
    $this->b = Product::factory()->create();
    StockMovement::factory()->create(['product_id' => $this->a->id, 'type' => StockMovementType::In, 'quantity' => 2]);
});

test('入荷済にすると全明細を入庫（in）として記録する。参照元は発注・操作履歴も同じトランザクション', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5], [$this->b, 3]]);

    $this->actingAs($this->staff)->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => receiveVersion($order)])
        ->assertRedirect(route('purchase-orders.show', $order))->assertSessionHasNoErrors();

    $stock = app(StockQuery::class);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($stock->stock($this->a->id))->toBe(7)
        ->and($stock->stock($this->b->id))->toBe(3);
    $movements = StockMovement::query()->where('ref_type', StockRefType::PurchaseOrder->value)->orderBy('product_id')->get();
    expect($movements->map(fn (StockMovement $m) => [$m->product_id, $m->type, $m->quantity, $m->ref_id, $m->user_id, $m->reason])->all())->toBe([
        [$this->a->id, StockMovementType::In, 5, $order->id, $this->staff->id, "発注 {$order->number} の入荷"],
        [$this->b->id, StockMovementType::In, 3, $order->id, $this->staff->id, "発注 {$order->number} の入荷"],
    ])
        ->and(AuditLog::query()->sole()->summary)->toBe("発注 {$order->number} のステータスを「発注済」から「入荷済」に変更しました（入庫 2 品目）");

    // 在庫の入出庫履歴から発注の詳細へ移れる（参照元の番号を渡す）
    $this->get(route('stock.show', $this->a))->assertInertia(fn (Assert $page) => $page
        ->where('movements.0.ref', ['type' => 'purchase_order', 'id' => $order->id, 'number' => $order->number]));
});

test('入荷済は最終状態（もう一度入荷・取消はできない。在庫は 1 回分だけ）。下書きからは入荷できない', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5]]);
    $draft = receiveTestOrder(PurchaseOrderStatus::Draft, [[$this->a, 1]]);
    $this->actingAs($this->staff);

    $this->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => receiveVersion($order)])->assertSessionHasNoErrors();
    foreach (['received', 'cancelled'] as $to) {
        $this->post(route('purchase-orders.status', $order), ['to' => $to, 'version' => receiveVersion($order)])
            ->assertSessionHasErrors(['status' => 'この発注は「入荷済」のため、ステータスを変更できません']);
    }
    $this->post(route('purchase-orders.status', $draft), ['to' => 'received', 'version' => receiveVersion($draft)])
        ->assertSessionHasErrors(['status' => '「下書き」から「入荷済」には変更できません']);

    expect(app(StockQuery::class)->stock($this->a->id))->toBe(7)
        ->and($draft->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

test('取消の後は入荷できない（在庫は変わらない）', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5]]);
    $this->actingAs($this->staff);

    $this->post(route('purchase-orders.status', $order), ['to' => 'cancelled', 'version' => receiveVersion($order)])->assertSessionHasNoErrors();
    $this->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => receiveVersion($order)])
        ->assertSessionHasErrors(['status' => 'この発注は「取消」のため、ステータスを変更できません']);

    expect(app(StockQuery::class)->stock($this->a->id))->toBe(2);
});

test('競合: 詳細を開いた後に更新されていたら入荷しない（在庫は増えない）。形式の違う version も競合', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5]]);
    $stale = receiveVersion($order);
    // 他の人の更新（updated_at だけが進む。例: 備考の修正などの将来の操作）
    PurchaseOrder::query()->whereKey($order->id)->update(['updated_at' => OrderVersion::next($stale)]);
    $this->actingAs($this->staff);

    foreach ([$stale, '2026-10-19 10:00:00', null] as $version) {
        $this->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => $version])
            ->assertSessionHasErrors(['conflict' => PurchaseOrderService::statusConflictMessage()]);
    }
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and(app(StockQuery::class)->stock($this->a->id))->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0);

    $this->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => receiveVersion($order)])->assertSessionHasNoErrors();
    expect(app(StockQuery::class)->stock($this->a->id))->toBe(7);
});

test('操作履歴の保存に失敗したら、入荷（ステータス・入庫）も取り消す', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5]]);
    AuditLog::creating(function () {
        throw new RuntimeException('操作履歴の保存に失敗（テスト）');
    });

    expect(fn () => app(PurchaseOrderService::class)->changeStatus($this->staff, $order->id, PurchaseOrderStatus::Received, receiveVersion($order)))
        ->toThrow(RuntimeException::class, '操作履歴の保存に失敗（テスト）');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and(StockMovement::query()->where('ref_type', 'purchase_order')->count())->toBe(0);
});

test('明細の FOR SHARE のロックは在庫を記録するトランザクションの中では使えない（引き上げでデッドロックするため）', function () {
    expect(fn () => StockTransaction::run(fn () => OrderLocks::lockProductsForLines([$this->a->id])))
        ->toThrow(LogicException::class, 'lockProductsForLines（FOR SHARE）は在庫を記録するトランザクションでは使えません');
    // 外では使える
    expect(OrderLocks::lockProductsForLines([$this->a->id]))->toHaveKey($this->a->id)
        ->and(StockTransaction::inProgress())->toBeFalse();
});

test('仕入先が無効になっても、発注済の発注は入荷できる（入荷では仕入先・商品の有効を問わない）', function () {
    $order = receiveTestOrder(PurchaseOrderStatus::Ordered, [[$this->a, 5]]);
    Partner::query()->whereKey($order->supplier_id)->update(['is_active' => false]);
    Product::query()->whereKey($this->a->id)->update(['is_active' => false]);

    $this->actingAs($this->staff)->post(route('purchase-orders.status', $order), ['to' => 'received', 'version' => receiveVersion($order)])->assertSessionHasNoErrors();

    expect(app(StockQuery::class)->stock($this->a->id))->toBe(7);
});
