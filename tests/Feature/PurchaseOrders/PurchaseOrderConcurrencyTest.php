<?php

use App\Enums\PartnerKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderVersion;
use App\PurchaseOrders\PurchaseOrderService;
use App\Stock\StockQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsTransactions;
use Tests\Support\StockWorker;

/**
 * 発注の同時実行（MySQL の独立した接続・別プロセスで実際に競合させる。docs/spec.md §3・§4）。
 *
 * 発注・取引先・商品の操作は別プロセスのワーカー（tests/Support/purchase-order-worker.php）、在庫の記録は在庫のワーカー
 * （tests/Support/stock-worker.php）で動かす。ロックを持ち続ける側は、このプロセスの別の接続（StockWorker::peer）で動かす。
 * 「後の側がロック待ちに入った」ことは performance_schema.data_lock_waits で確かめてから先の側を確定させる
 * （固定時間の待ちは証拠にしない。待ちに入らなければ上限の 10 秒でテストの失敗）。
 *
 * ワーカーはテストのトランザクションの外で書き込むので、このファイルはテストのトランザクションを張らない（CommitsTransactions。
 * テストの前後で全テーブルを空にする）。ワーカーは DB 名を testing に固定しているので、--parallel には対応しない
 */
uses(CommitsTransactions::class);

/** @param  list<array{0: Product, 1: int}>  $lines  [商品, 数量] */
function concurrencyInput(Partner $supplier, array $lines): array
{
    return [
        'supplier_id' => $supplier->id,
        'expected_date' => null,
        'note' => null,
        'lines' => array_map(fn (array $l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'unit_price' => 100], $lines),
    ];
}

function poWorker(array $payload): StockWorker
{
    return StockWorker::start($payload, 'purchase-order-worker.php');
}

/** @param  list<array{0: Product, 1: int}>  $lines */
function concurrencyOrder(Partner $supplier, PurchaseOrderStatus $status, array $lines): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'status' => $status]);
    foreach ($lines as [$product, $quantity]) {
        PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 100]);
    }

    return $order;
}

function concurrencyVersion(PurchaseOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

beforeEach(function () {
    $this->userId = User::factory()->staff()->create()->id;
    $this->supplier = Partner::factory()->supplier()->create(['code' => 'S-0001', 'name' => '架空商事']);
    $this->p1 = Product::factory()->create(['code' => 'P-0001', 'name' => '商品1']);
    $this->p2 = Product::factory()->create(['code' => 'P-0002', 'name' => '商品2']);
});

afterEach(function () {
    // 途中で失敗したときに、ロックを持ったままのワーカー・接続が後始末を待たせないようにする
    StockWorker::stopAll();
    if (array_key_exists('po_holder', DB::getConnections())) {
        if (DB::connection('po_holder')->transactionLevel() > 0) {
            DB::connection('po_holder')->rollBack(0);
        }
        DB::purge('po_holder');
    }
});

test('採番: 同時に 2 件作成しても番号は重複しない（後の側は採番の行のロックを待つ）', function (?int $lastNumber) {
    $today = DocumentNumber::today();
    if ($lastNumber !== null) {
        DB::table('document_sequences')->insert(['kind' => 'PO', 'date' => $today, 'last_number' => $lastNumber]);
    }
    $input = concurrencyInput($this->supplier, [[$this->p1, 1]]);

    $first = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => $input, 'hold' => true]);
    $first->waitUntilHolding();
    $second = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => $input]);
    // 行がない日は、先の側が INSERT した行の暗黙のロックを後の側が待つ（待たせている相手はトランザクションから求める: StockWorker::lockWaits）
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
    $first->release();
    $a = $first->result();
    $b = $second->result();

    $base = $lastNumber ?? 0;
    $date = str_replace('-', '', $today);
    expect([$a['outcome'], $b['outcome']])->toBe(['ok', 'ok'])
        ->and([$a['number'], $b['number']])->toBe([sprintf('PO-%s-%03d', $date, $base + 1), sprintf('PO-%s-%03d', $date, $base + 2)])
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1])
        ->and(DB::table('document_sequences')->where('kind', 'PO')->value('last_number'))->toBe($base + 2);
})->with([
    'その日の最初の発注（採番の行がない）' => [null],
    'その日の採番の行がある' => [5],
]);

test('入荷: 同時に 2 回入荷しても入庫は 1 回だけ（後の側は先の確定を待ち、条件付き更新が 0 件になる）', function () {
    $order = concurrencyOrder($this->supplier, PurchaseOrderStatus::Ordered, [[$this->p1, 5], [$this->p2, 2]]);
    $version = concurrencyVersion($order);
    $payload = ['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'received', 'version' => $version];

    $first = poWorker([...$payload, 'hold' => true]);
    $first->waitUntilHolding();
    $second = poWorker($payload);
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
    $first->release();
    $a = $first->result();
    $b = $second->result();

    $stock = app(StockQuery::class);
    expect($a['outcome'])->toBe('ok')
        ->and($b['outcome'])->toBe('invalid')
        ->and($b['errors'])->toBe(['status' => 'この発注は「入荷済」のため、ステータスを変更できません'])
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and([$stock->stock($this->p1->id), $stock->stock($this->p2->id)])->toBe([5, 2])
        ->and(DB::table('stock_movements')->where('ref_type', 'purchase_order')->where('ref_id', $order->id)->count())->toBe(2);
});

describe('取引先の種別変更と発注の作成（PR #21 のレビューで再現した競合）', function () {
    test('種別の変更（仕入先 → 顧客）が先: 作成は確定を待ってから読み直し、仕入先ではないので作成しない', function () {
        $first = poWorker([
            'op' => 'partnerUpdate', 'userId' => $this->userId, 'partnerId' => $this->supplier->id, 'hold' => true,
            'partner' => ['code' => 'S-0001', 'name' => '架空商事', 'kind' => 'customer', 'postal_code' => null, 'address' => null, 'phone' => null, 'email' => null],
        ]);
        $first->waitUntilHolding();
        $second = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => concurrencyInput($this->supplier, [[$this->p1, 1]])]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($b['errors'])->toBe(['supplier_id' => 'S-0001「架空商事」は仕入先ではありません（種別が「仕入先」か「顧客・仕入先」の取引先を選んでください）'])
            ->and(PurchaseOrder::query()->count())->toBe(0)
            ->and($this->supplier->fresh()->kind)->toBe(PartnerKind::Customer);
    });

    test('作成が先: 種別の変更は作成の確定を待ってから発注を数え、仕入先の役割を外す変更を拒否する', function () {
        $first = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => concurrencyInput($this->supplier, [[$this->p1, 1]]), 'hold' => true]);
        $first->waitUntilHolding();
        $second = poWorker([
            'op' => 'partnerUpdate', 'userId' => $this->userId, 'partnerId' => $this->supplier->id,
            'partner' => ['code' => 'S-0001', 'name' => '架空商事', 'kind' => 'customer', 'postal_code' => null, 'address' => null, 'phone' => null, 'email' => null],
        ]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($b['errors'])->toBe(['kind' => '発注（1 件）で使われているため、仕入先の役割を外せません（「仕入先」か「顧客・仕入先」にしてください）'])
            ->and($this->supplier->fresh()->kind)->toBe(PartnerKind::Supplier);
    });
});

test('商品の無効化が先: 作成は確定を待ってから読み直し、無効な商品の明細は作らない', function () {
    $first = poWorker(['op' => 'productDeactivate', 'userId' => $this->userId, 'productId' => $this->p2->id, 'hold' => true]);
    $first->waitUntilHolding();
    $second = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => concurrencyInput($this->supplier, [[$this->p1, 1], [$this->p2, 1]])]);
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
    $first->release();
    $a = $first->result();
    $b = $second->result();

    expect($a['outcome'])->toBe('ok')
        ->and($b['outcome'])->toBe('invalid')
        ->and($b['errors'])->toBe(['lines.1.product_id' => 'P-0002「商品2」は無効になっているため選べません'])
        ->and(PurchaseOrder::query()->count())->toBe(0);
});

test('作成と在庫の記録が同じ 2 商品で重なってもデッドロックしない（どちらも商品を id の順にロックする）', function () {
    $holder = StockWorker::peer('po_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$this->p2->id]);

    // 作成: p1 を FOR SHARE で取り、p2 を待つ。明細は id の逆の順（p2, p1）で渡す
    $create = poWorker(['op' => 'create', 'userId' => $this->userId, 'input' => concurrencyInput($this->supplier, [[$this->p2, 1], [$this->p1, 1]])]);
    StockWorker::waitForLockWait($create->connectionId(), StockWorker::connectionIdOf($holder));
    // 在庫の記録: p1 を FOR UPDATE で取りに行き、作成の共有ロックを待つ
    $record = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $this->p2->id, 'type' => 'in', 'quantity' => 3], ['productId' => $this->p1->id, 'type' => 'in', 'quantity' => 4],
    ]]);
    StockWorker::waitForLockWait($record->connectionId(), $create->connectionId());
    $holder->rollBack();
    $a = $create->result();
    $b = $record->result();

    // デッドロックで再試行していない（attempts が 1）
    expect([$a['outcome'], $b['outcome']])->toBe(['ok', 'ok'])
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1]);
});

describe('ステータス変更・編集が発注の行のロックで順番待ちになる', function () {
    // 先の側は確定の直前（発注の行のロックを持ったまま）で止め、後の側がその行を待っていることを確かめてから確定させる。
    // 2 本がそろって 3 本目のロックを待つ形にはしない（放されたロックをどちらが先に得るかは InnoDB が決め、保証がない）

    test('入荷と取消が同時: 先の入荷が確定し、取消は入荷済のため変更できない（在庫は 1 回分）', function () {
        $order = concurrencyOrder($this->supplier, PurchaseOrderStatus::Ordered, [[$this->p1, 5]]);
        $version = concurrencyVersion($order);

        $receive = poWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'received', 'version' => $version, 'hold' => true]);
        $receive->waitUntilHolding();
        $cancel = poWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'cancelled', 'version' => $version]);
        StockWorker::waitForLockWait($cancel->connectionId(), $receive->connectionId());
        $receive->release();
        $a = $receive->result();
        $b = $cancel->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['status' => 'この発注は「入荷済」のため、ステータスを変更できません']])
            ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Received)
            ->and(app(StockQuery::class)->stock($this->p1->id))->toBe(5);
    });

    test('明細を 0 行にする編集が先・発注済への変更が後: 明細 0 行の発注済はできない', function () {
        $order = concurrencyOrder($this->supplier, PurchaseOrderStatus::Draft, [[$this->p1, 5]]);
        $version = concurrencyVersion($order);

        $edit = poWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => concurrencyInput($this->supplier, []), 'version' => $version, 'hold' => true]);
        $edit->waitUntilHolding();
        $toOrdered = poWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'ordered', 'version' => $version]);
        StockWorker::waitForLockWait($toOrdered->connectionId(), $edit->connectionId());
        $edit->release();
        $a = $edit->result();
        $b = $toOrdered->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b['outcome'])->toBe('invalid')
            ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
            ->and(PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->count())->toBe(0);
    });

    test('発注済への変更が先・編集が後: 編集は発注済のため保存せず、明細は元のまま', function () {
        $order = concurrencyOrder($this->supplier, PurchaseOrderStatus::Draft, [[$this->p1, 5]]);
        $version = concurrencyVersion($order);

        $toOrdered = poWorker(['op' => 'status', 'userId' => $this->userId, 'id' => $order->id, 'to' => 'ordered', 'version' => $version, 'hold' => true]);
        $toOrdered->waitUntilHolding();
        $edit = poWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => concurrencyInput($this->supplier, [[$this->p1, 9]]), 'version' => $version]);
        StockWorker::waitForLockWait($edit->connectionId(), $toOrdered->connectionId());
        $toOrdered->release();
        $a = $toOrdered->result();
        $b = $edit->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['form' => 'この発注は「発注済」のため編集できません（編集できるのは下書きだけです）']])
            ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Ordered)
            ->and(PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->pluck('quantity')->all())->toBe([5]);
    });

    test('編集が同時に 2 本: 後の側は先の確定を待ち、updated_at が変わったので競合になる', function () {
        $order = concurrencyOrder($this->supplier, PurchaseOrderStatus::Draft, [[$this->p1, 5]]);
        $version = concurrencyVersion($order);

        $first = poWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => concurrencyInput($this->supplier, [[$this->p1, 6]]), 'version' => $version, 'hold' => true]);
        $first->waitUntilHolding();
        $second = poWorker(['op' => 'update', 'userId' => $this->userId, 'id' => $order->id, 'input' => concurrencyInput($this->supplier, [[$this->p1, 7]]), 'version' => $version]);
        StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());
        $first->release();
        $a = $first->result();
        $b = $second->result();

        expect($a['outcome'])->toBe('ok')
            ->and($b)->toMatchArray(['outcome' => 'invalid', 'errors' => ['conflict' => PurchaseOrderService::conflictMessage()]])
            ->and(PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->pluck('quantity')->all())->toBe([6]);
    });
});
