<?php

use App\Stock\StockAdjustment;
use App\Stock\StockTransaction;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\StockWorker;

/**
 * 在庫の記録の同時実行（MySQL の独立した接続で実際に競合させる）。
 *
 * 記録・棚卸調整は別プロセスのワーカー（tests/Support/stock-worker.php）で動かす。ロックを持ち続ける側・明細を INSERT する側は、
 * このプロセスの別の接続（StockWorker::peer）で動かす。「後の側がロック待ちに入った」ことは performance_schema.data_lock_waits で
 * 確かめてから先の側を確定させる（固定時間の待ちは証拠にしない。待ちに入らなければ上限の 10 秒でテストの失敗）。
 *
 * ワーカーはテストのトランザクション（RefreshDatabase）の外で書き込むので、準備・確認・後始末は自動で確定する別の接続
 * （StockWorker::db）で行い、このテストのデータ（商品コード SC-・メール @stock-concurrency.test・受注番号 SO-SC-）は前後で消す
 */
function cleanUpStockConcurrencyData(): void
{
    $db = StockWorker::db();
    $productIds = $db->table('products')->where('code', 'like', 'SC-%')->pluck('id')->all();
    $userIds = $db->table('users')->where('email', 'like', '%@stock-concurrency.test')->pluck('id')->all();
    $db->table('stock_movements')->whereIn('product_id', $productIds)->delete();
    $db->table('audit_logs')->whereIn('user_id', $userIds)->delete();
    $db->table('sales_orders')->where('number', 'like', 'SO-SC-%')->delete(); // 明細は cascade で消える
    $db->table('partners')->where('code', 'C-SC01')->delete();
    $db->table('products')->whereIn('id', $productIds)->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
}

/** 商品を作り、在庫を入れる（入庫 1 件）。id は作った順に大きくなる */
function createStockConcurrencyProduct(string $code, int $stock, int $reorderPoint = 0): int
{
    $db = StockWorker::db();
    $id = $db->table('products')->insertGetId([
        'code' => $code, 'name' => "同時実行 {$code}", 'category' => 'テスト', 'unit' => '個',
        'sales_price' => 100, 'purchase_price' => 50, 'reorder_point' => $reorderPoint, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($stock > 0) {
        $db->table('stock_movements')->insert(['product_id' => $id, 'type' => 'in', 'quantity' => $stock, 'created_at' => now()]);
    }

    return $id;
}

function stockConcurrencyStock(int $productId): int
{
    return (int) StockWorker::db()->table('stock_movements')->where('product_id', $productId)->sum('quantity');
}

/** 受注の見出しと明細を 1 行 INSERT する（明細の INSERT の外部キー検査が products の行に共有ロックを取る） */
function insertStockConcurrencyOrder(Connection $connection, int $partnerId): int
{
    return (int) $connection->table('sales_orders')->insertGetId([
        'number' => 'SO-SC-'.bin2hex(random_bytes(3)), 'customer_id' => $partnerId, 'order_date' => now()->toDateString(),
        'status' => 'draft', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function insertStockConcurrencyLine(Connection $connection, int $orderId, int $productId): void
{
    $connection->table('sales_order_lines')->insert(['sales_order_id' => $orderId, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => 100]);
}

beforeEach(function () {
    cleanUpStockConcurrencyData();
    $this->userId = StockWorker::db()->table('users')->insertGetId([
        'name' => '同時実行', 'email' => 'stock@stock-concurrency.test', 'password' => 'x', 'role' => 'staff', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    // 途中で失敗したときに、ロックを持ったままのワーカー・接続が後始末を待たせないようにする
    StockWorker::stopAll();
    foreach (['stock_holder', 'stock_peer'] as $name) {
        if (array_key_exists($name, DB::getConnections())) {
            if (DB::connection($name)->transactionLevel() > 0) {
                DB::connection($name)->rollBack(0);
            }
            DB::purge($name);
        }
    }
    cleanUpStockConcurrencyData();
    DB::purge('stock_concurrency');
});

test('同じ商品を 2 つの接続から同時に出庫しても在庫は負にならない（後の側はロックを待ち、先の出庫を含めた在庫で判定する）', function () {
    $p = createStockConcurrencyProduct('SC-0001', 5);

    // 1 つ目: 出庫 3 を記録し、確定の直前でロックを持ったまま止まる
    $first = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [['productId' => $p, 'type' => 'out', 'quantity' => -3]], 'hold' => true]);
    $first->waitUntilHolding();
    // 2 つ目: 先に普通の SELECT をしてから（伝票を読んでから記録する形）出庫 3 を記録する。1 つ目のロックを待つことを確かめる
    $second = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [['productId' => $p, 'type' => 'out', 'quantity' => -3]], 'readFirst' => true]);
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());

    $first->release();
    $a = $first->result();
    $b = $second->result();

    expect($a['outcome'])->toBe('ok')
        ->and($a['changes'])->toBe([['productId' => $p, 'before' => 5, 'after' => 2]])
        ->and($b['outcome'])->toBe('insufficient')
        ->and($b['shortages'])->toBe([['productId' => $p, 'available' => 2, 'required' => 3, 'shortage' => 1]])
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1])
        ->and(stockConcurrencyStock($p))->toBe(2);
});

test('4 つの接続が同じ商品の出庫でロックの順番待ちになっても、在庫の範囲で記録できた分だけ記録される', function () {
    $p = createStockConcurrencyProduct('SC-0001', 5);
    $holder = StockWorker::peer('stock_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$p]);

    $workers = [];
    foreach (range(1, 4) as $i) {
        $workers[] = $worker = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [['productId' => $p, 'type' => 'out', 'quantity' => -2]]]);
        StockWorker::waitForLockWait($worker->connectionId());
    }
    $holder->rollBack();

    $outcomes = array_map(fn (StockWorker $w) => $w->result()['outcome'], $workers);
    sort($outcomes);
    expect($outcomes)->toBe(['insufficient', 'insufficient', 'ok', 'ok'])
        ->and(stockConcurrencyStock($p))->toBe(1);
});

test('複数の商品を逆の順番で同時に記録してもデッドロックしない（ロックは商品 id の順に取る）', function () {
    $p1 = createStockConcurrencyProduct('SC-0001', 10);
    $p2 = createStockConcurrencyProduct('SC-0002', 10);
    expect($p1)->toBeLessThan($p2);

    // p2 を別の接続で押さえておき、1 つ目（p2, p1 の順に指定）が p1 を取ってから p2 を待つ状態を作る
    $holder = StockWorker::peer('stock_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$p2]);
    $holderId = StockWorker::connectionIdOf($holder);

    $first = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $p2, 'type' => 'out', 'quantity' => -1], ['productId' => $p1, 'type' => 'out', 'quantity' => -1],
    ]]);
    StockWorker::waitForLockWait($first->connectionId(), $holderId);
    // 2 つ目（p1, p2 の順に指定）は、1 つ目が先に取った p1 を待つ（id の順に取るので、1 つ目は p2 より先に p1 を持っている）
    $second = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $p1, 'type' => 'out', 'quantity' => -2], ['productId' => $p2, 'type' => 'out', 'quantity' => -2],
    ]]);
    StockWorker::waitForLockWait($second->connectionId(), $first->connectionId());

    $holder->rollBack();
    $a = $first->result();
    $b = $second->result();

    expect([$a['outcome'], $b['outcome']])->toBe(['ok', 'ok'])
        // デッドロックからの再試行もしていない
        ->and([$a['attempts'], $b['attempts']])->toBe([1, 1])
        ->and([stockConcurrencyStock($p1), stockConcurrencyStock($p2)])->toBe([7, 7]);
});

test('出庫と同時の棚卸調整は、出庫の確定を待ってから差分を求める（記録後の在庫は入力した実数になる）', function () {
    $p = createStockConcurrencyProduct('SC-0001', 10, reorderPoint: 8);

    $out = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [['productId' => $p, 'type' => 'out', 'quantity' => -4]], 'hold' => true]);
    $out->waitUntilHolding();
    $adjust = StockWorker::start(['op' => 'adjust', 'userId' => $this->userId, 'productId' => $p, 'actual' => 7, 'reason' => '月末棚卸']);
    StockWorker::waitForLockWait($adjust->connectionId(), $out->connectionId());

    $out->release();
    $a = $out->result();
    $b = $adjust->result();

    expect($a['outcome'])->toBe('ok')
        // 出庫で 10 → 6 になり、発注点 8 を割った
        ->and($a['fellBelowReorderPoint'])->toBe([['productId' => $p, 'before' => 10, 'after' => 6]])
        ->and($b['outcome'])->toBe('ok')
        // 差分は出庫の後の在庫（6）から求める（画面を開いたときの 10 からではない）
        ->and($b['adjust'])->toBe(['recorded' => true, 'before' => 6, 'after' => 7, 'delta' => 1, 'fellBelowReorderPoint' => []])
        ->and(stockConcurrencyStock($p))->toBe(7)
        ->and(StockWorker::db()->table('audit_logs')->where('user_id', $this->userId)->where('action', StockAdjustment::ACTION)->value('summary'))
        ->toBe('商品 SC-0001 の在庫を棚卸で調整しました（6 → 7個、差分 +1、理由: 月末棚卸）');
});

test('明細の INSERT（外部キー検査の共有ロック）と在庫の記録が逆の順番で交差すると InnoDB はデッドロックにするが、在庫の側は再試行して記録できる', function () {
    expect(StockTransaction::ATTEMPTS)->toBeGreaterThan(1);
    $p1 = createStockConcurrencyProduct('SC-0001', 10);
    $p2 = createStockConcurrencyProduct('SC-0002', 10);
    $partnerId = StockWorker::db()->table('partners')->insertGetId([
        'code' => 'C-SC01', 'name' => '同時実行商事', 'kind' => 'customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // 受注の作成（明細を入力の順番 p2 → p1 で INSERT する。商品を先にロックしない書き方）
    $order = StockWorker::peer('stock_peer');
    $order->beginTransaction();
    $orderId = insertStockConcurrencyOrder($order, $partnerId);
    insertStockConcurrencyLine($order, $orderId, $p2); // p2 に共有ロック
    // 在庫の記録は p1 を排他ロックしてから、p2 の共有ロックを待つ
    $stock = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $p1, 'type' => 'out', 'quantity' => -1], ['productId' => $p2, 'type' => 'out', 'quantity' => -1],
    ]]);
    StockWorker::waitForLockWait($stock->connectionId(), StockWorker::connectionIdOf($order));

    // 受注が p1 の明細を INSERT すると、待ちが一周する（デッドロック）。InnoDB は変更の少ない在庫の側を取り消す
    insertStockConcurrencyLine($order, $orderId, $p1);
    // 在庫の側は再試行し、今度は受注の確定を待つ
    StockWorker::waitForLockWait($stock->connectionId(), StockWorker::connectionIdOf($order));
    $order->commit();
    $result = $stock->result();

    expect($result['outcome'])->toBe('ok')
        ->and($result['attempts'])->toBe(2)
        // 再試行で二重に記録していない
        ->and([stockConcurrencyStock($p1), stockConcurrencyStock($p2)])->toBe([9, 9])
        ->and(StockWorker::db()->table('sales_order_lines')->where('sales_order_id', $orderId)->count())->toBe(2);
});

test('明細を書く側が先に明細の全商品を id の順に FOR SHARE でロックすれば、在庫の記録と交差してもデッドロックしない（#7・#8 の書き方）', function () {
    $p1 = createStockConcurrencyProduct('SC-0001', 10);
    $p2 = createStockConcurrencyProduct('SC-0002', 10);
    $partnerId = StockWorker::db()->table('partners')->insertGetId([
        'code' => 'C-SC01', 'name' => '同時実行商事', 'kind' => 'customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = StockWorker::peer('stock_peer');
    $order->beginTransaction();
    $order->select('SELECT id FROM products WHERE id IN (?, ?) ORDER BY id FOR SHARE', [$p1, $p2]);
    $orderId = insertStockConcurrencyOrder($order, $partnerId);
    insertStockConcurrencyLine($order, $orderId, $p2);
    $stock = StockWorker::start(['op' => 'record', 'userId' => $this->userId, 'entries' => [
        ['productId' => $p1, 'type' => 'out', 'quantity' => -1], ['productId' => $p2, 'type' => 'out', 'quantity' => -1],
    ]]);
    StockWorker::waitForLockWait($stock->connectionId(), StockWorker::connectionIdOf($order));

    // 共有ロックはもう持っているので、明細を入力の順番で INSERT しても待たない
    insertStockConcurrencyLine($order, $orderId, $p1);
    StockWorker::waitForLockWait($stock->connectionId(), StockWorker::connectionIdOf($order));
    $order->commit();
    $result = $stock->result();

    expect($result['outcome'])->toBe('ok')
        ->and($result['attempts'])->toBe(1)
        ->and([stockConcurrencyStock($p1), stockConcurrencyStock($p2)])->toBe([9, 9]);
});
