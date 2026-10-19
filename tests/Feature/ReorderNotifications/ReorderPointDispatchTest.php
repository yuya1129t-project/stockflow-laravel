<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Jobs\SendReorderPointNotification;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Orders\OrderVersion;
use App\ReorderNotifications\ReorderPointNotifier;
use App\ReorderNotifications\ReorderPointTrigger;
use App\Stock\StockChange;
use App\Stock\StockProduct;
use App\Stock\StockQuery;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\CommitsTransactions;

/**
 * 発注点割れの通知の投入（棚卸調整・受注の出荷の、StockTransaction::run の確定後。docs/spec.md §6-3）。
 * 在庫の記録は最も外側のトランザクションでしか動かないので、テストのトランザクションは張らない。外部へは送らない
 * （Queue::fake か、sync のキュー＋Http::fake）
 */
uses(CommitsTransactions::class);

const DISPATCH_TEST_WEBHOOK = 'https://hooks.slack.example.test/services/T000/B000/SECRET-TOKEN';

function dispatchTestProduct(int $stock, array $attributes = []): Product
{
    $product = Product::factory()->create(['unit' => '個', ...$attributes]);
    if ($stock > 0) {
        StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => $stock]);
    }

    return $product;
}

/** @param  list<array{0: Product, 1: int}>  $lines */
function dispatchTestSalesOrder(array $lines): SalesOrder
{
    $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed]);
    foreach ($lines as [$product, $quantity]) {
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    return $order;
}

/** @return list<array{int, int, int}> 投入した Job の商品 [ID, 変更前, 変更後] */
function dispatchedChanges(SendReorderPointNotification $job): array
{
    return array_map(fn (StockChange $c) => [$c->product->id, $c->before, $c->after], $job->changes);
}

function shipOrder(User $user, SalesOrder $order): TestResponse
{
    return test()->actingAs($user)->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => OrderVersion::of($order->fresh()?->updated_at)]);
}

beforeEach(function () {
    config(['services.reorder_notification.webhook_url' => DISPATCH_TEST_WEBHOOK]);
    $this->staff = User::factory()->staff()->create();
});

test('棚卸調整で発注点以上 → 未満に変わったら、確定後に Job を 1 回投入する（商品・前後の在庫・きっかけ・操作者）', function () {
    Queue::fake();
    $product = dispatchTestProduct(8, ['reorder_point' => 5]);

    $this->actingAs($this->staff)->post(route('stock.adjust', $product), ['actual_quantity' => '3', 'reason' => '棚卸'])
        ->assertRedirect(route('stock.show', $product))->assertSessionHasNoErrors();

    Queue::assertPushed(SendReorderPointNotification::class, 1);
    Queue::assertPushed(SendReorderPointNotification::class, fn (SendReorderPointNotification $job) => dispatchedChanges($job) === [[$product->id, 8, 3]]
        && $job->trigger == ReorderPointTrigger::stockAdjust($product->id)
        && $job->userId === $this->staff->id);
});

test('棚卸調整で発注点を跨がない・無効の商品・差分 0 なら投入しない', function (int $stock, array $attributes, string $actual) {
    Queue::fake();
    $product = dispatchTestProduct($stock, $attributes);

    $this->actingAs($this->staff)->post(route('stock.adjust', $product), ['actual_quantity' => $actual, 'reason' => '棚卸'])->assertSessionHasNoErrors();

    Queue::assertNothingPushed();
})->with([
    '発注点ちょうどまで（割れていない）' => [8, ['reorder_point' => 5], '5'],
    'もとから割れている' => [3, ['reorder_point' => 5], '1'],
    '割れから回復' => [3, ['reorder_point' => 5], '9'],
    '無効の商品' => [8, ['reorder_point' => 5, 'is_active' => false], '3'],
    '差分 0（記録しない）' => [3, ['reorder_point' => 5], '3'],
    '発注点 0（割れようがない）' => [8, ['reorder_point' => 0], '0'],
]);

test('Webhook URL が未設定（空・空白だけ）なら、跨いでも投入しない', function (?string $url) {
    Queue::fake();
    config(['services.reorder_notification.webhook_url' => $url]);
    $product = dispatchTestProduct(8, ['reorder_point' => 5]);

    $this->actingAs($this->staff)->post(route('stock.adjust', $product), ['actual_quantity' => '3', 'reason' => '棚卸'])->assertSessionHasNoErrors();

    Queue::assertNothingPushed();
})->with([null, '', '   ']);

test('受注の出荷: 跨いだ有効な商品をまとめて 1 通（1 回の投入）。もとから割れ・無効・割れない商品は含めない', function () {
    Queue::fake();
    $a = dispatchTestProduct(10, ['reorder_point' => 5]);   // 10 → 4: 割れに変わる
    $b = dispatchTestProduct(6, ['reorder_point' => 3]);    // 6 → 1: 割れに変わる
    $c = dispatchTestProduct(4, ['reorder_point' => 10]);   // 4 → 3: もとから割れている
    $d = dispatchTestProduct(9, ['reorder_point' => 5, 'is_active' => false]); // 9 → 1: 無効
    $e = dispatchTestProduct(20, ['reorder_point' => 5]);   // 20 → 19: 割れない
    $order = dispatchTestSalesOrder([[$b, 5], [$a, 6], [$c, 1], [$d, 8], [$e, 1]]);

    shipOrder($this->staff, $order)->assertRedirect(route('sales-orders.show', $order))->assertSessionHasNoErrors();

    Queue::assertPushed(SendReorderPointNotification::class, 1);
    Queue::assertPushed(SendReorderPointNotification::class, fn (SendReorderPointNotification $job) => dispatchedChanges($job) === [[$a->id, 10, 4], [$b->id, 6, 1]]
        && $job->trigger == ReorderPointTrigger::salesOrderShipped($order->id, $order->number)
        && $job->userId === $this->staff->id);
});

test('出荷以外のステータス変更（確定・取消）と、発注の入荷（在庫が増えるだけ）では投入しない', function () {
    Queue::fake();
    $product = dispatchTestProduct(0, ['reorder_point' => 5]);
    $draft = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft]);
    SalesOrderLine::factory()->create(['sales_order_id' => $draft->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]);
    $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Ordered]);
    PurchaseOrderLine::factory()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]);
    $this->actingAs($this->staff);

    $this->post(route('sales-orders.status', $draft), ['to' => 'confirmed', 'version' => OrderVersion::of($draft->fresh()?->updated_at)])->assertSessionHasNoErrors();
    $this->post(route('sales-orders.status', $draft), ['to' => 'cancelled', 'version' => OrderVersion::of($draft->fresh()?->updated_at)])->assertSessionHasNoErrors();
    $this->post(route('purchase-orders.status', $po), ['to' => 'received', 'version' => OrderVersion::of($po->fresh()?->updated_at)])->assertSessionHasNoErrors();

    expect(app(StockQuery::class)->stock($product->id))->toBe(2);
    Queue::assertNothingPushed();
});

test('トランザクションが取り消されたら投入しない（在庫不足で出荷を中止・操作履歴の保存に失敗）', function () {
    Queue::fake();
    $a = dispatchTestProduct(10, ['reorder_point' => 5]);
    $short = dispatchTestProduct(1, ['reorder_point' => 0]);
    $insufficient = dispatchTestSalesOrder([[$a, 6], [$short, 2]]);
    $auditFails = dispatchTestSalesOrder([[$a, 6]]);

    shipOrder($this->staff, $insufficient)->assertSessionHasErrors('status');
    AuditLog::creating(function (AuditLog $log) {
        if (str_contains($log->summary, '「出荷済」')) {
            throw new RuntimeException('操作履歴の保存に失敗（テスト）');
        }
    });
    shipOrder($this->staff, $auditFails)->assertServerError();

    expect(app(StockQuery::class)->stock($a->id))->toBe(10)
        ->and(StockMovement::query()->where('ref_type', 'sales_order')->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('送信は確定の後: 送る時点でトランザクションの外にいて、別の接続から出荷後の在庫・受注の状態が見える', function () {
    // sync のキュー（phpunit.xml）なので、投入した要求の中で Job が動く。別の接続は確定したものしか読めない
    config(['database.connections.reorder_observer' => config('database.connections.'.DB::getDefaultConnection())]);
    $a = dispatchTestProduct(10, ['reorder_point' => 5]);
    $order = dispatchTestSalesOrder([[$a, 6]]);
    $seen = [];
    Http::fake(function () use (&$seen, $a, $order) {
        $observer = DB::connection('reorder_observer');
        $seen[] = [
            DB::transactionLevel(),
            (int) $observer->table('stock_movements')->where('product_id', $a->id)->sum('quantity'),
            $observer->table('sales_orders')->where('id', $order->id)->value('status'),
        ];

        return Http::response('ok', 200);
    });

    shipOrder($this->staff, $order)->assertSessionHasNoErrors();

    expect($seen)->toBe([[0, 4, 'shipped']])
        ->and(AuditLog::query()->where('action', 'notify_failed')->count())->toBe(0);
    DB::purge('reorder_observer');
});

test('送信に失敗しても業務処理は取り消さず、操作履歴に notify_failed を残す（画面は成功のまま）', function () {
    Http::fake(['*' => Http::response('応答の本文 SECRET-TOKEN', 500)]);
    $product = dispatchTestProduct(8, ['reorder_point' => 5, 'code' => 'P-0042']);

    $this->actingAs($this->staff)->post(route('stock.adjust', $product), ['actual_quantity' => '3', 'reason' => '棚卸'])
        ->assertRedirect(route('stock.show', $product))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '在庫を 8 → 3 個に調整しました（差分 -5）']);

    Http::assertSentCount(1);
    expect(app(StockQuery::class)->stock($product->id))->toBe(3)
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['stock_adjust', 'notify_failed'])
        ->and(AuditLog::query()->where('action', 'notify_failed')->sole()->only(['user_id', 'target_type', 'target_id', 'summary']))->toBe([
            'user_id' => $this->staff->id,
            'target_type' => 'product',
            'target_id' => (string) $product->id,
            'summary' => '発注点割れの通知を送信できませんでした（棚卸調整・商品 P-0042）。原因: HTTP エラー（500）',
        ]);
});

test('キューへの投入に失敗しても業務処理は取り消さず、notify_failed（queue_error）を残す（例外のメッセージは残さない）', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('jobs に書けない '.DISPATCH_TEST_WEBHOOK));
    Log::spy();
    $a = dispatchTestProduct(10, ['reorder_point' => 5, 'code' => 'P-0043']);
    $order = dispatchTestSalesOrder([[$a, 6]]);

    shipOrder($this->staff, $order)->assertRedirect(route('sales-orders.show', $order))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Shipped)
        ->and(app(StockQuery::class)->stock($a->id))->toBe(4)
        ->and(AuditLog::query()->where('action', 'notify_failed')->sole()->only(['target_type', 'target_id', 'summary']))->toBe([
            'target_type' => 'sales_order',
            'target_id' => (string) $order->id,
            'summary' => "発注点割れの通知を送信できませんでした（受注 {$order->number} の出荷・商品 P-0043）。原因: キューに投入できなかった",
        ]);
    Log::shouldHaveReceived('error')->once()->with('[reorder-notification] 通知をキューに投入できませんでした: RuntimeException');
});

test('Job は確定するまでキュー（jobs テーブル）に入らない。トランザクションが取り消されたら捨てる（ShouldQueueAfterCommit）', function () {
    config(['queue.default' => 'database']);
    // 本番と同じく、キューと DB の接続に同じトランザクションの管理を使わせる（Pest.php の RefreshDatabase は、トランザクションを
    // 張らないとき（CommitsTransactions）も、コンテナにだけ別の管理を登録する。そのままだとキューから確定前かが見えない）
    app()->instance('db.transactions', $manager = new DatabaseTransactionsManager);
    DB::connection()->setTransactionManager($manager);
    $changes = [new StockChange(new StockProduct(1, 'P-0001', '商品A', '個', 5, true, 8), 8, 3)];
    $notifier = app(ReorderPointNotifier::class);

    try {
        DB::transaction(function () use ($notifier, $changes) {
            expect($notifier->notify($changes, ReorderPointTrigger::stockAdjust(1), null))->toBeTrue()
                ->and(DB::table('jobs')->count())->toBe(0);
            throw new RuntimeException('取り消し（テスト）');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('jobs')->count())->toBe(0);

    DB::transaction(function () use ($notifier, $changes) {
        $notifier->notify($changes, ReorderPointTrigger::stockAdjust(1), null);
        expect(DB::table('jobs')->count())->toBe(0);
    });
    expect(DB::table('jobs')->count())->toBe(1);

    // 投入した後の外側（トランザクションなし）ではすぐに入る。キューに Webhook URL は残さない
    $notifier->notify($changes, ReorderPointTrigger::stockAdjust(1), null);
    expect(DB::table('jobs')->count())->toBe(2)
        ->and(DB::table('jobs')->pluck('payload')->implode("\n"))->not->toContain('SECRET-TOKEN')->not->toContain('hooks.slack');
});
