<?php

use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Orders\OrderVersion;
use App\SalesOrders\SalesOrderService;
use App\Stock\InsufficientStockException;
use App\Stock\StockChange;
use App\Stock\StockQuery;
use App\Stock\StockShortage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommitsTransactions;

/**
 * 受注の出荷（confirmed → shipped で全明細を出庫。docs/spec.md §4）。発注の入荷（PurchaseOrderReceiveTest）と同じ観点に、
 * 在庫不足で全体を中止する（不足する商品と数量を表示する）ことを加える。
 * 出荷は StockTransaction::run（最も外側のトランザクションとしてだけ動く）を使うので、テストのトランザクションは張らない
 */
uses(CommitsTransactions::class);

/** @param  list<array{0: Product, 1: int}>  $lines  [商品, 数量] */
function shipTestOrder(SalesOrderStatus $status, array $lines): SalesOrder
{
    $order = SalesOrder::factory()->create(['status' => $status]);
    foreach ($lines as [$product, $quantity]) {
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    return $order;
}

function shipVersion(SalesOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

/** @return list<array<string, mixed>>|null 1 回だけの flash で返した不足する商品 */
function shipShortages(TestResponse $response): ?array
{
    $flash = $response->baseResponse->getSession()?->get(SessionKey::FLASH_DATA, []) ?? [];

    return $flash['shortages'] ?? null;
}

function shipStock(Product $product): int
{
    return app(StockQuery::class)->stock($product->id);
}

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
    $this->a = Product::factory()->create(['code' => 'P-0001', 'name' => '商品A', 'unit' => '個', 'reorder_point' => 0]);
    $this->b = Product::factory()->create(['code' => 'P-0002', 'name' => '商品B', 'unit' => '箱', 'reorder_point' => 0]);
    StockMovement::factory()->create(['product_id' => $this->a->id, 'type' => StockMovementType::In, 'quantity' => 10]);
    StockMovement::factory()->create(['product_id' => $this->b->id, 'type' => StockMovementType::In, 'quantity' => 4]);
});

test('出荷済にすると全明細を出庫（out・負の数量）として記録する。参照元は受注・操作履歴も同じトランザクション', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 6], [$this->b, 4]]);

    $this->actingAs($this->staff)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])
        ->assertRedirect(route('sales-orders.show', $order))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Shipped)
        ->and(shipStock($this->a))->toBe(4)
        ->and(shipStock($this->b))->toBe(0);
    $movements = StockMovement::query()->where('ref_type', StockRefType::SalesOrder->value)->orderBy('product_id')->get();
    expect($movements->map(fn (StockMovement $m) => [$m->product_id, $m->type, $m->quantity, $m->ref_id, $m->user_id, $m->reason])->all())->toBe([
        [$this->a->id, StockMovementType::Out, -6, $order->id, $this->staff->id, "受注 {$order->number} の出荷"],
        [$this->b->id, StockMovementType::Out, -4, $order->id, $this->staff->id, "受注 {$order->number} の出荷"],
    ])
        ->and(AuditLog::query()->sole()->summary)->toBe("受注 {$order->number} のステータスを「確定」から「出荷済」に変更しました（出庫 2 品目）");

    // 在庫の入出庫履歴から受注の詳細へ移れる（参照元の番号を渡す）
    $this->get(route('stock.show', $this->a))->assertInertia(fn (Assert $page) => $page
        ->where('movements.0.ref', ['type' => 'sales_order', 'id' => $order->id, 'number' => $order->number]));
});

test('在庫不足: 1 行だけ不足でも全体を中止する（他の行も記録しない・状態・updated_at・操作履歴は変わらない）。不足する商品と数量を返す', function () {
    // A は足りる（10 → 6）、B だけ足りない（在庫 4・必要 7）
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 6], [$this->b, 7]]);
    $version = shipVersion($order);
    $logs = AuditLog::query()->count();

    $response = $this->actingAs($this->staff)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => $version]);

    $response->assertRedirect(route('sales-orders.show', $order))
        ->assertSessionHasErrors(['status' => '在庫が足りない商品が 1 品目あるため、出荷しませんでした（在庫・ステータスは変わっていません）']);
    expect(shipShortages($response))->toBe([
        ['product_id' => $this->b->id, 'code' => 'P-0002', 'name' => '商品B', 'unit' => '箱', 'required' => 7, 'available' => 4, 'shortage' => 3],
    ])
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(shipVersion($order))->toBe($version)
        ->and(StockMovement::query()->where('ref_type', StockRefType::SalesOrder->value)->count())->toBe(0)
        ->and([shipStock($this->a), shipStock($this->b)])->toBe([10, 4])
        ->and(AuditLog::query()->count())->toBe($logs);

    // 入力（明細）も変えていないので、在庫を補充すれば同じ画面（同じ version）のまま出荷できる
    StockMovement::factory()->create(['product_id' => $this->b->id, 'type' => StockMovementType::In, 'quantity' => 3]);
    $this->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => $version])->assertSessionHasNoErrors();
    expect([shipStock($this->a), shipStock($this->b)])->toBe([4, 0])
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Shipped);
});

test('在庫不足が複数の商品: すべての不足を商品コードの順に返す（サービスは InsufficientStockException）', function () {
    // 商品 ID と商品コードの順を逆にする（表は商品コードの順）
    $this->a->update(['code' => 'P-0009']);
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 11], [$this->b, 5]]);

    $response = $this->actingAs($this->staff)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)]);

    $response->assertSessionHasErrors(['status' => '在庫が足りない商品が 2 品目あるため、出荷しませんでした（在庫・ステータスは変わっていません）']);
    expect(array_map(fn (array $s) => [$s['code'], $s['required'], $s['available'], $s['shortage'], $s['unit']], shipShortages($response) ?? []))->toBe([
        ['P-0002', 5, 4, 1, '箱'],
        ['P-0009', 11, 10, 1, '個'],
    ]);

    try {
        app(SalesOrderService::class)->changeStatus($this->staff, $order->id, SalesOrderStatus::Shipped, shipVersion($order));
        $this->fail('InsufficientStockException が投げられませんでした');
    } catch (InsufficientStockException $e) {
        expect(array_map(fn (StockShortage $s) => $s->product->id, $e->shortages))->toBe([$this->a->id, $this->b->id]);
    }
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
});

test('出荷の結果: 発注点以上 → 未満に変わった有効な商品を、確定後の呼び出し元に返す（#11 の通知用。無効な商品は含めない）', function () {
    $this->a->update(['reorder_point' => 5]);   // 10 → 4: 発注点割れに変わる
    $this->b->update(['reorder_point' => 10]);  // 4 → 1: もとから割れている（変わったものではない）
    $c = Product::factory()->create(['reorder_point' => 3, 'is_active' => false]);
    StockMovement::factory()->create(['product_id' => $c->id, 'type' => StockMovementType::In, 'quantity' => 5]);
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 6], [$this->b, 3], [$c, 4]]);

    $result = app(SalesOrderService::class)->changeStatus($this->staff, $order->id, SalesOrderStatus::Shipped, shipVersion($order));

    expect($result['number'])->toBe($order->number)
        ->and([$result['from'], $result['to']])->toBe([SalesOrderStatus::Confirmed, SalesOrderStatus::Shipped])
        ->and(array_map(fn (StockChange $c) => [$c->product->id, $c->before, $c->after], $result['stock']?->fellBelowReorderPoint ?? []))->toBe([[$this->a->id, 10, 4]])
        ->and(array_map(fn (StockChange $c) => [$c->product->id, $c->before, $c->after], $result['stock']?->changes ?? []))->toBe([
            [$this->a->id, 10, 4], [$this->b->id, 4, 1], [$c->id, 5, 1],
        ]);
});

test('出荷済は最終状態（もう一度出荷・取消はできない。在庫は 1 回分だけ）。下書きからは出荷できない', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);
    $draft = shipTestOrder(SalesOrderStatus::Draft, [[$this->a, 1]]);
    $this->actingAs($this->staff);

    $this->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])->assertSessionHasNoErrors();
    foreach (['shipped', 'cancelled'] as $to) {
        $this->post(route('sales-orders.status', $order), ['to' => $to, 'version' => shipVersion($order)])
            ->assertSessionHasErrors(['status' => 'この受注は「出荷済」のため、ステータスを変更できません']);
    }
    $this->post(route('sales-orders.status', $draft), ['to' => 'shipped', 'version' => shipVersion($draft)])
        ->assertSessionHasErrors(['status' => '「下書き」から「出荷済」には変更できません']);

    expect(shipStock($this->a))->toBe(5)
        ->and($draft->fresh()->status)->toBe(SalesOrderStatus::Draft);
});

test('取消の後は出荷できない（在庫は変わらない）', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);
    $this->actingAs($this->staff);

    $this->post(route('sales-orders.status', $order), ['to' => 'cancelled', 'version' => shipVersion($order)])->assertSessionHasNoErrors();
    $this->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])
        ->assertSessionHasErrors(['status' => 'この受注は「取消」のため、ステータスを変更できません']);

    expect(shipStock($this->a))->toBe(10);
});

test('競合: 詳細を開いた後に更新されていたら出荷しない（在庫は減らない）。形式の違う version も競合', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);
    $stale = shipVersion($order);
    // 他の人の更新（updated_at だけが進む）
    SalesOrder::query()->whereKey($order->id)->update(['updated_at' => OrderVersion::next($stale)]);
    $this->actingAs($this->staff);

    foreach ([$stale, '2026-10-19 10:00:00', null] as $version) {
        $this->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => $version])
            ->assertSessionHasErrors(['conflict' => SalesOrderService::statusConflictMessage()]);
    }
    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(shipStock($this->a))->toBe(10)
        ->and(AuditLog::query()->count())->toBe(0);

    $this->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])->assertSessionHasNoErrors();
    expect(shipStock($this->a))->toBe(5);
});

test('競合: 実在しない日時の version（2026-02-30・99:99:99）では出荷しない。受注・在庫・操作履歴は変わらない', function (string $version) {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);
    $before = shipVersion($order);

    $this->actingAs($this->staff)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => $version])
        ->assertSessionHasErrors(['conflict' => SalesOrderService::statusConflictMessage()]);

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(shipVersion($order))->toBe($before)
        ->and(shipStock($this->a))->toBe(10)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['2026-02-30 10:00:00.000000', '2026-10-19 99:99:99.000000', '2026-13-01 00:00:00.000000']);

test('操作履歴の保存に失敗したら、出荷（ステータス・出庫）も取り消す', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5], [$this->b, 1]]);
    $version = shipVersion($order);
    // 出荷の操作履歴だけを失敗させる（出庫の記録は通った後で、操作履歴の INSERT の直前に失敗する）
    AuditLog::creating(function (AuditLog $log) {
        if (str_contains($log->summary, '「出荷済」')) {
            throw new RuntimeException('操作履歴の保存に失敗（テスト）');
        }
    });

    expect(fn () => app(SalesOrderService::class)->changeStatus($this->staff, $order->id, SalesOrderStatus::Shipped, $version))
        ->toThrow(RuntimeException::class, '操作履歴の保存に失敗（テスト）');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(shipVersion($order))->toBe($version)
        ->and(StockMovement::query()->where('ref_type', 'sales_order')->count())->toBe(0)
        ->and([shipStock($this->a), shipStock($this->b)])->toBe([10, 4])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('顧客・商品が無効になっても、確定済みの受注は出荷できる（出荷では顧客・商品の有効を問わない）', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);
    Partner::query()->whereKey($order->customer_id)->update(['is_active' => false]);
    Product::query()->whereKey($this->a->id)->update(['is_active' => false]);

    $this->actingAs($this->staff)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])->assertSessionHasNoErrors();

    expect(shipStock($this->a))->toBe(5);
});

test('viewer は出荷できない（403。在庫は変わらない）', function () {
    $order = shipTestOrder(SalesOrderStatus::Confirmed, [[$this->a, 5]]);

    $this->actingAs(User::factory()->create())->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => shipVersion($order)])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
        ->and(shipStock($this->a))->toBe(10);
});
