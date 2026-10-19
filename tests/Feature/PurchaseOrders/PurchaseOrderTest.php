<?php

use App\Enums\PartnerKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderVersion;
use App\PurchaseOrders\PurchaseOrderListParams;
use App\PurchaseOrders\PurchaseOrderQuery;
use App\PurchaseOrders\PurchaseOrderService;
use App\Support\Csv\CsvParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * 発注の作成・編集・一覧・CSV 出力・権限・ステータス変更（入荷以外）・操作履歴（docs/spec.md §2〜§5・§7・§8）。
 * 入荷（在庫の記録。StockTransaction::run は最も外側でしか動かない）は PurchaseOrderReceiveTest、同時実行は PurchaseOrderConcurrencyTest
 */
function poSupplier(array $attributes = []): Partner
{
    return Partner::factory()->supplier()->create($attributes);
}

function poProduct(array $attributes = []): Product
{
    return Product::factory()->create($attributes);
}

/** @param  list<array{0: Product, 1: int|string, 2: int|string}>  $lines  [商品, 数量, 単価] */
function poInput(Partner $supplier, array $lines, array $extra = []): array
{
    return [
        'supplier_id' => (string) $supplier->id,
        'expected_date' => '',
        'note' => '',
        'lines' => array_map(fn (array $l) => ['product_id' => (string) $l[0]->id, 'quantity' => (string) $l[1], 'unit_price' => (string) $l[2]], $lines),
        ...$extra,
    ];
}

/** 画面から発注を作成して返す（test の supplier を使う） */
function poCreate(object $test, array $lines): PurchaseOrder
{
    $test->post(route('purchase-orders.store'), poInput($test->supplier, $lines))->assertSessionHasNoErrors();

    return PurchaseOrder::query()->latest('id')->firstOrFail();
}

/** 画面の version（詳細・編集画面に渡す値） */
function poVersion(PurchaseOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
});

describe('作成', function () {
    test('採番（PO-今日-001 から）・金額はサーバーで計算（税は 1 件ごとに切り捨て）・明細・操作履歴', function () {
        $supplier = poSupplier(['code' => 'S-0001', 'name' => '架空商事']);
        $a = poProduct(['purchase_price' => 120]);
        $b = poProduct();

        // 小計 = 3×333 + 1×10 = 1,009、税 = floor(100.9) = 100、合計 = 1,109。画面から送られた合計は使わない
        $response = $this->actingAs($this->staff)->post(route('purchase-orders.store'), [
            ...poInput($supplier, [[$a, 3, 333], [$b, 1, 10]], ['expected_date' => '2099-01-31', 'note' => "1 行目\r\n2 行目"]),
            'subtotal' => 1, 'tax' => 1, 'total' => 2,
        ]);

        $order = PurchaseOrder::query()->sole();
        $today = DocumentNumber::today();
        $response->assertRedirect(route('purchase-orders.show', $order));
        expect($order->number)->toBe('PO-'.str_replace('-', '', $today).'-001')
            ->and($order->order_date->toDateString())->toBe($today)
            ->and($order->status)->toBe(PurchaseOrderStatus::Draft)
            ->and([$order->subtotal, $order->tax, $order->total])->toBe([1009, 100, 1109])
            ->and($order->expected_date?->toDateString())->toBe('2099-01-31')
            ->and($order->note)->toBe("1 行目\n2 行目")
            ->and(PurchaseOrderLine::query()->orderBy('id')->get(['product_id', 'quantity', 'unit_price'])->toArray())->toBe([
                ['product_id' => $a->id, 'quantity' => 3, 'unit_price' => 333],
                ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10],
            ]);
        $log = AuditLog::query()->sole();
        expect([$log->user_id, $log->action, $log->target_type, $log->target_id])->toBe([$this->staff->id, 'create', 'purchase_order', (string) $order->id])
            ->and($log->summary)->toBe("発注 {$order->number} を作成しました（仕入先 S-0001「架空商事」・明細 2 行・合計 1,109 円）");
    });

    test('同じ日の 2 件目は 002、別の日は 001 から。999 を超えたら桁を増やす', function () {
        $supplier = poSupplier();
        $p = poProduct();
        $this->actingAs($this->staff);

        $this->post(route('purchase-orders.store'), poInput($supplier, [[$p, 1, 100]]));
        $this->post(route('purchase-orders.store'), poInput($supplier, [[$p, 1, 100]]));
        $today = str_replace('-', '', DocumentNumber::today());
        expect(PurchaseOrder::query()->orderBy('id')->pluck('number')->all())->toBe(["PO-{$today}-001", "PO-{$today}-002"]);

        DB::table('document_sequences')->where('kind', 'PO')->update(['last_number' => 999]);
        $this->post(route('purchase-orders.store'), poInput($supplier, [[$p, 1, 100]]));
        expect(PurchaseOrder::query()->latest('id')->value('number'))->toBe("PO-{$today}-1000");

        // 日付は Asia/Tokyo（UTC の 2030-01-01 15:30 は東京の 2030-01-02 00:30）
        $this->travelTo(CarbonImmutable::parse('2030-01-01 15:30:00', 'UTC'));
        $this->post(route('purchase-orders.store'), poInput($supplier, [[$p, 1, 100]]));
        expect(PurchaseOrder::query()->latest('id')->value('number'))->toBe('PO-20300102-001');
    });

    test('明細 0 行の下書きも保存できる（金額 0）。JSON の数値でも受け付ける', function () {
        $supplier = poSupplier();
        $p = poProduct();
        $this->actingAs($this->staff);

        $this->post(route('purchase-orders.store'), poInput($supplier, []))->assertSessionHasNoErrors();
        $this->postJson(route('purchase-orders.store'), [
            'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 1500]],
        ])->assertRedirect();

        expect(PurchaseOrder::query()->orderBy('id')->get()->map(fn ($o) => [$o->subtotal, $o->tax, $o->total])->all())
            ->toBe([[0, 0, 0], [3000, 300, 3300]]);
    });

    test('仕入先: 顧客だけの取引先・無効な仕入先は選べない。顧客・仕入先（both）は選べる', function (string $kind, bool $active, ?string $message) {
        $partner = Partner::factory()->create(['code' => 'X-0001', 'name' => '架空', 'kind' => $kind, 'is_active' => $active]);
        $p = poProduct();

        $response = $this->actingAs($this->staff)->post(route('purchase-orders.store'), poInput($partner, [[$p, 1, 100]]));

        if ($message === null) {
            $response->assertSessionHasNoErrors();
            expect(PurchaseOrder::query()->count())->toBe(1);
        } else {
            $response->assertSessionHasErrors(['supplier_id' => $message]);
            expect(PurchaseOrder::query()->count())->toBe(0)
                ->and(DB::table('document_sequences')->count())->toBe(0); // 採番も戻る（欠番を作らない）
        }
    })->with([
        '顧客' => [PartnerKind::Customer->value, true, 'X-0001「架空」は仕入先ではありません（種別が「仕入先」か「顧客・仕入先」の取引先を選んでください）'],
        '無効な仕入先' => [PartnerKind::Supplier->value, false, 'X-0001「架空」は無効になっているため選べません'],
        '顧客・仕入先' => [PartnerKind::Both->value, true, null],
    ]);

    test('無効な商品・存在しない商品は行ごとのエラー', function () {
        $supplier = poSupplier();
        $ok = poProduct();
        $inactive = poProduct(['code' => 'P-9001', 'name' => '廃番', 'is_active' => false]);

        $this->actingAs($this->staff)->post(route('purchase-orders.store'), [
            ...poInput($supplier, [[$ok, 1, 100], [$inactive, 1, 100]]),
            'lines' => [
                ['product_id' => (string) $ok->id, 'quantity' => '1', 'unit_price' => '100'],
                ['product_id' => (string) $inactive->id, 'quantity' => '1', 'unit_price' => '100'],
                ['product_id' => '999999', 'quantity' => '1', 'unit_price' => '100'],
            ],
        ])->assertSessionHasErrors([
            'lines.1.product_id' => 'P-9001「廃番」は無効になっているため選べません',
            'lines.2.product_id' => '商品が見つかりません',
        ]);
        expect(PurchaseOrder::query()->count())->toBe(0);
    });

    test('入力検証: 数量・単価・ID・重複・行数・合計の上限・日付・備考（エラー文は日本語）', function (array $override, array $errors) {
        $supplier = poSupplier();
        $p = poProduct();
        $q = poProduct();
        $input = array_replace(poInput($supplier, [[$p, 1, 100], [$q, 2, 200]]), $override);
        // 行の上書きは「#P」「#Q」を商品 ID に置き換える
        array_walk_recursive($input, function (&$v) use ($p, $q) {
            $v = $v === '#P' ? (string) $p->id : ($v === '#Q' ? (string) $q->id : $v);
        });

        $this->actingAs($this->staff)->post(route('purchase-orders.store'), $input)->assertSessionHasErrors($errors);
        expect(PurchaseOrder::query()->count())->toBe(0);
    })->with([
        '仕入先なし' => [['supplier_id' => ''], ['supplier_id' => '仕入先を選択してください']],
        '仕入先 ID が INT の範囲外' => [['supplier_id' => '99999999999'], ['supplier_id' => '仕入先を選択してください']],
        '数量 0' => [['lines' => [['product_id' => '#P', 'quantity' => '0', 'unit_price' => '1']]], ['lines.0.quantity' => '数量は1以上の整数で入力してください']],
        '数量が小数' => [['lines' => [['product_id' => '#P', 'quantity' => '1.5', 'unit_price' => '1']]], ['lines.0.quantity' => '数量は1以上の整数で入力してください']],
        '数量が負（JSON の数値）' => [['lines' => [['product_id' => '#P', 'quantity' => -1, 'unit_price' => 1]]], ['lines.0.quantity' => '数量は1以上の整数で入力してください']],
        '数量の上限' => [['lines' => [['product_id' => '#P', 'quantity' => '10000000', 'unit_price' => '1']]], ['lines.0.quantity' => '数量は9,999,999以下で入力してください']],
        '単価が負' => [['lines' => [['product_id' => '#P', 'quantity' => '1', 'unit_price' => '-1']]], ['lines.0.unit_price' => '単価は0以上の整数で入力してください']],
        '単価なし' => [['lines' => [['product_id' => '#P', 'quantity' => '1', 'unit_price' => '']]], ['lines.0.unit_price' => '単価を入力してください']],
        '商品なし' => [['lines' => [['product_id' => '', 'quantity' => '1', 'unit_price' => '1']]], ['lines.0.product_id' => '商品を選択してください']],
        '商品 ID が INT の範囲外' => [['lines' => [['product_id' => '4294967296', 'quantity' => '1', 'unit_price' => '1']]], ['lines.0.product_id' => '商品を選択してください']],
        '同じ商品が 2 行' => [['lines' => [['product_id' => '#P', 'quantity' => '1', 'unit_price' => '1'], ['product_id' => '#P', 'quantity' => '2', 'unit_price' => '1']]], ['lines.1.product_id' => '同じ商品の明細が 1 行目にあります（数量は 1 行にまとめてください）']],
        '明細が配列でない' => [['lines' => 'x'], ['lines' => '明細の形式が正しくありません']],
        '明細の行数の上限' => [['lines' => array_fill(0, 101, ['product_id' => '#P', 'quantity' => '1', 'unit_price' => '1'])], ['lines' => '明細は100行までです']],
        '合計の上限' => [['lines' => [['product_id' => '#P', 'quantity' => '9999999', 'unit_price' => '99999999']]], ['lines' => '合計金額が上限（999,999,999 円）を超えています']],
        '存在しない日付' => [['expected_date' => '2026-02-30'], ['expected_date' => '入荷予定日は正しい日付で入力してください（例 2026-10-19）']],
        '発注日より前の入荷予定日' => [['expected_date' => '2001-01-01'], ['expected_date' => '入荷予定日は発注日（'.DocumentNumber::today().'）以降の日付にしてください']],
        '備考のタブ' => [['note' => "a\tb"], ['note' => '備考 にタブなどの制御文字は使えません（改行は使えます）']],
        '備考の文字数' => [['note' => str_repeat('あ', 501)], ['note' => '備考は500文字以内で入力してください']],
    ]);

    test('全角数字・桁区切りのカンマは受け付ける', function () {
        $supplier = poSupplier();
        $p = poProduct();

        $this->actingAs($this->staff)->post(route('purchase-orders.store'), poInput($supplier, [[$p, '１２', '1,200']]))->assertSessionHasNoErrors();

        expect(PurchaseOrderLine::query()->sole()->only(['quantity', 'unit_price']))->toBe(['quantity' => 12, 'unit_price' => 1200]);
    });
});

describe('編集', function () {
    beforeEach(function () {
        $this->actingAs($this->staff);
        $this->supplier = poSupplier(['code' => 'S-0001']);
        $this->p = poProduct(['code' => 'P-0001']);
        $this->q = poProduct(['code' => 'P-0002']);
        $this->post(route('purchase-orders.store'), poInput($this->supplier, [[$this->p, 1, 100]]));
        $this->order = PurchaseOrder::query()->sole();
    });

    test('下書きは編集でき、金額を計算し直し、変更点を操作履歴に残す', function () {
        $other = poSupplier(['code' => 'S-0002']);

        $this->put(route('purchase-orders.update', $this->order), [
            ...poInput($other, [[$this->q, 2, 500]], ['expected_date' => '2099-12-31', 'note' => 'メモ']),
            'version' => poVersion($this->order),
        ])->assertRedirect(route('purchase-orders.show', $this->order))->assertSessionHasNoErrors();

        $order = $this->order->fresh();
        expect($order->supplier_id)->toBe($other->id)
            ->and([$order->subtotal, $order->tax, $order->total])->toBe([1000, 100, 1100])
            ->and(PurchaseOrderLine::query()->get(['product_id', 'quantity', 'unit_price'])->toArray())->toBe([['product_id' => $this->q->id, 'quantity' => 2, 'unit_price' => 500]])
            ->and(AuditLog::query()->where('action', 'update')->sole()->summary)
            ->toBe("発注 {$order->number} を更新しました（仕入先: S-0001 → S-0002、入荷予定日: （なし） → 2099-12-31、備考を変更、明細: 1 行 → 1 行、合計: 110 → 1,100 円）");
    });

    test('変更がなければ保存しない（操作履歴も残さない）', function () {
        $version = poVersion($this->order);
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 1, 100]]), 'version' => $version])
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'update')->count())->toBe(0)
            ->and(poVersion($this->order))->toBe($version);
    });

    test('競合: 画面を開いた後に他の人が保存していたら保存しない（同じ 1 秒の中の更新も見分ける）。形式の違う version も競合', function () {
        $stale = poVersion($this->order);
        // 時計を止めて、同じ時刻（同じ 1 秒・同じマイクロ秒）に 2 回保存しても version は変わる
        $this->freezeTime();
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 2, 100]]), 'version' => $stale])->assertSessionHasNoErrors();
        $afterFirst = poVersion($this->order);
        expect($afterFirst)->not->toBe($stale);

        $conflict = PurchaseOrderService::conflictMessage();
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 3, 100]]), 'version' => $stale])
            ->assertSessionHasErrors(['conflict' => $conflict]);
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 3, 100]]), 'version' => 'x'])
            ->assertSessionHasErrors(['conflict' => $conflict]);
        expect(PurchaseOrderLine::query()->sole()->quantity)->toBe(2);

        // 最新の version なら保存できる（同じ時刻でも前の値より大きくなる）
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 3, 100]]), 'version' => $afterFirst])->assertSessionHasNoErrors();
        expect(PurchaseOrderLine::query()->sole()->quantity)->toBe(3)
            ->and(poVersion($this->order) > $afterFirst)->toBeTrue();
    });

    test('下書き以外は編集できない（画面は詳細へ戻し、保存はエラー）', function (PurchaseOrderStatus $status) {
        DB::table('purchase_orders')->where('id', $this->order->id)->update(['status' => $status->value]);

        $this->get(route('purchase-orders.edit', $this->order))->assertRedirect(route('purchase-orders.show', $this->order));
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 5, 100]]), 'version' => poVersion($this->order)])
            ->assertSessionHasErrors(['form' => "この発注は「{$status->label()}」のため編集できません（編集できるのは下書きだけです）"]);
        expect(PurchaseOrderLine::query()->sole()->quantity)->toBe(1);
    })->with([PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled]);

    test('もとから明細にある商品は無効になっても残せる。新しく加える無効な商品は選べない', function () {
        $this->p->update(['is_active' => false]);
        $newInactive = poProduct(['code' => 'P-0003', 'name' => '廃番', 'is_active' => false]);

        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 4, 100], [$newInactive, 1, 1]]), 'version' => poVersion($this->order)])
            ->assertSessionHasErrors(['lines.1.product_id' => 'P-0003「廃番」は無効になっているため選べません']);
        $this->put(route('purchase-orders.update', $this->order), [...poInput($this->supplier, [[$this->p, 4, 100]]), 'version' => poVersion($this->order)])
            ->assertSessionHasNoErrors();

        expect(PurchaseOrderLine::query()->sole()->quantity)->toBe(4);
        // 編集画面の選択肢にも残る
        $this->get(route('purchase-orders.edit', $this->order))->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Edit')
            ->where('products', fn ($products) => collect($products)->contains(fn ($o) => $o['id'] === $this->p->id && $o['is_active'] === false)
                && ! collect($products)->contains(fn ($o) => $o['id'] === $newInactive->id)));
    });

    test('範囲外・存在しない id は 404', function () {
        $this->get('/purchase-orders/99999999999999')->assertNotFound();
        $this->put('/purchase-orders/4294967296', [...poInput($this->supplier, []), 'version' => 'x'])->assertNotFound();
        $this->post('/purchase-orders/99999999999999999999/status', ['to' => 'ordered'])->assertNotFound();
    });
});

describe('ステータス変更（入荷以外）', function () {
    beforeEach(function () {
        $this->actingAs($this->staff);
        $this->supplier = poSupplier();
        $this->p = poProduct();
    });

    test('draft → ordered → cancelled。操作履歴に残す', function () {
        $order = poCreate($this, [[$this->p, 1, 100]]);

        $this->post(route('purchase-orders.status', $order), ['to' => 'ordered', 'version' => poVersion($order)])
            ->assertRedirect(route('purchase-orders.show', $order))->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Ordered);
        $this->post(route('purchase-orders.status', $order), ['to' => 'cancelled', 'version' => poVersion($order)])->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled)
            ->and(AuditLog::query()->where('action', 'status_change')->orderBy('id')->pluck('summary')->all())->toBe([
                "発注 {$order->number} のステータスを「下書き」から「発注済」に変更しました",
                "発注 {$order->number} のステータスを「発注済」から「取消」に変更しました",
            ]);
    });

    test('明細 0 行は発注済にできない', function () {
        $order = poCreate($this, []);

        $this->post(route('purchase-orders.status', $order), ['to' => 'ordered', 'version' => poVersion($order)])
            ->assertSessionHasErrors(['status' => '明細が 1 行もないため、発注済にできません']);
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
    });

    // draft → received（在庫を記録するトランザクション）は PurchaseOrderReceiveTest で確かめる
    test('最終状態（取消）からは変更できない。draft への変更もできない', function () {
        $order = poCreate($this, [[$this->p, 1, 100]]);
        $this->post(route('purchase-orders.status', $order), ['to' => 'draft', 'version' => poVersion($order)])
            ->assertSessionHasErrors(['to' => '変更先のステータスが正しくありません']);

        $this->post(route('purchase-orders.status', $order), ['to' => 'cancelled', 'version' => poVersion($order)])->assertSessionHasNoErrors();
        foreach (['ordered', 'cancelled'] as $to) {
            $this->post(route('purchase-orders.status', $order), ['to' => $to, 'version' => poVersion($order)])
                ->assertSessionHasErrors(['status' => 'この発注は「取消」のため、ステータスを変更できません']);
        }
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
    });

    test('競合: 詳細を開いた後に編集されていたら、ステータスは変更しない（遷移できない理由が先）', function () {
        $order = poCreate($this, [[$this->p, 1, 100]]);
        $stale = poVersion($order);
        $this->put(route('purchase-orders.update', $order), [...poInput($this->supplier, [[$this->p, 9, 100]]), 'version' => $stale])->assertSessionHasNoErrors();

        $this->post(route('purchase-orders.status', $order), ['to' => 'ordered', 'version' => $stale])
            ->assertSessionHasErrors(['conflict' => PurchaseOrderService::statusConflictMessage()]);
        $this->post(route('purchase-orders.status', $order), ['to' => 'cancelled', 'version' => 'bad'])
            ->assertSessionHasErrors(['conflict' => PurchaseOrderService::statusConflictMessage()]);
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft);

        // 最新の version なら変更できる
        $this->post(route('purchase-orders.status', $order), ['to' => 'ordered', 'version' => poVersion($order)])->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Ordered);
    });
});

describe('一覧・詳細・CSV 出力', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create()); // viewer も閲覧・出力できる
        $this->s1 = poSupplier(['code' => 'S-0001', 'name' => 'Ａ商事']);
        $this->s2 = poSupplier(['code' => 'S-0002', 'name' => '=cmd|calc']);
        $make = fn (Partner $s, string $number, string $date, string $status, int $total, ?string $note = null) => PurchaseOrder::factory()->create([
            'supplier_id' => $s->id, 'number' => $number, 'order_date' => $date, 'status' => $status,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'note' => $note,
        ]);
        $make($this->s1, 'PO-20261001-001', '2026-10-01', 'draft', 300);
        $make($this->s1, 'PO-20261002-001', '2026-10-02', 'ordered', 100, '-至急');
        $make($this->s2, 'PO-20261002-002', '2026-10-02', 'received', 200);
        $make($this->s2, 'PO-20261002-1000', '2026-10-02', 'cancelled', 50);
    });

    $numbers = fn ($response) => array_column($response->viewData('page')['props']['orders'], 'number');

    test('既定は発注日の新しい順（同じ日は作成の順の逆）。番号・ステータス・仕入先・期間で絞り込み', function () use ($numbers) {
        expect($numbers($this->get(route('purchase-orders.index'))))->toBe(['PO-20261002-1000', 'PO-20261002-002', 'PO-20261002-001', 'PO-20261001-001'])
            ->and($numbers($this->get(route('purchase-orders.index', ['q' => '1002-00']))))->toBe(['PO-20261002-002', 'PO-20261002-001'])
            ->and($numbers($this->get(route('purchase-orders.index', ['q' => '%']))))->toBe([])
            ->and($numbers($this->get(route('purchase-orders.index', ['status' => 'ordered']))))->toBe(['PO-20261002-001'])
            ->and($numbers($this->get(route('purchase-orders.index', ['supplier' => (string) $this->s2->id]))))->toBe(['PO-20261002-1000', 'PO-20261002-002'])
            ->and($numbers($this->get(route('purchase-orders.index', ['from' => '2026-10-01', 'to' => '2026-10-01']))))->toBe(['PO-20261001-001'])
            // 不正な値は既定値
            ->and($numbers($this->get(route('purchase-orders.index', ['status' => 'x', 'from' => '2026-02-30', 'sort' => 'id;drop']))))->toHaveCount(4);
    });

    test('並べ替え: 番号は桁数も見る（-1000 は -999 の後）・合計・仕入先', function () use ($numbers) {
        expect($numbers($this->get(route('purchase-orders.index', ['sort' => 'number', 'order' => 'asc']))))->toBe(['PO-20261001-001', 'PO-20261002-001', 'PO-20261002-002', 'PO-20261002-1000'])
            ->and($numbers($this->get(route('purchase-orders.index', ['sort' => 'total', 'order' => 'asc']))))->toBe(['PO-20261002-1000', 'PO-20261002-001', 'PO-20261002-002', 'PO-20261001-001'])
            ->and($numbers($this->get(route('purchase-orders.index', ['sort' => 'supplier', 'order' => 'asc']))))->toBe(['PO-20261001-001', 'PO-20261002-001', 'PO-20261002-002', 'PO-20261002-1000']);
    });

    test('20 件ずつページ送り（範囲外のページは最終ページ）', function () {
        PurchaseOrder::factory()->count(20)->create(['supplier_id' => $this->s1->id, 'order_date' => '2026-09-01']);

        $this->get(route('purchase-orders.index', ['page' => '9']))->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Index')
            ->has('orders', 4)
            ->where('pagination', ['total' => 24, 'page' => 2, 'totalPages' => 2, 'perPage' => 20])
            ->where('filters.page', 2));
    });

    test('詳細: 明細・金額・version を渡す。遷移の選択肢', function () {
        $order = PurchaseOrder::query()->where('number', 'PO-20261002-001')->sole();
        PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'product_id' => poProduct(['code' => 'P-0001'])->id, 'quantity' => 2, 'unit_price' => 50]);

        $this->get(route('purchase-orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->component('purchase-orders/Show')
            ->where('order.number', 'PO-20261002-001')
            ->where('order.status_label', '発注済')
            ->where('order.lines.0.product_code', 'P-0001')
            ->where('order.lines.0.amount', 100)
            ->where('order.version', poVersion($order))
            ->where('transitions', ['received', 'cancelled']));
    });

    test('CSV: UTF-8（BOM 付き）・見出し・一覧と同じ絞り込み。数式になりうるセルは無害化', function () {
        $response = $this->get(route('purchase-orders.export', ['supplier' => (string) $this->s1->id, 'page' => '2']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="purchase_orders_'.now()->format('Ymd').'.csv"');
        $body = (string) $response->getContent();
        expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and(substr($body, 3))->toBe(
                "発注番号,発注日,入荷予定日,仕入先コード,仕入先名,ステータス,明細数,小計,消費税,合計,備考\r\n"
                ."PO-20261002-001,2026-10-02,,S-0001,Ａ商事,発注済,0,100,0,100,'-至急\r\n"
                ."PO-20261001-001,2026-10-01,,S-0001,Ａ商事,下書き,0,300,0,300,\r\n",
            );
        $records = CsvParser::parse(substr((string) $this->get(route('purchase-orders.export', ['supplier' => (string) $this->s2->id]))->getContent(), 3));
        expect($records[1]->cells[4])->toBe("'=cmd|calc");
    });

    test('CSV: 上限を超えたら出力せず 413（黙って切り捨てない）。絞り込めば出力できる', function () {
        $now = now();
        foreach (array_chunk(range(1, PurchaseOrderQuery::EXPORT_MAX_ROWS + 1), 1000) as $chunk) {
            DB::table('purchase_orders')->insert(array_map(fn (int $i) => [
                'number' => sprintf('PO-20260901-%05d', $i), 'supplier_id' => $this->s1->id, 'order_date' => '2026-09-01',
                'status' => 'draft', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $this->get(route('purchase-orders.export'))->assertStatus(413)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('件数が多すぎます。絞り込んでから出力してください（上限 10,000 件）');
        $this->get(route('purchase-orders.export', ['from' => '2026-10-01']))->assertOk();
    });

    test('CSV: 上限の判定（上限ちょうどは出力・超えたら null）', function () {
        $query = new PurchaseOrderQuery;
        $params = PurchaseOrderListParams::fromQuery([]);
        expect($query->forExport($params, 4))->toHaveCount(4)
            ->and($query->forExport($params, 3))->toBeNull();
    });
});

describe('権限（ルートの can: と Policy の二重）', function () {
    test('viewer は一覧・詳細・CSV 出力だけ。作成・編集・ステータス変更は 403', function () {
        $viewer = User::factory()->create();
        $order = PurchaseOrder::factory()->create();
        $p = poProduct();
        $this->actingAs($viewer);

        $this->get(route('purchase-orders.index'))->assertOk();
        $this->get(route('purchase-orders.show', $order))->assertOk();
        $this->get(route('purchase-orders.export'))->assertOk();
        $this->get(route('purchase-orders.create'))->assertForbidden();
        $this->post(route('purchase-orders.store'), poInput($order->supplier, [[$p, 1, 1]]))->assertForbidden();
        $this->get(route('purchase-orders.edit', $order))->assertForbidden();
        $this->put(route('purchase-orders.update', $order), [...poInput($order->supplier, [[$p, 1, 1]]), 'version' => poVersion($order)])->assertForbidden();
        $this->post(route('purchase-orders.status', $order), ['to' => 'cancelled', 'version' => poVersion($order)])->assertForbidden();
        expect(PurchaseOrder::query()->count())->toBe(1)
            ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
    });

    test('admin・staff は作成・編集・ステータス変更ができる。未ログインはログイン画面へ', function () {
        foreach ([User::factory()->admin()->create(), User::factory()->staff()->create()] as $user) {
            $this->actingAs($user)->get(route('purchase-orders.create'))->assertOk();
        }
        auth()->logout();
        $this->get(route('purchase-orders.index'))->assertRedirect(route('login'));
    });

    test('Policy: ルートの can: を通っても、無効ユーザー・viewer は Policy で拒否する', function () {
        $order = PurchaseOrder::factory()->create();
        $viewer = User::factory()->create();
        $inactiveStaff = User::factory()->staff()->inactive()->create();
        $staff = User::factory()->staff()->create();

        foreach (['create' => PurchaseOrder::class, 'update' => $order, 'changeStatus' => $order] as $ability => $target) {
            expect($viewer->can($ability, $target))->toBeFalse()
                ->and($inactiveStaff->can($ability, $target))->toBeFalse()
                ->and($staff->can($ability, $target))->toBeTrue();
        }
        expect($viewer->can('export', PurchaseOrder::class))->toBeTrue()
            ->and($viewer->can('view', $order))->toBeTrue();
    });
});

describe('操作履歴とトランザクション', function () {
    test('操作履歴の保存に失敗したら、作成・編集・ステータス変更も取り消す（採番も戻る）', function () {
        $staff = $this->staff;
        $supplier = poSupplier();
        $p = poProduct();
        $this->actingAs($staff)->post(route('purchase-orders.store'), poInput($supplier, [[$p, 1, 100]]));
        $order = PurchaseOrder::query()->sole();
        $sequence = DB::table('document_sequences')->value('last_number');

        AuditLog::creating(function () {
            throw new RuntimeException('操作履歴の保存に失敗（テスト）');
        });
        $service = app(PurchaseOrderService::class);
        $input = ['supplier_id' => $supplier->id, 'expected_date' => null, 'note' => null, 'lines' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 100]]];

        expect(fn () => $service->create($staff, $input))->toThrow(RuntimeException::class, '操作履歴の保存に失敗（テスト）')
            ->and(fn () => $service->update($staff, $order->id, $input, poVersion($order)))->toThrow(RuntimeException::class)
            ->and(fn () => $service->changeStatus($staff, $order->id, PurchaseOrderStatus::Ordered, poVersion($order)))->toThrow(RuntimeException::class);

        expect(PurchaseOrder::query()->count())->toBe(1)
            ->and(DB::table('document_sequences')->value('last_number'))->toBe($sequence)
            ->and(PurchaseOrderLine::query()->sole()->quantity)->toBe(1)
            ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
            ->and(AuditLog::query()->count())->toBe(1);
    });
});
