<?php

use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Stock\InsufficientStockException;
use App\Stock\StockAdjustment;
use App\Stock\StockChange;
use App\Stock\StockEntry;
use App\Stock\StockQuery;
use App\Stock\StockTransaction;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CommitsTransactions;
use Tests\Support\StockWorker;

/**
 * 在庫の共通サービス（StockTransaction・StockQuery）。同時実行は StockConcurrencyTest.php で、別々の接続で確かめる。
 * StockTransaction::run は最も外側のトランザクションとしてだけ動くので、テストのトランザクション（RefreshDatabase）は張らず、
 * 実際に確定させてテストの後で全テーブルを空にする（CommitsTransactions）
 */
uses(CommitsTransactions::class);

afterEach(function () {
    if (array_key_exists('stock_holder', DB::getConnections())) {
        if (DB::connection('stock_holder')->transactionLevel() > 0) {
            DB::connection('stock_holder')->rollBack(0);
        }
        DB::purge('stock_holder');
    }
});

function stockProduct(int $stock, array $attributes = []): Product
{
    $product = Product::factory()->create($attributes);
    if ($stock > 0) {
        StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => $stock]);
    }

    return $product;
}

function currentStock(Product $product): int
{
    return (new StockQuery)->stock($product->id);
}

test('現在庫: 複数の商品を 1 本の SQL で集計する（入出庫のない商品・存在しない商品は 0）', function () {
    $a = stockProduct(10);
    StockMovement::factory()->create(['product_id' => $a->id, 'type' => StockMovementType::Out, 'quantity' => -3]);
    $b = stockProduct(0);

    DB::enableQueryLog();
    $stocks = (new StockQuery)->stocks([$a->id, $b->id, 999_999, $a->id]);

    expect($stocks)->toBe([$a->id => 7, $b->id => 0, 999_999 => 0])
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and((new StockQuery)->stocks([]))->toBe([])
        ->and(currentStock($a))->toBe(7);
});

test('複数の商品をまとめて記録し、前後の在庫を返す（参照元・理由・記録したユーザーも保存する）', function () {
    $user = User::factory()->staff()->create();
    $a = stockProduct(10);
    $b = stockProduct(3);

    $result = StockTransaction::run(fn (StockTransaction $stock) => $stock->record($user->id, [
        new StockEntry($b->id, StockMovementType::In, 5, refType: StockRefType::PurchaseOrder, refId: 42),
        new StockEntry($a->id, StockMovementType::Out, -4, '出荷', StockRefType::SalesOrder, 7),
        new StockEntry($a->id, StockMovementType::Out, -2, '出荷', StockRefType::SalesOrder, 7),
    ]));

    expect(array_map(fn (StockChange $c) => [$c->product->id, $c->before, $c->after], $result->changes))
        ->toBe([[$a->id, 10, 4], [$b->id, 3, 8]])
        ->and(currentStock($a))->toBe(4)
        ->and(currentStock($b))->toBe(8);
    $movement = StockMovement::query()->where('product_id', $b->id)->latest('id')->firstOrFail();
    expect($movement->type)->toBe(StockMovementType::In)
        ->and($movement->ref_type)->toBe(StockRefType::PurchaseOrder)
        ->and($movement->ref_id)->toBe(42)
        ->and($movement->user_id)->toBe($user->id)
        ->and(StockMovement::query()->where('product_id', $a->id)->where('reason', '出荷')->count())->toBe(2);
});

test('1 商品でも不足すれば例外で全体を中止し、同じトランザクションのほかの書き込みも取り消される', function () {
    $user = User::factory()->create();
    $a = stockProduct(10, ['code' => 'P-0001', 'name' => 'ペン', 'unit' => '本']);
    $b = stockProduct(2, ['code' => 'P-0002', 'name' => 'ノート', 'unit' => '冊']);
    $movements = StockMovement::query()->count();

    try {
        StockTransaction::run(function (StockTransaction $stock) use ($user, $a, $b) {
            AuditLog::create(['user_id' => $user->id, 'action' => 'test', 'summary' => '伝票の更新（取り消されるはず）']);

            return $stock->record($user->id, [
                new StockEntry($a->id, StockMovementType::Out, -5),
                new StockEntry($b->id, StockMovementType::Out, -3),
            ]);
        });
        $this->fail('InsufficientStockException が投げられませんでした');
    } catch (InsufficientStockException $e) {
        expect($e->getMessage())->toBe('在庫が足りません: P-0002「ノート」（必要 3冊・在庫 2冊・不足 1冊）')
            ->and($e->shortages)->toHaveCount(1)
            ->and($e->shortages[0]->product->id)->toBe($b->id)
            ->and([$e->shortages[0]->available, $e->shortages[0]->required, $e->shortages[0]->shortage])->toBe([2, 3, 1]);
    }

    expect(StockMovement::query()->count())->toBe($movements)
        ->and(AuditLog::query()->where('action', 'test')->exists())->toBeFalse()
        ->and(currentStock($a))->toBe(10);
});

test('存在しない商品の指定はプログラムの誤りとして例外（何も記録しない）', function () {
    $a = stockProduct(10);
    $movements = StockMovement::query()->count();

    expect(fn () => StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, [
        new StockEntry($a->id, StockMovementType::Out, -1),
        new StockEntry(999_999, StockMovementType::In, 1),
    ])))->toThrow(InvalidArgumentException::class, '商品が見つかりません: 999999')
        ->and(StockMovement::query()->count())->toBe($movements);
});

test('発注点以上 → 発注点未満に変わった有効な商品を返す（もともと割れている商品・無効な商品は含めない）', function () {
    $falls = stockProduct(10, ['reorder_point' => 5]);
    $already = stockProduct(3, ['reorder_point' => 5]);
    $inactive = stockProduct(10, ['reorder_point' => 5, 'is_active' => false]);

    $result = StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, [
        new StockEntry($falls->id, StockMovementType::Out, -6),
        new StockEntry($already->id, StockMovementType::Out, -1),
        new StockEntry($inactive->id, StockMovementType::Out, -6),
    ]));

    expect(array_map(fn (StockChange $c) => [$c->product->id, $c->product->code, $c->before, $c->after], $result->fellBelowReorderPoint))
        ->toBe([[$falls->id, $falls->code, 10, 4]]);
});

test('lockProducts はロック後の現在庫と商品の状態を id の順に返す（存在しない商品は含めない）', function () {
    $a = stockProduct(4, ['reorder_point' => 5]);
    $b = stockProduct(0, ['is_active' => false]);

    $locked = StockTransaction::run(fn (StockTransaction $stock) => $stock->lockProducts([$b->id, 999_999, $a->id]));

    expect(array_keys($locked))->toBe([$a->id, $b->id])
        ->and($locked[$a->id]->stock)->toBe(4)
        ->and($locked[$a->id]->isBelowReorderPoint(4))->toBeTrue()
        ->and($locked[$b->id]->isActive)->toBeFalse();
});

test('トランザクションの外からは記録できない: StockTransaction は run の中でしか作れず、外に持ち出して使うと例外', function () {
    $a = stockProduct(10);
    expect((new ReflectionMethod(StockTransaction::class, '__construct'))->isPrivate())->toBeTrue();

    $escaped = StockTransaction::run(fn (StockTransaction $stock) => $stock);
    $message = '在庫の記録は StockTransaction::run のコールバックの中で、渡された StockTransaction を使って行ってください（トランザクションの外ではロックが効きません）';

    expect(fn () => $escaped->record(null, [new StockEntry($a->id, StockMovementType::Out, -1)]))->toThrow(LogicException::class, $message)
        ->and(fn () => $escaped->lockProducts([$a->id]))->toThrow(LogicException::class, $message)
        ->and(currentStock($a))->toBe(10);
});

test('同じトランザクションで別の商品のロックをあとから追加すると例外（同じ商品・その一部なら再びロックできる）', function () {
    $a = stockProduct(10);
    $b = stockProduct(10);

    StockTransaction::run(function (StockTransaction $stock) use ($a, $b) {
        $stock->lockProducts([$a->id, $b->id]);
        $stock->record(null, [new StockEntry($a->id, StockMovementType::Out, -1)]);

        return $stock->lockProducts([$b->id, $a->id]);
    });
    expect(currentStock($a))->toBe(9);

    expect(fn () => StockTransaction::run(function (StockTransaction $stock) use ($a, $b) {
        $stock->lockProducts([$a->id]);

        return $stock->record(null, [new StockEntry($b->id, StockMovementType::Out, -1)]);
    }))->toThrow(LogicException::class, "同じトランザクションで別の商品のロックをあとから追加しないでください（対象の全商品を最初に一度に渡します）: {$b->id}")
        ->and(currentStock($b))->toBe(10);
});

test('run は最も外側のトランザクションとしてだけ呼べる（ほかのトランザクションの中・run の中では例外。何も記録しない）', function () {
    $a = stockProduct(10);
    $message = 'StockTransaction::run は最も外側のトランザクションとして呼んでください';

    expect(fn () => DB::transaction(fn () => StockTransaction::run(
        fn (StockTransaction $stock) => $stock->record(null, [new StockEntry($a->id, StockMovementType::Out, -1)]),
    )))->toThrow(LogicException::class, $message)
        ->and(fn () => StockTransaction::run(fn () => StockTransaction::run(fn () => null)))->toThrow(LogicException::class, $message)
        ->and(currentStock($a))->toBe(10)
        ->and(DB::transactionLevel())->toBe(0);

    // 例外の後も次の run は使え、戻った時点で確定している（別の接続から見える）
    StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, [new StockEntry($a->id, StockMovementType::Out, -1)]));
    expect((int) StockWorker::db()->table('stock_movements')->where('product_id', $a->id)->sum('quantity'))->toBe(9);
});

test('分離レベル: 開始前に次のトランザクションだけを REPEATABLE READ にしても、run のトランザクションは READ COMMITTED で動く', function () {
    $a = stockProduct(10);
    // 実行中のトランザクションの分離レベル（performance_schema.events_transactions_current。観測用の接続で読む。
    // information_schema.innodb_trx は最大 0.1 秒ごとのキャッシュで、直前のトランザクションの行が見えることがあるので使わない）
    $isolationOfCurrentTransaction = function () use ($a): string {
        Product::query()->whereKey($a->id)->lockForUpdate()->first();
        $row = StockWorker::observer()->selectOne(<<<'SQL'
            SELECT e.ISOLATION_LEVEL AS level
            FROM performance_schema.events_transactions_current e
            JOIN performance_schema.threads t ON t.THREAD_ID = e.THREAD_ID
            WHERE t.PROCESSLIST_ID = ? AND e.STATE = 'ACTIVE'
            SQL, [DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);

        return $row->level;
    };

    // 対照: 普通の DB::transaction は、直前の SET TRANSACTION のとおり REPEATABLE READ になる（セッションの値は READ COMMITTED のまま）
    DB::unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    expect(DB::selectOne('SELECT @@SESSION.transaction_isolation AS level')->level)->toBe('READ-COMMITTED')
        ->and(DB::transaction($isolationOfCurrentTransaction))->toBe('REPEATABLE READ');

    DB::unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    expect(StockTransaction::run(fn () => $isolationOfCurrentTransaction()))->toBe('READ COMMITTED');
});

test('ロック待ちの上限は run の中だけ短くする（終わったら元に戻す）', function () {
    $before = DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;

    expect((int) StockTransaction::run(fn () => DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t))->toBe(StockTransaction::LOCK_WAIT_TIMEOUT)
        ->and(DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t)->toBe($before);
});

test('ロック待ちのタイムアウト（1205）は再試行しない（1 回で例外。上限は元に戻す）', function () {
    $a = stockProduct(10);
    $before = DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;
    $holder = StockWorker::peer('stock_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$a->id]);
    $attempts = 0;
    Event::listen(TransactionBeginning::class, function () use (&$attempts) {
        $attempts++;
    });

    try {
        StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, [new StockEntry($a->id, StockMovementType::Out, -1)]), lockWaitTimeout: 1);
        $this->fail('ロック待ちのタイムアウトになりませんでした');
    } catch (QueryException $e) {
        expect(StockTransaction::isLockWaitTimeout($e))->toBeTrue();
    }

    expect($attempts)->toBe(1)
        ->and(DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t)->toBe($before)
        ->and(DB::transactionLevel())->toBe(0);
    $holder->rollBack();
    expect(currentStock($a))->toBe(10);
});

test('棚卸調整がロック待ちのタイムアウトになったら、日本語で「混み合っています」を返す（記録しない）', function () {
    $user = User::factory()->staff()->create();
    $a = stockProduct(10);
    $holder = StockWorker::peer('stock_holder');
    $holder->beginTransaction();
    $holder->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$a->id]);

    try {
        (new StockAdjustment(lockWaitTimeout: 1))->adjust($user, $a, 7, '棚卸');
        $this->fail('ValidationException が投げられませんでした');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['actual_quantity' => [StockAdjustment::BUSY_MESSAGE]]);
    }
    $holder->rollBack();

    expect(currentStock($a))->toBe(10)
        ->and(AuditLog::query()->where('action', StockAdjustment::ACTION)->exists())->toBeFalse();
});

test('記録が空なら何もしない', function () {
    $result = StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, []));

    expect($result->changes)->toBe([])
        ->and($result->fellBelowReorderPoint)->toBe([]);
});
