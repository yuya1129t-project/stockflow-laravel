<?php

use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoData;

/** シード生成の純粋な部分（DB を使わない）。DB に入れたあとの不変条件は tests/Feature/Database/DemoSeedTest.php */
$now = CarbonImmutable::parse('2026-10-11 12:00:00', 'Asia/Tokyo');

test('同じ基準日時なら毎回同じデータになる（固定シード）', function () use ($now) {
    expect(DemoData::build($now))->toEqual(DemoData::build($now));
});

test('Next.js 版（stockflow-next）と同じデータになる', function () use ($now) {
    // 値は stockflow-next の buildSeedData(new Date("2026-10-11T12:00:00+09:00")) の結果
    $data = DemoData::build($now);
    $first = $data['sales_orders'][0];
    $last = $data['sales_orders'][79];

    expect(array_sum(array_column($data['sales_orders'], 'total')))->toBe(779009)
        ->and(array_sum(array_column($data['purchase_orders'], 'total')))->toBe(2621905)
        ->and($data['stock_movements'])->toHaveCount(303)
        ->and(array_sum(array_column($data['stock_movements'], 'quantity')))->toBe(7948)
        ->and(array_count_values(array_column($data['sales_orders'], 'status')))
        ->toEqual(['shipped' => 64, 'confirmed' => 9, 'cancelled' => 6, 'draft' => 1])
        ->and(array_count_values(array_column($data['purchase_orders'], 'status')))
        ->toEqual(['received' => 30, 'ordered' => 7, 'cancelled' => 3])
        ->and([$first['number'], $first['customer_id'], $first['due_date'], $first['status'], $first['total']])
        ->toBe(['SO-20260719-001', 10, '2026-07-24', 'shipped', 14454])
        ->and([$last['number'], $last['customer_id'], $last['status'], $last['total']])
        ->toBe(['SO-20261011-003', 7, 'confirmed', 16082])
        ->and($data['purchase_orders'][0]['number'])->toBe('PO-20260717-001');
});

test('基準日が変わっても、日付がずれるだけで中身は同じ', function () use ($now) {
    $shape = fn (array $data) => array_map(
        fn (array $o) => [$o['status'], $o['customer_id'], $o['total'], $o['lines']],
        $data['sales_orders'],
    );

    expect($shape(DemoData::build(CarbonImmutable::parse('2026-11-01 00:30:00', 'Asia/Tokyo'))))
        ->toEqual($shape(DemoData::build($now)));
});

test('受注・発注の日付は基準日（JST）から過去約 90 日以内で、作成日時は基準日時より後にならない', function () use ($now) {
    $data = DemoData::build($now);
    $from = $now->subDays(DemoData::PERIOD_DAYS)->toDateString();

    foreach ([...$data['sales_orders'], ...$data['purchase_orders']] as $order) {
        expect($order['order_date'] >= $from && $order['order_date'] <= '2026-10-11')->toBeTrue($order['number'])
            ->and($order['created_at']->lessThanOrEqualTo($now))->toBeTrue($order['number']);
    }
});

test('月初（1 日の早朝）に実行しても今月の受注がある', function () {
    $monthStart = CarbonImmutable::parse('2026-11-01 00:30:00', 'Asia/Tokyo');
    $data = DemoData::build($monthStart);

    $thisMonth = array_filter(
        $data['sales_orders'],
        fn (array $o) => $o['status'] === 'confirmed' && str_starts_with($o['order_date'], '2026-11'),
    );

    expect($thisMonth)->not->toBeEmpty();
});
