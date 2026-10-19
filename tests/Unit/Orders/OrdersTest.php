<?php

use App\Enums\DocumentKind;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Orders\DocumentNumber;
use App\Orders\OrderAmounts;
use App\Orders\OrderVersion;
use App\Rules\MultiLine;
use Carbon\CarbonImmutable;

/** 受注・発注で共通の部品（app/Orders）と受注・発注のステータス遷移（docs/spec.md §1・§3・§4） */
describe('金額（OrderAmounts）', function () {
    test('小計 = Σ 数量 × 単価、税は 1 件ごとに 10% の 1 円未満切り捨て、合計 = 小計 + 税', function (array $lines, array $expected) {
        $amounts = OrderAmounts::calculate($lines);

        expect($amounts?->toArray())->toBe($expected);
    })->with([
        '明細なし' => [[], ['subtotal' => 0, 'tax' => 0, 'total' => 0]],
        '切り捨て' => [[['quantity' => 3, 'unit_price' => 333], ['quantity' => 1, 'unit_price' => 10]], ['subtotal' => 1009, 'tax' => 100, 'total' => 1109]],
        '9 円は税 0' => [[['quantity' => 1, 'unit_price' => 9]], ['subtotal' => 9, 'tax' => 0, 'total' => 9]],
        '単価 0' => [[['quantity' => 5, 'unit_price' => 0]], ['subtotal' => 0, 'tax' => 0, 'total' => 0]],
    ]);

    test('合計の上限（999,999,999 円）: ちょうどは通し、超えたら null', function () {
        // 小計 909,090,909 → 税 90,909,090 → 合計 999,999,999
        expect(OrderAmounts::calculate([['quantity' => 1, 'unit_price' => 909_090_909]])?->total)->toBe(999_999_999)
            ->and(OrderAmounts::calculate([['quantity' => 1, 'unit_price' => 909_090_910]]))->toBeNull()
            ->and(OrderAmounts::calculate(array_fill(0, 100, ['quantity' => 9_999_999, 'unit_price' => 99_999_999])))->toBeNull();
    });

    test('負の数量・単価はプログラムの誤り', function () {
        expect(fn () => OrderAmounts::calculate([['quantity' => -1, 'unit_price' => 1]]))->toThrow(InvalidArgumentException::class);
    });
});

describe('伝票番号（DocumentNumber）', function () {
    test('PO-YYYYMMDD-NNN。999 を超えたら桁を増やす', function () {
        expect(DocumentNumber::format(DocumentKind::PurchaseOrder, '2026-10-19', 1))->toBe('PO-20261019-001')
            ->and(DocumentNumber::format(DocumentKind::SalesOrder, '2026-10-19', 999))->toBe('SO-20261019-999')
            ->and(DocumentNumber::format(DocumentKind::PurchaseOrder, '2026-10-19', 1000))->toBe('PO-20261019-1000');
    });

    test('日付・連番の誤りは例外', function () {
        expect(fn () => DocumentNumber::format(DocumentKind::PurchaseOrder, '20261019', 1))->toThrow(InvalidArgumentException::class)
            ->and(fn () => DocumentNumber::format(DocumentKind::PurchaseOrder, '2026-10-19', 0))->toThrow(InvalidArgumentException::class);
    });

    test('今日は Asia/Tokyo の日付', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC'));
        try {
            expect(DocumentNumber::today())->toBe('2026-10-20');
        } finally {
            CarbonImmutable::setTestNow();
        }
    });
});

describe('競合の検出（OrderVersion）', function () {
    test('マイクロ秒まで比べる。形式の違う値は null', function () {
        $at = CarbonImmutable::parse('2026-10-19 10:00:00.123456');

        expect(OrderVersion::of($at))->toBe('2026-10-19 10:00:00.123456')
            ->and(OrderVersion::matches($at, '2026-10-19 10:00:00.123456'))->toBeTrue()
            ->and(OrderVersion::matches($at, '2026-10-19 10:00:00.123457'))->toBeFalse()
            ->and(OrderVersion::matches(null, '2026-10-19 10:00:00.123456'))->toBeFalse()
            ->and(OrderVersion::parse('2026-10-19 10:00:00'))->toBeNull()
            ->and(OrderVersion::parse(['x']))->toBeNull()
            ->and(OrderVersion::parse('2026-10-19 10:00:00.000000'))->toBe('2026-10-19 10:00:00.000000')
            // 形は合っていても実在しない日時は null（繰り上がった値にしない）
            ->and(OrderVersion::parse('2026-02-30 10:00:00.000000'))->toBeNull()
            ->and(OrderVersion::parse('2026-10-19 99:99:99.000000'))->toBeNull()
            ->and(OrderVersion::parse('2026-10-19 24:00:00.000000'))->toBeNull()
            ->and(OrderVersion::parse("2026-10-19 10:00:00.000000\n"))->toBeNull()
            ->and(OrderVersion::parse('2028-02-29 23:59:59.999999'))->toBe('2028-02-29 23:59:59.999999');
    });

    test('次の値は今の値より必ず大きい（時計が止まっていても・戻っても）', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 10:00:00.500000'));
        try {
            expect(OrderVersion::next('2026-10-19 09:00:00.000000'))->toBe('2026-10-19 10:00:00.500000')
                ->and(OrderVersion::next('2026-10-19 10:00:00.500000'))->toBe('2026-10-19 10:00:00.500001')
                ->and(OrderVersion::next(CarbonImmutable::parse('2026-10-19 11:00:00.999999')))->toBe('2026-10-19 11:00:01.000000');
        } finally {
            CarbonImmutable::setTestNow();
        }
    });
});

describe('発注のステータス遷移（PurchaseOrderStatus）', function () {
    test('遷移表: draft → ordered／cancelled、ordered → received／cancelled。received・cancelled は最終', function () {
        expect(PurchaseOrderStatus::Draft->transitions())->toBe([PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Cancelled])
            ->and(PurchaseOrderStatus::Ordered->transitions())->toBe([PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled])
            ->and(PurchaseOrderStatus::Received->isFinal())->toBeTrue()
            ->and(PurchaseOrderStatus::Cancelled->isFinal())->toBeTrue()
            ->and(PurchaseOrderStatus::Draft->isEditable())->toBeTrue()
            ->and(PurchaseOrderStatus::Ordered->isEditable())->toBeFalse();
    });

    test('遷移できない理由', function (PurchaseOrderStatus $from, PurchaseOrderStatus $to, int $lines, ?string $error) {
        expect($from->transitionError($to, $lines))->toBe($error);
    })->with([
        [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered, 1, null],
        [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered, 0, '明細が 1 行もないため、発注済にできません'],
        [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Received, 1, '「下書き」から「入荷済」には変更できません'],
        [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled, 0, null],
        [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Received, 1, null],
        [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Draft, 1, '「発注済」から「下書き」には変更できません'],
        [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled, 1, 'この発注は「入荷済」のため、ステータスを変更できません'],
        [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Ordered, 1, 'この発注は「取消」のため、ステータスを変更できません'],
    ]);
});

describe('受注のステータス遷移（SalesOrderStatus）', function () {
    test('遷移表: draft → confirmed／cancelled、confirmed → shipped／cancelled。shipped・cancelled は最終', function () {
        expect(SalesOrderStatus::Draft->transitions())->toBe([SalesOrderStatus::Confirmed, SalesOrderStatus::Cancelled])
            ->and(SalesOrderStatus::Confirmed->transitions())->toBe([SalesOrderStatus::Shipped, SalesOrderStatus::Cancelled])
            ->and(SalesOrderStatus::Shipped->isFinal())->toBeTrue()
            ->and(SalesOrderStatus::Cancelled->isFinal())->toBeTrue()
            ->and(SalesOrderStatus::Draft->isEditable())->toBeTrue()
            ->and(SalesOrderStatus::Confirmed->isEditable())->toBeFalse()
            ->and(array_map(fn (SalesOrderStatus $s) => $s->label(), SalesOrderStatus::cases()))->toBe(['下書き', '確定', '出荷済', '取消']);
    });

    test('遷移できない理由', function (SalesOrderStatus $from, SalesOrderStatus $to, int $lines, ?string $error) {
        expect($from->transitionError($to, $lines))->toBe($error);
    })->with([
        [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed, 1, null],
        [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed, 0, '明細が 1 行もないため、確定にできません'],
        [SalesOrderStatus::Draft, SalesOrderStatus::Shipped, 1, '「下書き」から「出荷済」には変更できません'],
        [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled, 0, null],
        [SalesOrderStatus::Confirmed, SalesOrderStatus::Shipped, 1, null],
        [SalesOrderStatus::Confirmed, SalesOrderStatus::Cancelled, 1, null],
        [SalesOrderStatus::Confirmed, SalesOrderStatus::Draft, 1, '「確定」から「下書き」には変更できません'],
        [SalesOrderStatus::Shipped, SalesOrderStatus::Cancelled, 1, 'この受注は「出荷済」のため、ステータスを変更できません'],
        [SalesOrderStatus::Cancelled, SalesOrderStatus::Confirmed, 1, 'この受注は「取消」のため、ステータスを変更できません'],
    ]);
});

test('備考の規則（MultiLine）: 改行は使える。タブ・CR・表示方向の制御などは使えない', function () {
    expect(MultiLine::passes("1 行目\n2 行目"))->toBeTrue()
        ->and(MultiLine::passes("a\tb"))->toBeFalse()
        ->and(MultiLine::passes("a\rb"))->toBeFalse()
        ->and(MultiLine::passes("a\u{202E}b"))->toBeFalse()
        ->and(MultiLine::passes("\xFF"))->toBeFalse();
});
