<?php

use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Stock\InsufficientStockException;
use App\Stock\StockChange;
use App\Stock\StockEntry;
use App\Stock\StockQuery;
use App\Stock\StockTransaction;
use Illuminate\Support\Facades\DB;

/**
 * 在庫の共通サービス（StockTransaction・StockQuery）。同時実行は StockConcurrencyTest.php で、別々の接続で確かめる。
 * ここではテストのトランザクション（RefreshDatabase）の中で動かす（StockTransaction::run はその内側のトランザクションになる）
 */
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

test('run の入れ子は例外', function () {
    expect(fn () => StockTransaction::run(fn () => StockTransaction::run(fn () => null)))
        ->toThrow(LogicException::class, 'StockTransaction::run を入れ子にしないでください');

    // 例外の後も次の run は使える
    expect(StockTransaction::run(fn () => 'ok'))->toBe('ok');
});

test('接続の分離レベルは READ COMMITTED。それ以外なら run は例外にする', function () {
    expect(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('READ-COMMITTED');

    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    try {
        expect(fn () => StockTransaction::run(fn () => null))
            ->toThrow(LogicException::class, '在庫を記録するトランザクションの分離レベルが READ COMMITTED ではありません');
    } finally {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
});

test('記録が空なら何もしない', function () {
    $result = StockTransaction::run(fn (StockTransaction $stock) => $stock->record(null, []));

    expect($result->changes)->toBe([])
        ->and($result->fellBelowReorderPoint)->toBe([]);
});
