<?php

use App\Dashboard\DashboardPeriod;
use App\Dashboard\DashboardQuery;
use Carbon\CarbonImmutable;

/** ダッシュボードの期間と前月比（App\Dashboard\DashboardPeriod。docs/spec.md §1・§5-2） */
describe('JST の暦日', function () {
    test('UTC では前日でも JST の日付になる（0 時〜9 時の境界）', function () {
        // 2026-10-31T15:00Z = JST 11/1 0:00
        expect(DashboardPeriod::jstDate(new DateTimeImmutable('2026-10-31T15:00:00Z')))->toBe('2026-11-01')
            // 1 マイクロ秒前は JST でも 10/31
            ->and(DashboardPeriod::jstDate(new DateTimeImmutable('2026-10-31T14:59:59.999999Z')))->toBe('2026-10-31');
    });

    test('年またぎ（UTC 12/31 15:00 = JST 1/1 0:00）', function () {
        expect(DashboardPeriod::jstDate(new DateTimeImmutable('2026-12-31T15:00:00Z')))->toBe('2027-01-01')
            ->and(DashboardPeriod::jstDate(new DateTimeImmutable('2026-12-31T14:59:59Z')))->toBe('2026-12-31');
    });

    test('PHP の既定のタイムゾーンによらない', function () {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati'] as $tz) {
                date_default_timezone_set($tz);
                expect(DashboardPeriod::jstDate(CarbonImmutable::parse('2000-02-29T15:30:00Z')))->toBe('2000-03-01')
                    ->and(DashboardPeriod::recentMonths(CarbonImmutable::parse('2000-02-29T15:30:00Z'), 1)[0]['key'])->toBe('2000-03');
            }
        } finally {
            date_default_timezone_set($original);
        }
    });
});

describe('月の範囲', function () {
    test('1 日から翌月 1 日まで（翌月 1 日は含まない）', function () {
        expect(DashboardPeriod::monthRange(2026, 10))->toBe([
            'year' => 2026,
            'month' => 10,
            'key' => '2026-10',
            'start' => '2026-10-01',
            'end' => '2026-11-01',
        ]);
    });

    test('12 月の終わりは翌年 1 月 1 日', function () {
        expect(DashboardPeriod::monthRange(2026, 12))
            ->toMatchArray(['start' => '2026-12-01', 'end' => '2027-01-01']);
    });

    test('2 月（うるう年・平年とも月末の日付を持たないので同じ扱い）', function (int $year) {
        expect(DashboardPeriod::monthRange($year, 2))
            ->toMatchArray(['start' => "{$year}-02-01", 'end' => "{$year}-03-01"]);
    })->with([
        'うるう年 2028' => 2028,
        '平年 2027' => 2027,
        'うるう年 2000（400 で割り切れる）' => 2000,
        '平年 2100（100 で割り切れる）' => 2100,
    ]);

    test('月をずらす（年をまたぐ・負の数）', function (int $year, int $month, int $n, array $expected) {
        expect(DashboardPeriod::addMonths($year, $month, $n))->toBe($expected);
    })->with([
        '1 月の前月' => [2027, 1, -1, ['year' => 2026, 'month' => 12]],
        '5 か月前' => [2026, 3, -5, ['year' => 2025, 'month' => 10]],
        '12 月の翌月' => [2026, 12, 1, ['year' => 2027, 'month' => 1]],
        '17 か月前' => [2026, 5, -17, ['year' => 2024, 'month' => 12]],
        '12 か月前' => [2026, 1, -12, ['year' => 2025, 'month' => 1]],
        'ずらさない' => [2026, 10, 0, ['year' => 2026, 'month' => 10]],
    ]);

    test('直近 6 か月（古い順。最後が JST の今月）', function () {
        // UTC ではまだ 2026-12-31 だが JST では 2027-01-01
        $months = DashboardPeriod::recentMonths(new DateTimeImmutable('2026-12-31T15:30:00Z'), 6);

        expect(array_column($months, 'key'))->toBe(['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'])
            ->and($months[0]['start'])->toBe('2026-08-01')
            ->and($months[5]['end'])->toBe('2027-02-01');
    });

    test('JST の月末 23:59 はまだ今月（うるう日）', function () {
        // UTC 2000-02-29 14:59:59 = JST 2000-02-29 23:59:59
        $months = DashboardPeriod::recentMonths(new DateTimeImmutable('2000-02-29T14:59:59Z'), 2);

        expect(array_column($months, 'key'))->toBe(['2000-01', '2000-02'])
            ->and($months[1]['end'])->toBe('2000-03-01');
    });
});

describe('前月比', function () {
    test('増加・減少・同じ（矢印の向き・符号付きの差額・小数 1 桁の増減率）', function (int $current, int $previous, array $expected) {
        expect(DashboardPeriod::monthOverMonth($current, $previous))->toBe($expected);
    })->with([
        '増加' => [1500, 1000, ['diff' => 500, 'rate' => 50.0, 'direction' => 'up', 'diff_label' => '+500', 'rate_label' => '+50.0%']],
        '減少' => [750, 1000, ['diff' => -250, 'rate' => -25.0, 'direction' => 'down', 'diff_label' => '-250', 'rate_label' => '-25.0%']],
        '同じ' => [1000, 1000, ['diff' => 0, 'rate' => 0.0, 'direction' => 'flat', 'diff_label' => '0', 'rate_label' => '±0%']],
        '今月 0' => [0, 1000, ['diff' => -1000, 'rate' => -100.0, 'direction' => 'down', 'diff_label' => '-1,000', 'rate_label' => '-100.0%']],
        // diff / 前月 × 100 だと 7.000000000000001 になる値
        '端数が出ない' => [107, 100, ['diff' => 7, 'rate' => 7.0, 'direction' => 'up', 'diff_label' => '+7', 'rate_label' => '+7.0%']],
        '3 桁区切り' => [2_641_540, 1_100_000, ['diff' => 1_541_540, 'rate' => 140.14, 'direction' => 'up', 'diff_label' => '+1,541,540', 'rate_label' => '+140.1%']],
        // 差額はあるが、増減率は丸めると 0（+0.0% と出さない）
        'わずかな増加' => [100_001, 100_000, ['diff' => 1, 'rate' => 0.001, 'direction' => 'up', 'diff_label' => '+1', 'rate_label' => '±0%']],
        'わずかな減少' => [99_999, 100_000, ['diff' => -1, 'rate' => -0.001, 'direction' => 'down', 'diff_label' => '-1', 'rate_label' => '±0%']],
    ]);

    test('前月が 0 なら増減率は出さない（null）。表示は「前月の受注なし」', function () {
        expect(DashboardPeriod::monthOverMonth(1200, 0))->toBe(['diff' => 1200, 'rate' => null, 'direction' => 'up', 'diff_label' => '+1,200', 'rate_label' => '前月の受注なし'])
            ->and(DashboardPeriod::monthOverMonth(0, 0))->toBe(['diff' => 0, 'rate' => null, 'direction' => 'flat', 'diff_label' => '0', 'rate_label' => '前月の受注なし']);
    });

    test('増減率の表示は小数 1 桁。丸めると 0 なら「±0%」（-0.0% も出さない）', function (float $rate, string $expected) {
        expect(DashboardPeriod::rateLabel($rate))->toBe($expected);
    })->with([
        '0' => [0.0, '±0%'],
        '-0.0' => [-0.0, '±0%'],
        '0.04 は ±0%' => [0.04, '±0%'],
        '-0.04 は ±0%' => [-0.04, '±0%'],
        '0.05 は +0.1%' => [0.05, '+0.1%'],
        '-0.05 は -0.1%' => [-0.05, '-0.1%'],
        '切り上げ' => [12.345, '+12.3%'],
        '桁が多い' => [1234.56, '+1234.6%'],
        '-100' => [-100.0, '-100.0%'],
    ]);
});

describe('一覧の「他 N 件」（DashboardQuery::more）', function () {
    test('件数 − 一覧の件数。出しきれたら 0（0 未満にしない）', function (int $count, int $shown, int $expected) {
        expect(DashboardQuery::more($count, $shown))->toBe($expected);
    })->with([
        '超える' => [7, 5, 2],
        'ちょうど' => [5, 5, 0],
        '少ない' => [3, 3, 0],
        '0 件' => [0, 0, 0],
        '件数より多く出した（起きないが 0 にする）' => [2, 3, 0],
    ]);
});
