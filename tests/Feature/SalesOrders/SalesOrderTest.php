<?php

use App\Enums\PartnerKind;
use App\Enums\SalesOrderStatus;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Orders\DocumentNumber;
use App\Orders\OrderVersion;
use App\SalesOrders\SalesOrderListParams;
use App\SalesOrders\SalesOrderQuery;
use App\SalesOrders\SalesOrderService;
use App\Support\Csv\CsvParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * 受注の作成・編集・一覧・CSV 出力・権限・ステータス変更（出荷以外）・操作履歴（docs/spec.md §2〜§5・§7・§8）。発注（PurchaseOrderTest）と同じ観点。
 * 出荷（在庫の記録。StockTransaction::run は最も外側でしか動かない）は SalesOrderShipTest、同時実行は SalesOrderConcurrencyTest
 */
function soCustomer(array $attributes = []): Partner
{
    return Partner::factory()->customer()->create($attributes);
}

function soProduct(array $attributes = []): Product
{
    return Product::factory()->create($attributes);
}

/** @param  list<array{0: Product, 1: int|string, 2: int|string}>  $lines  [商品, 数量, 単価] */
function soInput(Partner $customer, array $lines, array $extra = []): array
{
    return [
        'customer_id' => (string) $customer->id,
        'due_date' => '',
        'note' => '',
        'lines' => array_map(fn (array $l) => ['product_id' => (string) $l[0]->id, 'quantity' => (string) $l[1], 'unit_price' => (string) $l[2]], $lines),
        ...$extra,
    ];
}

/** 画面から受注を作成して返す（test の customer を使う） */
function soCreate(object $test, array $lines): SalesOrder
{
    $test->post(route('sales-orders.store'), soInput($test->customer, $lines))->assertSessionHasNoErrors();

    return SalesOrder::query()->latest('id')->firstOrFail();
}

/** 画面の version（詳細・編集画面に渡す値） */
function soVersion(SalesOrder $order): string
{
    return OrderVersion::of($order->fresh()?->updated_at);
}

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
});

describe('作成', function () {
    test('採番（SO-今日-001 から）・金額はサーバーで計算（税は 1 件ごとに切り捨て）・明細・操作履歴', function () {
        $customer = soCustomer(['code' => 'C-0001', 'name' => '架空商事']);
        $a = soProduct(['sales_price' => 120]);
        $b = soProduct();

        // 小計 = 3×333 + 1×10 = 1,009、税 = floor(100.9) = 100、合計 = 1,109。画面から送られた合計は使わない
        $response = $this->actingAs($this->staff)->post(route('sales-orders.store'), [
            ...soInput($customer, [[$a, 3, 333], [$b, 1, 10]], ['due_date' => '2099-01-31', 'note' => "1 行目\r\n2 行目"]),
            'subtotal' => 1, 'tax' => 1, 'total' => 2,
        ]);

        $order = SalesOrder::query()->sole();
        $today = DocumentNumber::today();
        $response->assertRedirect(route('sales-orders.show', $order));
        expect($order->number)->toBe('SO-'.str_replace('-', '', $today).'-001')
            ->and($order->order_date->toDateString())->toBe($today)
            ->and($order->status)->toBe(SalesOrderStatus::Draft)
            ->and([$order->subtotal, $order->tax, $order->total])->toBe([1009, 100, 1109])
            ->and($order->due_date?->toDateString())->toBe('2099-01-31')
            ->and($order->note)->toBe("1 行目\n2 行目")
            ->and(SalesOrderLine::query()->orderBy('id')->get(['product_id', 'quantity', 'unit_price'])->toArray())->toBe([
                ['product_id' => $a->id, 'quantity' => 3, 'unit_price' => 333],
                ['product_id' => $b->id, 'quantity' => 1, 'unit_price' => 10],
            ]);
        $log = AuditLog::query()->sole();
        expect([$log->user_id, $log->action, $log->target_type, $log->target_id])->toBe([$this->staff->id, 'create', 'sales_order', (string) $order->id])
            ->and($log->summary)->toBe("受注 {$order->number} を作成しました（顧客 C-0001「架空商事」・明細 2 行・合計 1,109 円）");
    });

    test('同じ日の 2 件目は 002、別の日は 001 から。999 を超えたら桁を増やす', function () {
        $customer = soCustomer();
        $p = soProduct();
        $this->actingAs($this->staff);

        $this->post(route('sales-orders.store'), soInput($customer, [[$p, 1, 100]]));
        $this->post(route('sales-orders.store'), soInput($customer, [[$p, 1, 100]]));
        $today = str_replace('-', '', DocumentNumber::today());
        expect(SalesOrder::query()->orderBy('id')->pluck('number')->all())->toBe(["SO-{$today}-001", "SO-{$today}-002"]);

        DB::table('document_sequences')->where('kind', 'SO')->update(['last_number' => 999]);
        $this->post(route('sales-orders.store'), soInput($customer, [[$p, 1, 100]]));
        expect(SalesOrder::query()->latest('id')->value('number'))->toBe("SO-{$today}-1000");

        // 日付は Asia/Tokyo（UTC の 2030-01-01 15:30 は東京の 2030-01-02 00:30）
        $this->travelTo(CarbonImmutable::parse('2030-01-01 15:30:00', 'UTC'));
        $this->post(route('sales-orders.store'), soInput($customer, [[$p, 1, 100]]));
        expect(SalesOrder::query()->latest('id')->value('number'))->toBe('SO-20300102-001');
    });

    test('明細 0 行の下書きも保存できる（金額 0）。JSON の数値でも受け付ける', function () {
        $customer = soCustomer();
        $p = soProduct();
        $this->actingAs($this->staff);

        $this->post(route('sales-orders.store'), soInput($customer, []))->assertSessionHasNoErrors();
        $this->postJson(route('sales-orders.store'), [
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 1500]],
        ])->assertRedirect();

        expect(SalesOrder::query()->orderBy('id')->get()->map(fn ($o) => [$o->subtotal, $o->tax, $o->total])->all())
            ->toBe([[0, 0, 0], [3000, 300, 3300]]);
    });

    test('顧客: 仕入先だけの取引先・無効な顧客は選べない。顧客・仕入先（both）は選べる', function (string $kind, bool $active, ?string $message) {
        $partner = Partner::factory()->create(['code' => 'X-0001', 'name' => '架空', 'kind' => $kind, 'is_active' => $active]);
        $p = soProduct();

        $response = $this->actingAs($this->staff)->post(route('sales-orders.store'), soInput($partner, [[$p, 1, 100]]));

        if ($message === null) {
            $response->assertSessionHasNoErrors();
            expect(SalesOrder::query()->count())->toBe(1);
        } else {
            $response->assertSessionHasErrors(['customer_id' => $message]);
            expect(SalesOrder::query()->count())->toBe(0)
                ->and(DB::table('document_sequences')->count())->toBe(0); // 採番も戻る（欠番を作らない）
        }
    })->with([
        '仕入先' => [PartnerKind::Supplier->value, true, 'X-0001「架空」は顧客ではありません（種別が「顧客」か「顧客・仕入先」の取引先を選んでください）'],
        '無効な顧客' => [PartnerKind::Customer->value, false, 'X-0001「架空」は無効です。別の顧客を選んでください'],
        '顧客・仕入先' => [PartnerKind::Both->value, true, null],
    ]);

    test('無効な商品・存在しない商品は行ごとのエラー', function () {
        $customer = soCustomer();
        $ok = soProduct();
        $inactive = soProduct(['code' => 'P-0901', 'name' => '廃番', 'is_active' => false]); // ファクトリーの商品コード（P-9###）と重ならないコード

        $this->actingAs($this->staff)->post(route('sales-orders.store'), [
            ...soInput($customer, [[$ok, 1, 100], [$inactive, 1, 100]]),
            'lines' => [
                ['product_id' => (string) $ok->id, 'quantity' => '1', 'unit_price' => '100'],
                ['product_id' => (string) $inactive->id, 'quantity' => '1', 'unit_price' => '100'],
                ['product_id' => '999999', 'quantity' => '1', 'unit_price' => '100'],
            ],
        ])->assertSessionHasErrors([
            'lines.1.product_id' => 'P-0901「廃番」は無効になっているため選べません',
            'lines.2.product_id' => '商品が見つかりません',
        ]);
        expect(SalesOrder::query()->count())->toBe(0);
    });

    test('入力検証: 数量・単価・ID・重複・行数・合計の上限・日付・備考（エラー文は日本語）', function (array $override, array $errors) {
        $customer = soCustomer();
        $p = soProduct();
        $q = soProduct();
        $input = array_replace(soInput($customer, [[$p, 1, 100], [$q, 2, 200]]), $override);
        // 行の上書きは「#P」「#Q」を商品 ID に置き換える
        array_walk_recursive($input, function (&$v) use ($p, $q) {
            $v = $v === '#P' ? (string) $p->id : ($v === '#Q' ? (string) $q->id : $v);
        });

        $this->actingAs($this->staff)->post(route('sales-orders.store'), $input)->assertSessionHasErrors($errors);
        expect(SalesOrder::query()->count())->toBe(0);
    })->with([
        '顧客なし' => [['customer_id' => ''], ['customer_id' => '顧客を選択してください']],
        '顧客 ID が INT の範囲外' => [['customer_id' => '99999999999'], ['customer_id' => '顧客を選択してください']],
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
        '存在しない日付' => [['due_date' => '2026-02-30'], ['due_date' => '納期は正しい日付で入力してください（例 2026-10-19）']],
        '受注日より前の納期' => [['due_date' => '2001-01-01'], ['due_date' => '納期は受注日（'.DocumentNumber::today().'）以降の日付にしてください']],
        '備考のタブ' => [['note' => "a\tb"], ['note' => '備考 にタブなどの制御文字は使えません（改行は使えます）']],
        '備考の文字数' => [['note' => str_repeat('あ', 501)], ['note' => '備考は500文字以内で入力してください']],
    ]);

    test('作成画面: 単価の初期値は商品の売価。顧客の選択肢は有効な顧客・顧客・仕入先だけ', function () {
        $customer = soCustomer(['code' => 'C-0001']);
        $both = Partner::factory()->create(['code' => 'B-0001', 'kind' => PartnerKind::Both]);
        Partner::factory()->supplier()->create(['code' => 'S-0001']);
        soCustomer(['code' => 'C-0002', 'is_active' => false]);
        $p = soProduct(['code' => 'P-0001', 'sales_price' => 1200, 'purchase_price' => 700]);
        soProduct(['code' => 'P-0002', 'is_active' => false]);

        $this->actingAs($this->staff)->get(route('sales-orders.create'))->assertInertia(fn (Assert $page) => $page
            ->component('sales-orders/Create')
            ->where('customers', [
                ['id' => $both->id, 'code' => 'B-0001', 'name' => $both->name, 'is_active' => true],
                ['id' => $customer->id, 'code' => 'C-0001', 'name' => $customer->name, 'is_active' => true],
            ])
            ->where('products', [['id' => $p->id, 'code' => 'P-0001', 'name' => $p->name, 'unit' => $p->unit, 'price' => 1200, 'is_active' => true]]));
    });

    test('全角数字・桁区切りのカンマは受け付ける', function () {
        $customer = soCustomer();
        $p = soProduct();

        $this->actingAs($this->staff)->post(route('sales-orders.store'), soInput($customer, [[$p, '１２', '1,200']]))->assertSessionHasNoErrors();

        expect(SalesOrderLine::query()->sole()->only(['quantity', 'unit_price']))->toBe(['quantity' => 12, 'unit_price' => 1200]);
    });
});

describe('編集', function () {
    beforeEach(function () {
        $this->actingAs($this->staff);
        $this->customer = soCustomer(['code' => 'C-0001']);
        $this->p = soProduct(['code' => 'P-0001']);
        $this->q = soProduct(['code' => 'P-0002']);
        $this->post(route('sales-orders.store'), soInput($this->customer, [[$this->p, 1, 100]]));
        $this->order = SalesOrder::query()->sole();
    });

    test('下書きは編集でき、金額を計算し直し、変更点を操作履歴に残す', function () {
        $other = soCustomer(['code' => 'C-0002']);

        $this->put(route('sales-orders.update', $this->order), [
            ...soInput($other, [[$this->q, 2, 500]], ['due_date' => '2099-12-31', 'note' => 'メモ']),
            'version' => soVersion($this->order),
        ])->assertRedirect(route('sales-orders.show', $this->order))->assertSessionHasNoErrors();

        $order = $this->order->fresh();
        expect($order->customer_id)->toBe($other->id)
            ->and([$order->subtotal, $order->tax, $order->total])->toBe([1000, 100, 1100])
            ->and(SalesOrderLine::query()->get(['product_id', 'quantity', 'unit_price'])->toArray())->toBe([['product_id' => $this->q->id, 'quantity' => 2, 'unit_price' => 500]])
            ->and(AuditLog::query()->where('action', 'update')->sole()->summary)
            ->toBe("受注 {$order->number} を更新しました（顧客: C-0001 → C-0002、納期: （なし） → 2099-12-31、備考を変更、明細: 1 行 → 1 行、合計: 110 → 1,100 円）");
    });

    test('変更がなければ保存しない（操作履歴も残さない）', function () {
        $version = soVersion($this->order);
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 1, 100]]), 'version' => $version])
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'update')->count())->toBe(0)
            ->and(soVersion($this->order))->toBe($version);
    });

    test('競合: 画面を開いた後に他の人が保存していたら保存しない（同じ 1 秒の中の更新も見分ける）。形式の違う version も競合', function () {
        $stale = soVersion($this->order);
        // 時計を止めて、同じ時刻（同じ 1 秒・同じマイクロ秒）に 2 回保存しても version は変わる
        $this->freezeTime();
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 2, 100]]), 'version' => $stale])->assertSessionHasNoErrors();
        $afterFirst = soVersion($this->order);
        expect($afterFirst)->not->toBe($stale);

        $conflict = SalesOrderService::conflictMessage();
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 3, 100]]), 'version' => $stale])
            ->assertSessionHasErrors(['conflict' => $conflict]);
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 3, 100]]), 'version' => 'x'])
            ->assertSessionHasErrors(['conflict' => $conflict]);
        expect(SalesOrderLine::query()->sole()->quantity)->toBe(2);

        // 最新の version なら保存できる（同じ時刻でも前の値より大きくなる）
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 3, 100]]), 'version' => $afterFirst])->assertSessionHasNoErrors();
        expect(SalesOrderLine::query()->sole()->quantity)->toBe(3)
            ->and(soVersion($this->order) > $afterFirst)->toBeTrue();
    });

    test('競合: 実在しない日時の version（2026-02-30・99:99:99 など）は競合として扱い、何も変えない', function (string $version) {
        $before = soVersion($this->order);

        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 7, 100]]), 'version' => $version])
            ->assertSessionHasErrors(['conflict' => SalesOrderService::conflictMessage()]);

        expect(SalesOrderLine::query()->sole()->quantity)->toBe(1)
            ->and(soVersion($this->order))->toBe($before)
            ->and(AuditLog::query()->where('action', 'update')->count())->toBe(0);
    })->with(['2026-02-30 10:00:00.000000', '2026-10-19 99:99:99.000000', '2026-10-19 24:00:00.000000', "2026-10-19 10:00:00.000000\n"]);

    test('顧客が無効になった下書き: 保存には有効な顧客が必要（画面ではその顧客を選べない形で出す）。確定にはそのままできる', function () {
        $this->customer->update(['is_active' => false]);
        $message = "C-0001「{$this->customer->name}」は無効です。別の顧客を選んでください";

        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 1, 100]], ['note' => '備考だけ直す']), 'version' => soVersion($this->order)])
            ->assertSessionHasErrors(['customer_id' => $message]);
        $this->get(route('sales-orders.edit', $this->order))->assertInertia(fn (Assert $page) => $page
            ->component('sales-orders/Edit')
            ->where('customers', fn ($customers) => collect($customers)->contains(fn ($o) => $o['id'] === $this->customer->id && $o['is_active'] === false)));

        // ステータス変更では顧客の有効を問わない
        $this->post(route('sales-orders.status', $this->order), ['to' => 'confirmed', 'version' => soVersion($this->order)])->assertSessionHasNoErrors();
        expect($this->order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
    });

    test('下書き以外は編集できない（画面は詳細へ戻し、保存はエラー）', function (SalesOrderStatus $status) {
        DB::table('sales_orders')->where('id', $this->order->id)->update(['status' => $status->value]);

        $this->get(route('sales-orders.edit', $this->order))->assertRedirect(route('sales-orders.show', $this->order));
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 5, 100]]), 'version' => soVersion($this->order)])
            ->assertSessionHasErrors(['form' => "この受注は「{$status->label()}」のため編集できません（編集できるのは下書きだけです）"]);
        expect(SalesOrderLine::query()->sole()->quantity)->toBe(1);
    })->with([SalesOrderStatus::Confirmed, SalesOrderStatus::Shipped, SalesOrderStatus::Cancelled]);

    test('もとから明細にある商品は無効になっても残せる。新しく加える無効な商品は選べない', function () {
        $this->p->update(['is_active' => false]);
        $newInactive = soProduct(['code' => 'P-0003', 'name' => '廃番', 'is_active' => false]);

        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 4, 100], [$newInactive, 1, 1]]), 'version' => soVersion($this->order)])
            ->assertSessionHasErrors(['lines.1.product_id' => 'P-0003「廃番」は無効になっているため選べません']);
        $this->put(route('sales-orders.update', $this->order), [...soInput($this->customer, [[$this->p, 4, 100]]), 'version' => soVersion($this->order)])
            ->assertSessionHasNoErrors();

        expect(SalesOrderLine::query()->sole()->quantity)->toBe(4);
        // 編集画面の選択肢にも残る
        $this->get(route('sales-orders.edit', $this->order))->assertInertia(fn (Assert $page) => $page
            ->component('sales-orders/Edit')
            ->where('products', fn ($products) => collect($products)->contains(fn ($o) => $o['id'] === $this->p->id && $o['is_active'] === false)
                && ! collect($products)->contains(fn ($o) => $o['id'] === $newInactive->id)));
    });

    test('範囲外・存在しない id は 404', function () {
        $this->get('/sales-orders/99999999999999')->assertNotFound();
        $this->put('/sales-orders/4294967296', [...soInput($this->customer, []), 'version' => 'x'])->assertNotFound();
        $this->post('/sales-orders/99999999999999999999/status', ['to' => 'confirmed'])->assertNotFound();
    });
});

describe('ステータス変更（出荷以外）', function () {
    beforeEach(function () {
        $this->actingAs($this->staff);
        $this->customer = soCustomer();
        $this->p = soProduct();
    });

    test('draft → confirmed → cancelled。操作履歴に残す', function () {
        $order = soCreate($this, [[$this->p, 1, 100]]);

        $this->post(route('sales-orders.status', $order), ['to' => 'confirmed', 'version' => soVersion($order)])
            ->assertRedirect(route('sales-orders.show', $order))->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
        $this->post(route('sales-orders.status', $order), ['to' => 'cancelled', 'version' => soVersion($order)])->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled)
            ->and(AuditLog::query()->where('action', 'status_change')->orderBy('id')->pluck('summary')->all())->toBe([
                "受注 {$order->number} のステータスを「下書き」から「確定」に変更しました",
                "受注 {$order->number} のステータスを「確定」から「取消」に変更しました",
            ]);
    });

    test('明細 0 行は確定にできない', function () {
        $order = soCreate($this, []);

        $this->post(route('sales-orders.status', $order), ['to' => 'confirmed', 'version' => soVersion($order)])
            ->assertSessionHasErrors(['status' => '明細が 1 行もないため、確定にできません']);
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Draft);
    });

    // draft → shipped（在庫を記録するトランザクション）は SalesOrderShipTest で確かめる
    test('最終状態（取消）からは変更できない。draft への変更もできない', function () {
        $order = soCreate($this, [[$this->p, 1, 100]]);
        $this->post(route('sales-orders.status', $order), ['to' => 'draft', 'version' => soVersion($order)])
            ->assertSessionHasErrors(['to' => '変更先のステータスが正しくありません']);

        $this->post(route('sales-orders.status', $order), ['to' => 'cancelled', 'version' => soVersion($order)])->assertSessionHasNoErrors();
        foreach (['confirmed', 'cancelled'] as $to) {
            $this->post(route('sales-orders.status', $order), ['to' => $to, 'version' => soVersion($order)])
                ->assertSessionHasErrors(['status' => 'この受注は「取消」のため、ステータスを変更できません']);
        }
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
    });

    test('競合: 詳細を開いた後に編集されていたら、ステータスは変更しない（遷移できない理由が先）', function () {
        $order = soCreate($this, [[$this->p, 1, 100]]);
        $stale = soVersion($order);
        $this->put(route('sales-orders.update', $order), [...soInput($this->customer, [[$this->p, 9, 100]]), 'version' => $stale])->assertSessionHasNoErrors();

        $this->post(route('sales-orders.status', $order), ['to' => 'confirmed', 'version' => $stale])
            ->assertSessionHasErrors(['conflict' => SalesOrderService::statusConflictMessage()]);
        $this->post(route('sales-orders.status', $order), ['to' => 'cancelled', 'version' => 'bad'])
            ->assertSessionHasErrors(['conflict' => SalesOrderService::statusConflictMessage()]);
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Draft);

        // 最新の version なら変更できる
        $this->post(route('sales-orders.status', $order), ['to' => 'confirmed', 'version' => soVersion($order)])->assertSessionHasNoErrors();
        expect($order->fresh()->status)->toBe(SalesOrderStatus::Confirmed);
    });

    test('競合: 実在しない日時の version ではステータスを変更しない（日本語の競合エラー）', function (string $version) {
        $order = soCreate($this, [[$this->p, 1, 100]]);
        $before = soVersion($order);

        $this->post(route('sales-orders.status', $order), ['to' => 'confirmed', 'version' => $version])
            ->assertSessionHasErrors(['conflict' => SalesOrderService::statusConflictMessage()]);

        expect($order->fresh()->status)->toBe(SalesOrderStatus::Draft)
            ->and(soVersion($order))->toBe($before)
            ->and(AuditLog::query()->where('action', 'status_change')->count())->toBe(0);
    })->with(['2026-02-30 10:00:00.000000', '2026-10-19 99:99:99.000000']);
});

describe('一覧・詳細・CSV 出力', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create()); // viewer も閲覧・出力できる
        $this->s1 = soCustomer(['code' => 'C-0001', 'name' => 'Ａ商事']);
        $this->s2 = soCustomer(['code' => 'C-0002', 'name' => '=cmd|calc']);
        $make = fn (Partner $s, string $number, string $date, string $status, int $total, ?string $note = null) => SalesOrder::factory()->create([
            'customer_id' => $s->id, 'number' => $number, 'order_date' => $date, 'status' => $status,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'note' => $note,
        ]);
        $make($this->s1, 'SO-20261001-001', '2026-10-01', 'draft', 300);
        $make($this->s1, 'SO-20261002-001', '2026-10-02', 'confirmed', 100, '-至急');
        $make($this->s2, 'SO-20261002-002', '2026-10-02', 'shipped', 200);
        $make($this->s2, 'SO-20261002-1000', '2026-10-02', 'cancelled', 50);
    });

    $numbers = fn ($response) => array_column($response->viewData('page')['props']['orders'], 'number');

    test('既定は受注日の新しい順（同じ日は作成の順の逆）。番号・ステータス・顧客・期間で絞り込み', function () use ($numbers) {
        expect($numbers($this->get(route('sales-orders.index'))))->toBe(['SO-20261002-1000', 'SO-20261002-002', 'SO-20261002-001', 'SO-20261001-001'])
            ->and($numbers($this->get(route('sales-orders.index', ['q' => '1002-00']))))->toBe(['SO-20261002-002', 'SO-20261002-001'])
            ->and($numbers($this->get(route('sales-orders.index', ['q' => '%']))))->toBe([])
            ->and($numbers($this->get(route('sales-orders.index', ['status' => 'confirmed']))))->toBe(['SO-20261002-001'])
            ->and($numbers($this->get(route('sales-orders.index', ['customer' => (string) $this->s2->id]))))->toBe(['SO-20261002-1000', 'SO-20261002-002'])
            ->and($numbers($this->get(route('sales-orders.index', ['from' => '2026-10-01', 'to' => '2026-10-01']))))->toBe(['SO-20261001-001'])
            // 不正な値は既定値
            ->and($numbers($this->get(route('sales-orders.index', ['status' => 'x', 'from' => '2026-02-30', 'sort' => 'id;drop']))))->toHaveCount(4);
    });

    test('並べ替え: 番号は桁数も見る（-1000 は -999 の後）・合計・顧客', function () use ($numbers) {
        // 同じ日の 999（文字列の順では -1000 が -999 より前になる。桁数で並べないと昇順・降順とも崩れる）
        SalesOrder::factory()->create([
            'customer_id' => $this->s2->id, 'number' => 'SO-20261002-999', 'order_date' => '2026-10-02', 'status' => 'shipped',
            'subtotal' => 150, 'tax' => 0, 'total' => 150,
        ]);
        $ascending = ['SO-20261001-001', 'SO-20261002-001', 'SO-20261002-002', 'SO-20261002-999', 'SO-20261002-1000'];

        expect($numbers($this->get(route('sales-orders.index', ['sort' => 'number', 'order' => 'asc']))))->toBe($ascending)
            ->and($numbers($this->get(route('sales-orders.index', ['sort' => 'number', 'order' => 'desc']))))->toBe(array_reverse($ascending))
            ->and($numbers($this->get(route('sales-orders.index', ['sort' => 'total', 'order' => 'asc']))))->toBe(['SO-20261002-1000', 'SO-20261002-001', 'SO-20261002-999', 'SO-20261002-002', 'SO-20261001-001'])
            ->and($numbers($this->get(route('sales-orders.index', ['sort' => 'customer', 'order' => 'asc']))))->toBe(['SO-20261001-001', 'SO-20261002-001', 'SO-20261002-002', 'SO-20261002-1000', 'SO-20261002-999']);
    });

    test('日付の絞り込みに NULL バイトなど形の違う値が来ても例外にせず、条件なしとして扱う（一覧・CSV）', function (string $field) use ($numbers) {
        foreach (["2026-10\0-19", "2026\0-10-01", '2026-10-1', '２０２６-10-01', "2026-10-01\n2026"] as $value) {
            expect($numbers($this->get(route('sales-orders.index', [$field => $value]))))->toHaveCount(4);
            $csv = $this->get(route('sales-orders.export', [$field => $value]));
            $csv->assertOk();
            expect(CsvParser::parse(substr((string) $csv->getContent(), 3)))->toHaveCount(5); // 見出し＋4 件
        }
    })->with(['from', 'to']);

    test('20 件ずつページ送り（範囲外のページは最終ページ）', function () {
        SalesOrder::factory()->count(20)->create(['customer_id' => $this->s1->id, 'order_date' => '2026-09-01']);

        $this->get(route('sales-orders.index', ['page' => '9']))->assertInertia(fn (Assert $page) => $page
            ->component('sales-orders/Index')
            ->has('orders', 4)
            ->where('pagination', ['total' => 24, 'page' => 2, 'totalPages' => 2, 'perPage' => 20])
            ->where('filters.page', 2));
    });

    test('詳細: 明細・金額・version を渡す。遷移の選択肢', function () {
        $order = SalesOrder::query()->where('number', 'SO-20261002-001')->sole();
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => soProduct(['code' => 'P-0001'])->id, 'quantity' => 2, 'unit_price' => 50]);

        $this->get(route('sales-orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->component('sales-orders/Show')
            ->where('order.number', 'SO-20261002-001')
            ->where('order.status_label', '確定')
            ->where('order.lines.0.product_code', 'P-0001')
            ->where('order.lines.0.amount', 100)
            ->where('order.version', soVersion($order))
            ->where('transitions', ['shipped', 'cancelled']));
    });

    test('CSV: UTF-8（BOM 付き）・見出し・一覧と同じ絞り込み。数式になりうるセルは無害化', function () {
        $response = $this->get(route('sales-orders.export', ['customer' => (string) $this->s1->id, 'page' => '2']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="sales_orders_'.now()->format('Ymd').'.csv"');
        $body = (string) $response->getContent();
        expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and(substr($body, 3))->toBe(
                "受注番号,受注日,納期,顧客コード,顧客名,ステータス,明細数,小計,消費税,合計,備考\r\n"
                ."SO-20261002-001,2026-10-02,,C-0001,Ａ商事,確定,0,100,0,100,'-至急\r\n"
                ."SO-20261001-001,2026-10-01,,C-0001,Ａ商事,下書き,0,300,0,300,\r\n",
            );
        $records = CsvParser::parse(substr((string) $this->get(route('sales-orders.export', ['customer' => (string) $this->s2->id]))->getContent(), 3));
        expect($records[1]->cells[4])->toBe("'=cmd|calc");
    });

    test('CSV: 上限を超えたら出力せず 413（黙って切り捨てない）。絞り込めば出力できる', function () {
        $now = now();
        foreach (array_chunk(range(1, SalesOrderQuery::EXPORT_MAX_ROWS + 1), 1000) as $chunk) {
            DB::table('sales_orders')->insert(array_map(fn (int $i) => [
                'number' => sprintf('SO-20260901-%05d', $i), 'customer_id' => $this->s1->id, 'order_date' => '2026-09-01',
                'status' => 'draft', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $this->get(route('sales-orders.export'))->assertStatus(413)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText('件数が多すぎます。絞り込んでから出力してください（上限 10,000 件）');
        $this->get(route('sales-orders.export', ['from' => '2026-10-01']))->assertOk();
    });

    test('CSV: 上限の判定（上限ちょうどは出力・超えたら null）', function () {
        $query = new SalesOrderQuery;
        $params = SalesOrderListParams::fromQuery([]);
        expect($query->forExport($params, 4))->toHaveCount(4)
            ->and($query->forExport($params, 3))->toBeNull();
    });
});

describe('権限（ルートの can: と Policy の二重）', function () {
    test('viewer は一覧・詳細・CSV 出力だけ。作成・編集・ステータス変更は 403', function () {
        $viewer = User::factory()->create();
        $order = SalesOrder::factory()->create();
        $p = soProduct();
        $this->actingAs($viewer);

        $this->get(route('sales-orders.index'))->assertOk();
        $this->get(route('sales-orders.show', $order))->assertOk();
        $this->get(route('sales-orders.export'))->assertOk();
        $this->get(route('sales-orders.create'))->assertForbidden();
        $this->post(route('sales-orders.store'), soInput($order->customer, [[$p, 1, 1]]))->assertForbidden();
        $this->get(route('sales-orders.edit', $order))->assertForbidden();
        $this->put(route('sales-orders.update', $order), [...soInput($order->customer, [[$p, 1, 1]]), 'version' => soVersion($order)])->assertForbidden();
        $this->post(route('sales-orders.status', $order), ['to' => 'cancelled', 'version' => soVersion($order)])->assertForbidden();
        expect(SalesOrder::query()->count())->toBe(1)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Draft);
    });

    test('admin・staff は作成・編集・ステータス変更ができる。未ログインはログイン画面へ', function () {
        foreach ([User::factory()->admin()->create(), User::factory()->staff()->create()] as $user) {
            $this->actingAs($user)->get(route('sales-orders.create'))->assertOk();
        }
        auth()->logout();
        $this->get(route('sales-orders.index'))->assertRedirect(route('login'));
    });

    test('Policy: ルートの can: を通っても、無効ユーザー・viewer は Policy で拒否する', function () {
        $order = SalesOrder::factory()->create();
        $viewer = User::factory()->create();
        $inactiveStaff = User::factory()->staff()->inactive()->create();
        $staff = User::factory()->staff()->create();

        foreach (['create' => SalesOrder::class, 'update' => $order, 'changeStatus' => $order] as $ability => $target) {
            expect($viewer->can($ability, $target))->toBeFalse()
                ->and($inactiveStaff->can($ability, $target))->toBeFalse()
                ->and($staff->can($ability, $target))->toBeTrue();
        }
        expect($viewer->can('export', SalesOrder::class))->toBeTrue()
            ->and($viewer->can('view', $order))->toBeTrue();
    });
});

describe('操作履歴とトランザクション', function () {
    test('操作履歴の保存に失敗したら、作成・編集・ステータス変更も取り消す（採番も戻る）', function () {
        $staff = $this->staff;
        $customer = soCustomer();
        $p = soProduct();
        $this->actingAs($staff)->post(route('sales-orders.store'), soInput($customer, [[$p, 1, 100]]));
        $order = SalesOrder::query()->sole();
        $sequence = DB::table('document_sequences')->value('last_number');

        AuditLog::creating(function () {
            throw new RuntimeException('操作履歴の保存に失敗（テスト）');
        });
        $service = app(SalesOrderService::class);
        $input = ['customer_id' => $customer->id, 'due_date' => null, 'note' => null, 'lines' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 100]]];

        expect(fn () => $service->create($staff, $input))->toThrow(RuntimeException::class, '操作履歴の保存に失敗（テスト）')
            ->and(fn () => $service->update($staff, $order->id, $input, soVersion($order)))->toThrow(RuntimeException::class)
            ->and(fn () => $service->changeStatus($staff, $order->id, SalesOrderStatus::Confirmed, soVersion($order)))->toThrow(RuntimeException::class);

        expect(SalesOrder::query()->count())->toBe(1)
            ->and(DB::table('document_sequences')->value('last_number'))->toBe($sequence)
            ->and(SalesOrderLine::query()->sole()->quantity)->toBe(1)
            ->and($order->fresh()->status)->toBe(SalesOrderStatus::Draft)
            ->and(AuditLog::query()->count())->toBe(1);
    });
});
