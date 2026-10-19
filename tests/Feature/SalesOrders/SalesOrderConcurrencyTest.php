<?php

use App\Enums\PartnerKind;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderTransaction;
use App\Orders\OrderVersion;
use App\SalesOrders\SalesOrderService;
use App\Stock\StockQuery;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\CommitsTransactions;
use Tests\Support\StockWorker;

/**
 * 受注の同時実行（MySQL の独立した接続・別プロセスで実際に競合させる。docs/spec.md §3・§4）。発注（PurchaseOrderConcurrencyTest）と同じ作り。
 *
 * 受注・取引先・商品の操作は別プロセスのワーカー（tests/Support/sales-order-worker.php）、在庫の記録は在庫のワーカー
 * （tests/Support/stock-worker.php）で動かす。ロックを持ち続ける側は、このプロセスの別の接続（StockWorker::peer）で動かす。
 * 「後の側がロック待ちに入った」ことは performance_schema.data_lock_waits で確かめてから先の側を確定させる
 * （固定時間の待ちは証拠にしない。待ちに入らなければ上限の 10 秒でテストの失敗）。
 *
 * ワーカーはテストのトランザクションの外で書き込むので、このファイルはテストのトランザクションを張らない（CommitsTransactions。
 * テストの前後で全テーブルを空にする）。ワーカーは DB 名を testing に固定しているので、--parallel には対応しない
 */
uses(CommitsTransactions::class);

/** @param  list<array{0: Product, 1: int}>  $lines  [商品, 数量] */
function soConcurrencyInput(Partner $customer, array $lines): array
{
    return [
        'customer_id' => $customer->id,
        'due_date' => null,
        'note' => null,
        'lines' => array_map(fn (array $l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => 100], $lines),
    ];
}

function soWorker(array $payload): StockWorker
{
    return StockWorker::start($payload, 'sales-order-worker.php');
}

/** @param  list<array{0: Product, 1: int}>  $lines  明細（この順に INSERT する） */
function soConcurrencyOrder(Partner $customer, SalesOrderStatus $status, array $lines): SalesOrder
{
    $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => $status]);
    foreach ($lines as [$product, $quantity]) {
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    return $order;
}

function soConcurrencyVersion(SalesOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

function soShipPayload(int $userId, SalesOrder $order): array
{
    return ['op' => 'status', 'userId' => $userId, 'id' => $order->id, 'to' => 'shipped', 'version' => soConcurrencyVersion($order)];
}

beforeEach(function () {
    $this->userId = User::factory()->staff()->create()->id;
    $this->customer = Partner::factory()->customer()->create(['code' => 'C-0001', 'name' => '架空商店']);
    $this->p1 = Product::factory()->create(['code' => 'P-0001', 'name' => '商品1']);
    $this->p2 = Product::factory()->create(['code' => 'P-0002', 'name' => '商品2']);
});

afterEach(function () {
    // 途中で失敗したときに、ロックを持ったままのワーカー・接続が後始末を待たせないようにする
    StockWorker::stopAll();
    if (array_key_exists('so_holder', DB::getConnections())) {
        if (DB::connection('so_holder')->transactionLevel() > 0) {
            DB::connection('so_holder')->rollBack(0);
        }
        DB::purge('so_holder');
    }
});

test('採番: 同時に 2 件作成しても番号は重複しない（後の側は採番の行のロックを待つ）', function (?int $lastNumber) {
    $today = DocumentNumber::today();
    if ($lastNumber !== null) {
        DB::table('document_sequences')->insert(['kind' => 'SO', 'date' => $today, 'last_number' => $lastNumber]);
    }
    $input = soConcurrencyInput($this->customer, [[$this->p1, 1]]);

    $first = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => $input, 'hold' => true]);
    $first->waitUntilHolding();
    $second = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => $input]);
    // 行がない日は、先の側が INSERT した行の暗黙のロックを後の側が待つ（待たせている相手はトランザクションから求める: StockWorker::lockWaits）
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
    $first->release();
    $a = $first->result();
    $b = $second->result();

    $base = $lastNumber ?? 0;
    $date = str_replace('-', '', $today);
    expect([$a['outcome'], $b['outcome']])->toBe(['ok', 'ok'])
        ->and([$a['number'], $b['number']])->toBe([sprintf('SO-%s-%03d', $date, $base + 1), sprintf('SO-%s-%03d', $date, $base + 2)])
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1])
        ->and(DB::table('document_sequences')->where('kind', 'SO')->value('last_number'))->toBe($base + 2);
})->with([
    'その日の最初の受注（採番の行がない）' => [null],
    'その日の採番の行がある' => [5],
]);

describe('出荷の同時実行', function () {
    beforeEach(function () {
        StockMovement::factory()->create(['product_id' => $this->p1->id, 'type' => StockMovementType::In, 'quantity' => 5]);
        StockMovement::factory()->create(['product_id' => $this->p2->id, 'type' => StockMovementType::In, 'quantity' => 5]);
    });

    test('同じ受注を同時に 2 回出荷しても出庫は 1 回だけ（後の側は先の確定を待ち、条件付き更新が 0 件になる）', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 3], [$this->p2, 2]]);
        $payload = soShipPayload($this->userId, $order);

        $first = soWorker([...$payload, 'hold' => true]);
        $first->waitUntilHolding();
        $second = soWorker($payload);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        $stock = app(StockQuery::class);
        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($b['errors'])->toBe(['status' => 'この受注は「出荷済」のため、ステータスを変更できません'])
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Shipped)
            ->and([$stock->stock($this->p1->id), $stock->stock($this->p2->id)])->toBe([2, 3])
            ->and(DB::table('stock_movements')->where('ref_type', 'sales_order')->where('ref_id', $order->id)->count())->toBe(2);
    });

    test('別々の受注が同じ商品を同時に出荷しても在庫は負にならない（後の側は商品のロックを待ち、先の出庫を含めた在庫で不足と判定する）', function () {
        $a = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 3]]);
        $b = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 3]]);

        $versionB = soConcurrencyVersion($b);
        $first = soWorker([...soShipPayload($this->userId, $a), 'hold' => true]);
        $first->waitUntilHolding();
        $second = soWorker(soShipPayload($this->userId, $b));
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $ra = $first->result();
        $rb = $second->result();

        // 負け側（b）は、待ち合わせた後でも受注の更新（ステータス・updated_at）と操作履歴が残らない（不足の例外で全体が取り消される）
        expect($ra['outcome'])->toBe('ok')
            ->and($rb)->toMatchArray([
                'outcome' => 'insufficient',
                'shortages' => [['productId' => $this->p1->id, 'available' => 2, 'required' => 3, 'shortage' => 1]],
            ])
            ->and(app(StockQuery::class)->stock($this->p1->id))->toBe(2)
            ->and($a->fresh()->status)->toBe(SalesOrderStatus::Shipped)
            ->and($b->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
            ->and(soConcurrencyVersion($b))->toBe($versionB)
            ->and(DB::table('stock_movements')->where('ref_type', 'sales_order')->where('ref_id', $b->id)->count())->toBe(0)
            ->and(DB::table('audit_logs')->where('action', SalesOrderService::STATUS_ACTION)->pluck('target_id')->all())->toBe([(string) $a->id]);
    });

    test('出荷がデッドロックの負け側になっても、再試行（2 回目）で出荷でき、出庫・操作履歴・updated_at の更新は 1 回分だけ', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 3], [$this->p2, 2]]);
        $payload = soShipPayload($this->userId, $order);

        // 相手: 変更の多いトランザクションにしてから（InnoDB に出荷の側を取り消させる）、商品 p2 の行を押さえる
        $peer = StockWorker::peer('so_holder');
        $peer->beginTransaction();
        foreach (range(1, 20) as $i) {
            $peer->table('audit_logs')->insert(['user_id' => $this->userId, 'action' => 'so_peer', 'summary' => "相手 {$i}", 'created_at' => now()]);
        }
        $peer->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$this->p2->id]);
        $peerId = StockWorker::connectionIdOf($peer);

        // 1 回目の試行: 受注の行を更新し、商品 p1 をロックしてから、相手の持つ p2 を待つ
        $ship = soWorker($payload);
        StockWorker::waitForLockWait($ship->connectionId(), $peerId);
        // 相手が受注の行を要求すると待ちが一周する（デッドロック）。出荷の側が取り消され、相手は受注の行のロックを得る
        $peer->select('SELECT id FROM sales_orders WHERE id = ? FOR UPDATE', [$order->id]);
        // 2 回目の試行: 受注の行（相手が待ちを経て得たロック）を待つ
        StockWorker::waitForLockWait($ship->connectionId(), $peerId);
        $peer->rollBack();
        $result = $ship->result();

        $stock = app(StockQuery::class);
        expect($result['outcome'])->toBe('ok')
            ->and($result['attempts'])->toBe(2)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Shipped)
            ->and(soConcurrencyVersion($order))->not->toBe($payload['version'])
            ->and([$stock->stock($this->p1->id), $stock->stock($this->p2->id)])->toBe([2, 3])
            ->and(DB::table('stock_movements')->where('ref_type', 'sales_order')->where('ref_id', $order->id)->orderBy('product_id')->pluck('quantity')->all())->toBe([-3, -2])
            ->and(DB::table('audit_logs')->where('action', SalesOrderService::STATUS_ACTION)->where('target_id', (string) $order->id)->count())->toBe(1)
            ->and(DB::table('audit_logs')->where('action', 'so_peer')->count())->toBe(0);
    });

    test('出荷がロック待ちのタイムアウト（1205）になったら再試行せず「混み合っています」を返し、受注・在庫・操作履歴は変わらない', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 3]]);
        $version = soConcurrencyVersion($order);
        $holder = StockWorker::peer('so_holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$this->p1->id]);
        $attempts = 0;
        Event::listen(TransactionBeginning::class, function () use (&$attempts) {
            $attempts++;
        });

        // 画面からの出荷（このプロセスの接続）。在庫のトランザクションのロック待ちの上限（10 秒）で 1205 になる
        $this->actingAs(User::query()->findOrFail($this->userId))
            ->post(route('sales-orders.status', $order), ['to' => 'shipped', 'version' => $version])
            ->assertRedirect()
            ->assertSessionHasErrors(['form' => OrderTransaction::busyMessage('出荷できませんでした（在庫は変わっていません）')]);
        $holder->rollBack();

        expect($attempts)->toBe(1)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
            ->and(soConcurrencyVersion($order))->toBe($version)
            ->and(app(StockQuery::class)->stock($this->p1->id))->toBe(5)
            ->and(DB::table('stock_movements')->where('ref_type', 'sales_order')->count())->toBe(0)
            ->and(DB::table('audit_logs')->where('action', SalesOrderService::STATUS_ACTION)->count())->toBe(0);
    });

    test('明細の順番が逆の 2 受注を同時に出荷してもデッドロックしない（全明細を一度に渡し、商品は id の順にロックされる）', function () {
        // A は [p1, p2]、B は [p2, p1] の順に明細を INSERT する（出荷は明細を id の順に読む）
        $orderA = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 1], [$this->p2, 2]]);
        $orderB = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p2, 1], [$this->p1, 2]]);
        $holder = StockWorker::peer('so_holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$this->p2->id]);

        // B を先にロック待ちにする（id の順なら p1 を取って p2 を待つ。明細の順なら p2 を待つ）。その後に A を始める
        $shipB = soWorker(soShipPayload($this->userId, $orderB));
        StockWorker::waitForLockWait($shipB->connectionId(), StockWorker::connectionIdOf($holder));
        $shipA = soWorker(soShipPayload($this->userId, $orderA));
        StockWorker::waitForLockWait($shipA->connectionId());
        $holder->rollBack();
        $rb = $shipB->result();
        $ra = $shipA->result();

        // デッドロックで再試行していない（attempts が 1）
        $stock = app(StockQuery::class);
        expect([$ra['outcome'], $rb['outcome']])->toBe(['ok', 'ok'])
            ->and([$ra['attempts'], $rb['attempts']])->toBe([1, 1])
            ->and([$stock->stock($this->p1->id), $stock->stock($this->p2->id)])->toBe([2, 2])
            ->and([$orderA->fresh()->status, $orderB->fresh()->status])->toBe([SalesOrderStatus::Shipped, SalesOrderStatus::Shipped]);
    });
});

describe('取引先の種別変更と受注の作成（PR #21 のレビューで再現した競合）', function () {
    test('種別の変更（顧客 → 仕入先）が先: 作成は確定を待ってから読み直し、顧客ではないので作成しない', function () {
        $first = soWorker([
            'op' => 'partnerUpdate', 'userId' => $this->userId, 'partnerId' => $this->customer->id, 'hold' => true,
            'partner' => ['code' => 'C-0001', 'name' => '架空商店', 'kind' => 'supplier', 'postal_code' => null, 'address' => null, 'phone' => null, 'email' => null],
        ]);
        $first->waitUntilHolding();
        $second = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 1]])]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($b['errors'])->toBe(['customer_id' => 'C-0001「架空商店」は顧客ではありません（種別が「顧客」か「顧客・仕入先」の取引先を選んでください）'])
            ->and(SalesOrder::query()->count())->toBe(0)
            ->and($this->customer->fresh()->kind)->toBe(PartnerKind::Supplier);
    });

    test('作成が先: 種別の変更は作成の確定を待ってから受注を数え、顧客の役割を外す変更を拒否する', function () {
        $first = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 1]]), 'hold' => true]);
        $first->waitUntilHolding();
        $second = soWorker([
            'op' => 'partnerUpdate', 'userId' => $this->userId, 'partnerId' => $this->customer->id,
            'partner' => ['code' => 'C-0001', 'name' => '架空商店', 'kind' => 'supplier', 'postal_code' => null, 'address' => null, 'phone' => null, 'email' => null],
        ]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($b['errors'])->toBe(['kind' => '受注（1 件）で使われているため、顧客の役割を外せません（「顧客」か「顧客・仕入先」にしてください）'])
            ->and($this->customer->fresh()->kind)->toBe(PartnerKind::Customer);
    });
});

test('商品の無効化が先: 作成は確定を待ってから読み直し、無効な商品の明細は作らない', function () {
    $first = soWorker(['op' => 'productDeactivate', 'userId' => $this->userId, 'productId' => $this->p2->id, 'hold' => true]);
    $first->waitUntilHolding();
    $second = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 1], [$this->p2, 1]])]);
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
    $first->release();
    $a = $first->result();
    $b = $second->result();

    expect($a['outcome'])->toBe('ok')
        ->and($b['outcome'])->toBe('invalid')
        ->and($b['errors'])->toBe(['lines.1.product_id' => 'P-0002「商品2」は無効になっているため選べません'])
        ->and(SalesOrder::query()->count())->toBe(0);
});

test('作成と在庫の記録が同じ 2 商品で重なってもデッドロックしない（どちらも商品を id の順にロックする）', function () {
    $holder = StockWorker::peer('so_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$this->p2->id]);

    // 作成: p1 を FOR SHARE で取り、p2 を待つ。明細は id の逆の順（p2, p1）で渡す
    $create = soWorker(['op' => 'create', 'userId' => $this->userId, 'input' => soConcurrencyInput($this->customer, [[$this->p2, 1], [$this->p1, 1]])]);
    StockWorker::waitForLockWait($create->connectionId(), StockWorker::connectionIdOf($holder));
    // 在庫の記録: p1 を FOR UPDATE で取りに行き、作成の共有ロックを待つ
    $record = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $this->p2->id, 'type' => 'in', 'quantity' => 3], ['productId' => $this->p1->id, 'type' => 'in', 'quantity' => 4],
    ]]);
    StockWorker::waitForLockWait($record->connectionId(), $create->connectionId());
    $holder->rollBack();
    $a = $create->result();
    $b = $record->result();

    expect([$a['outcome'], $b['outcome']])->toBe(['ok', 'ok'])
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1]);
});

describe('ステータス変更・編集が受注の行のロックで順番待ちになる', function () {
    // 先の側は確定の直前（受注の行のロックを持ったまま）で止め、後の側がその行を待っていることを確かめてから確定させる。
    // 2 本がそろって 3 本目のロックを待つ形にはしない（放されたロックをどちらが先に得るかは InnoDB が決め、保証がない）

    test('出荷と取消が同時: 先の出荷が確定し、取消は出荷済のため変更できない（在庫は 1 回分）', function () {
        StockMovement::factory()->create(['product_id' => $this->p1->id, 'type' => StockMovementType::In, 'quantity' => 5]);
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Confirmed, [[$this->p1, 5]]);
        $version = soConcurrencyVersion($order);

        $ship = soWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'shipped', 'version' => $version, 'hold' => true]);
        $ship->waitUntilHolding();
        $cancel = soWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'cancelled', 'version' => $version]);
        StockWorker::waitForLockWait($cancel->connectionId(), $ship->connectionId());
        $ship->release();
        $a = $ship->result();
        $b = $cancel->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['status' => 'この受注は「出荷済」のため、ステータスを変更できません']])
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Shipped)
            ->and(app(StockQuery::class)->stock($this->p1->id))->toBe(0);
    });

    test('明細を 0 行にする編集が先・確定への変更が後: 明細 0 行の確定はできない', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Draft, [[$this->p1, 5]]);
        $version = soConcurrencyVersion($order);

        $edit = soWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => soConcurrencyInput($this->customer, []), 'version' => $version, 'hold' => true]);
        $edit->waitUntilHolding();
        $toConfirmed = soWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'confirmed', 'version' => $version]);
        StockWorker::waitForLockWait($toConfirmed->connectionId(), $edit->connectionId());
        $edit->release();
        $a = $edit->result();
        $b = $toConfirmed->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['status' => '明細が 1 行もないため、確定にできません']])
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Draft)
            ->and(SalesOrderLine::query()->where('sales_order_id', $order->id)->count())->toBe(0);
    });

    test('確定への変更が先・編集が後: 編集は確定のため保存せず、明細は元のまま', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Draft, [[$this->p1, 5]]);
        $version = soConcurrencyVersion($order);

        $toConfirmed = soWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'confirmed', 'version' => $version, 'hold' => true]);
        $toConfirmed->waitUntilHolding();
        $edit = soWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 9]]), 'version' => $version]);
        StockWorker::waitForLockWait($edit->connectionId(), $toConfirmed->connectionId());
        $toConfirmed->release();
        $a = $toConfirmed->result();
        $b = $edit->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['form' => 'この受注は「確定」のため編集できません（編集できるのは下書きだけです）']])
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed)
            ->and(SalesOrderLine::query()->where('sales_order_id', $order->id)->pluck('quantity')->all())->toBe([5]);
    });

    test('編集が同時に 2 本: 後の側は先の確定を待ち、updated_at が変わったので競合になる', function () {
        $order = soConcurrencyOrder($this->customer, SalesOrderStatus::Draft, [[$this->p1, 5]]);
        $version = soConcurrencyVersion($order);

        $first = soWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 6]]), 'version' => $version, 'hold' => true]);
        $first->waitUntilHolding();
        $second = soWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => soConcurrencyInput($this->customer, [[$this->p1, 7]]), 'version' => $version]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['conflict' => SalesOrderService::conflictMessage()]])
            ->and(SalesOrderLine::query()->where('sales_order_id', $order->id)->pluck('quantity')->all())->toBe([6]);
    });
});
