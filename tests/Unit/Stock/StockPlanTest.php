<?php

use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Stock\StockChange;
use App\Stock\StockEntry;
use App\Stock\StockPlan;
use App\Stock\StockProduct;

/** 在庫の規則（純粋な計算）: 数量の符号・参照元の検査、同じ商品の合算、不足の判定、発注点割れへの変化 */
function planProduct(int $id, int $stock, int $reorderPoint = 0, bool $active = true): StockProduct
{
    return new StockProduct($id, sprintf('P-%04d', $id), "商品{$id}", '個', $reorderPoint, $active, $stock);
}

test('数量の符号は種別に合うこと（in は正・out は負・adjust は 0 以外）。合わなければ作れない', function (StockMovementType $type, int $quantity, bool $valid) {
    expect(StockEntry::isValidQuantity($type, $quantity))->toBe($valid);
    if (! $valid) {
        expect(fn () => new StockEntry(1, $type, $quantity))
            ->toThrow(InvalidArgumentException::class, "入出庫の数量が種別（{$type->value}）に合いません: {$quantity}");
    }
})->with([
    [StockMovementType::In, 1, true],
    [StockMovementType::In, 0, false],
    [StockMovementType::In, -1, false],
    [StockMovementType::Out, -1, true],
    [StockMovementType::Out, 0, false],
    [StockMovementType::Out, 1, false],
    [StockMovementType::Adjust, 3, true],
    [StockMovementType::Adjust, -3, true],
    [StockMovementType::Adjust, 0, false],
    // stock_movements.quantity（INT）の範囲
    [StockMovementType::In, 2_147_483_647, true],
    [StockMovementType::In, 2_147_483_648, false],
    [StockMovementType::Out, -2_147_483_648, false],
]);

test('参照元は refType と refId の両方を指定するか、両方とも指定しない', function () {
    expect(new StockEntry(1, StockMovementType::Out, -1, refType: StockRefType::SalesOrder, refId: 10))->toBeInstanceOf(StockEntry::class)
        ->and(new StockEntry(1, StockMovementType::Adjust, 1, '棚卸'))->toBeInstanceOf(StockEntry::class)
        ->and(fn () => new StockEntry(1, StockMovementType::Out, -1, refType: StockRefType::SalesOrder))
        ->toThrow(InvalidArgumentException::class, '入出庫の参照元は refType と refId を両方指定してください: sales_order / null')
        ->and(fn () => new StockEntry(1, StockMovementType::In, 1, refId: 10))
        ->toThrow(InvalidArgumentException::class, '入出庫の参照元は refType と refId を両方指定してください: null / 10');
});

test('同じ商品の入出庫は合計して判定する。結果は商品 ID の順', function () {
    $plan = StockPlan::make([2 => planProduct(2, 5), 1 => planProduct(1, 10)], [
        new StockEntry(2, StockMovementType::Out, -3),
        new StockEntry(1, StockMovementType::In, 4),
        new StockEntry(2, StockMovementType::Out, -3), // 2 行の合計 6 は在庫 5 を超える
    ]);

    expect($plan->changes)->toHaveCount(1)
        ->and($plan->changes[0]->product->id)->toBe(1)
        ->and([$plan->changes[0]->before, $plan->changes[0]->after])->toBe([10, 14])
        ->and($plan->shortages)->toHaveCount(1)
        ->and($plan->shortages[0]->product->id)->toBe(2)
        ->and([$plan->shortages[0]->available, $plan->shortages[0]->required, $plan->shortages[0]->shortage])->toBe([5, 6, 1]);
});

test('在庫ちょうどまでは出庫できる（記録後 0 は不足ではない）。入庫と出庫の合計で判定する', function () {
    $plan = StockPlan::make([1 => planProduct(1, 5)], [
        new StockEntry(1, StockMovementType::Out, -7),
        new StockEntry(1, StockMovementType::In, 2),
    ]);

    expect($plan->shortages)->toBe([])
        ->and($plan->changes[0]->after)->toBe(0);
});

test('発注点以上 → 発注点未満に変わった有効な商品だけを返す（もともと割れている・無効・入庫で増えた・発注点ちょうどは含めない）', function () {
    $products = [
        1 => planProduct(1, 10, reorderPoint: 5), // 10 → 4: 割れた
        2 => planProduct(2, 5, reorderPoint: 5),  // 5 → 4: 発注点ちょうどから割れた
        3 => planProduct(3, 3, reorderPoint: 5),  // 3 → 2: もともと割れている
        4 => planProduct(4, 10, reorderPoint: 5, active: false), // 無効の商品は通知しない（spec §6-3）
        5 => planProduct(5, 6, reorderPoint: 5),  // 6 → 5: 発注点ちょうどは割れていない
        6 => planProduct(6, 2, reorderPoint: 5),  // 2 → 9: 入庫で回復
    ];
    $plan = StockPlan::make($products, [
        new StockEntry(1, StockMovementType::Out, -6),
        new StockEntry(2, StockMovementType::Out, -1),
        new StockEntry(3, StockMovementType::Out, -1),
        new StockEntry(4, StockMovementType::Out, -6),
        new StockEntry(5, StockMovementType::Out, -1),
        new StockEntry(6, StockMovementType::In, 7),
    ]);

    expect(array_map(fn (StockChange $c) => [$c->product->id, $c->before, $c->after], $plan->fellBelowReorderPoint))
        ->toBe([[1, 10, 4], [2, 5, 4]])
        ->and($plan->changes)->toHaveCount(6);
});

test('在庫を読んでいない商品の入出庫はプログラムの誤り', function () {
    expect(fn () => StockPlan::make([1 => planProduct(1, 5)], [new StockEntry(2, StockMovementType::In, 1)]))
        ->toThrow(LogicException::class, '商品 2 の在庫を読んでいません');
});
