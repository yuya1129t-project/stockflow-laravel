<?php

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use App\Http\Controllers\StockController;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Stock\StockAdjustment;
use App\Stock\StockQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** 在庫の画面（現在庫一覧・入出庫履歴・棚卸調整。docs/spec.md §2・§4・§5-7） */
function stockPageProduct(int $stock, array $attributes = []): Product
{
    $product = Product::factory()->create($attributes);
    if ($stock > 0) {
        StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => $stock]);
    }

    return $product;
}

/** @return list<string> */
function stockListedCodes(TestResponse $response): array
{
    return array_column($response->viewData('page')['props']['products'], 'code');
}

test('現在庫一覧: 現在庫と発注点割れを返す。「発注点割れのみ」は有効で 現在庫 < 発注点 の商品だけ（件数も一致）', function () {
    $this->actingAs(User::factory()->create()); // viewer でも見られる
    stockPageProduct(3, ['code' => 'P-0001', 'reorder_point' => 5]);
    stockPageProduct(5, ['code' => 'P-0002', 'reorder_point' => 5]); // 発注点ちょうどは割れていない
    stockPageProduct(0, ['code' => 'P-0003', 'reorder_point' => 1]); // 入出庫なしで割れている
    stockPageProduct(0, ['code' => 'P-0004', 'reorder_point' => 5, 'is_active' => false]); // 無効は割れにしない

    $this->get(route('stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stock/Index')
            ->has('products', 3)
            ->where('products.0.code', 'P-0001')
            ->where('products.0.stock', 3)
            ->where('products.0.is_low_stock', true)
            ->where('products.1.is_low_stock', false)
            ->where('filters', ['q' => '', 'category' => '', 'status' => 'active', 'sort' => 'code', 'order' => 'asc', 'page' => 1, 'level' => 'all']));

    $this->get(route('stock.index', ['level' => 'low', 'status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 2)
            ->where('filters.level', 'low'));
    expect(stockListedCodes($this->get(route('stock.index', ['level' => 'low', 'status' => 'all']))))->toBe(['P-0001', 'P-0003'])
        // 不正な値は既定値（すべて）
        ->and(stockListedCodes($this->get(route('stock.index', ['level' => 'bogus']))))->toBe(['P-0001', 'P-0002', 'P-0003']);
});

test('現在庫一覧: 検索・カテゴリ・並べ替え（現在庫）・ページ送りは商品一覧と同じ。発注点割れの絞り込みと組み合わせられる', function () {
    $this->actingAs(User::factory()->create());
    stockPageProduct(8, ['code' => 'P-1001', 'name' => '油性ペン', 'category' => '文具', 'reorder_point' => 10]);
    stockPageProduct(2, ['code' => 'P-1002', 'name' => 'ノート', 'category' => '文具', 'reorder_point' => 10]);
    stockPageProduct(30, ['code' => 'P-1003', 'name' => 'ペン立て', 'category' => '事務用品', 'reorder_point' => 10]);

    expect(stockListedCodes($this->get(route('stock.index', ['q' => 'ペン']))))->toBe(['P-1001', 'P-1003'])
        ->and(stockListedCodes($this->get(route('stock.index', ['category' => '文具', 'level' => 'low', 'sort' => 'stock']))))->toBe(['P-1002', 'P-1001'])
        ->and(stockListedCodes($this->get(route('stock.index', ['sort' => 'stock', 'order' => 'desc']))))->toBe(['P-1003', 'P-1001', 'P-1002']);

    foreach (range(1, 21) as $i) {
        stockPageProduct(0, ['code' => sprintf('Q-%04d', $i), 'reorder_point' => 1]);
    }
    $this->get(route('stock.index', ['level' => 'low', 'page' => '2']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 3) // 割れている 23 件（P-1001・P-1002・Q-0001〜0021）の 21〜23 件目
            ->where('pagination', ['total' => 23, 'page' => 2, 'totalPages' => 2, 'perPage' => 20]));
    // 範囲外のページは最終ページに丸める
    $this->get(route('stock.index', ['level' => 'low', 'page' => '99']))
        ->assertInertia(fn (Assert $page) => $page->where('filters.page', 2));
});

test('現在庫一覧は商品ごとに問い合わせない（N+1 にしない）', function () {
    $this->actingAs(User::factory()->create());
    foreach (range(1, 5) as $i) {
        stockPageProduct($i);
    }
    $this->get(route('stock.index', ['level' => 'low']))->assertOk();
    DB::enableQueryLog();
    $this->get(route('stock.index', ['level' => 'low']))->assertOk();
    $five = count(DB::getQueryLog());

    foreach (range(1, 10) as $i) {
        stockPageProduct($i);
    }
    DB::flushQueryLog();
    $this->get(route('stock.index', ['level' => 'low']))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($five);
});

test('入出庫履歴: 新しい順に 20 件ずつ。参照元の伝票番号と記録したユーザーを返す（N+1 にしない）', function () {
    $this->actingAs(User::factory()->create());
    $staff = User::factory()->staff()->create(['name' => '在庫 担当']);
    $product = stockPageProduct(0, ['reorder_point' => 5]);
    StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => 100, 'created_at' => now()->subDay()]);
    $sales = SalesOrder::factory()->create(['number' => 'SO-20261019-001']);
    $purchase = PurchaseOrder::factory()->create(['number' => 'PO-20261019-001']);
    foreach (range(1, 22) as $i) {
        StockMovement::factory()->create([
            'product_id' => $product->id, 'type' => StockMovementType::Out, 'quantity' => -1, 'reason' => null,
            'ref_type' => StockRefType::SalesOrder, 'ref_id' => $sales->id, 'user_id' => $staff->id,
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }
    StockMovement::factory()->create([
        'product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => 3,
        'ref_type' => StockRefType::PurchaseOrder, 'ref_id' => $purchase->id, 'created_at' => now()->subMinute(),
    ]);
    $latest = StockMovement::factory()->create([
        'product_id' => $product->id, 'type' => StockMovementType::Adjust, 'quantity' => -2, 'reason' => '破損',
        'user_id' => $staff->id, 'created_at' => now(),
    ]);
    // 伝票が見つからない参照（消えた伝票）は番号なし
    StockMovement::factory()->create([
        'product_id' => $product->id, 'type' => StockMovementType::Out, 'quantity' => -1,
        'ref_type' => StockRefType::SalesOrder, 'ref_id' => 999_999, 'created_at' => now()->subYear(),
    ]);

    DB::enableQueryLog();
    $this->get(route('stock.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stock/Show')
            ->where('product.id', $product->id)
            ->where('product.stock', 100 - 22 + 3 - 2 - 1)
            ->has('movements', 20)
            ->where('movements.0', [
                'id' => $latest->id, 'created_at' => $latest->created_at?->format('Y-m-d H:i'), 'type' => 'adjust',
                'quantity' => -2, 'reason' => '破損', 'ref' => null, 'user_name' => '在庫 担当',
            ])
            ->where('movements.1.ref', ['type' => 'purchase_order', 'id' => $purchase->id, 'number' => 'PO-20261019-001'])
            ->where('movements.1.user_name', null)
            ->where('movements.2.ref', ['type' => 'sales_order', 'id' => $sales->id, 'number' => 'SO-20261019-001'])
            ->where('pagination', ['total' => 26, 'page' => 1, 'totalPages' => 2, 'perPage' => 20])
            ->where('limits', ['actualQuantity' => 9_999_999, 'reason' => 200]));
    $queries = count(DB::getQueryLog());

    $this->get(route('stock.show', [$product, 'page' => '2']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movements', 6)
            ->where('movements.5.ref', ['type' => 'sales_order', 'id' => 999_999, 'number' => null])
            ->where('pagination.page', 2));
    // 不正なページは 1、範囲外は最終ページ
    $this->get(route('stock.show', [$product, 'page' => 'x']))->assertInertia(fn (Assert $page) => $page->where('pagination.page', 1));
    $this->get(route('stock.show', [$product, 'page' => '50']))->assertInertia(fn (Assert $page) => $page->where('pagination.page', 2));

    // 履歴が 2 件（受注・発注の参照とユーザーつき）の商品と、問い合わせの回数が同じ（行ごとに問い合わせない）
    $small = stockPageProduct(0);
    StockMovement::factory()->create(['product_id' => $small->id, 'type' => StockMovementType::In, 'quantity' => 1, 'ref_type' => StockRefType::PurchaseOrder, 'ref_id' => $purchase->id, 'user_id' => $staff->id]);
    StockMovement::factory()->create(['product_id' => $small->id, 'type' => StockMovementType::Out, 'quantity' => -1, 'ref_type' => StockRefType::SalesOrder, 'ref_id' => $sales->id, 'user_id' => $staff->id]);
    DB::flushQueryLog();
    $this->get(route('stock.show', $small))->assertInertia(fn (Assert $page) => $page->has('movements', 2));
    expect(count(DB::getQueryLog()))->toBe($queries);
});

test('入出庫のない商品は 0 件・1 ページ。存在しない商品は 404', function () {
    $this->actingAs(User::factory()->create());
    $product = stockPageProduct(0);

    $this->get(route('stock.show', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movements', 0)
            ->where('pagination', ['total' => 0, 'page' => 1, 'totalPages' => 1, 'perPage' => 20]));
    $this->get('/stock/999999')->assertNotFound();
});

test('棚卸調整: 実数との差分を adjust で記録し、操作履歴を残して在庫の画面に戻る', function () {
    $staff = User::factory()->staff()->create();
    $product = stockPageProduct(10, ['code' => 'P-0001', 'unit' => '個', 'reorder_point' => 8]);

    $this->actingAs($staff)
        ->post(route('stock.adjust', $product), ['actual_quantity' => '7', 'reason' => '  月末棚卸  '])
        ->assertRedirect(route('stock.show', $product))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '在庫を 10 → 7 個に調整しました（差分 -3）']);

    $movement = StockMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    expect($movement->type)->toBe(StockMovementType::Adjust)
        ->and($movement->quantity)->toBe(-3)
        ->and($movement->reason)->toBe('月末棚卸')
        ->and($movement->ref_type)->toBeNull()
        ->and($movement->user_id)->toBe($staff->id)
        ->and(AuditLog::query()->where('action', StockAdjustment::ACTION)->sole()->only(['user_id', 'target_type', 'target_id', 'summary']))
        ->toBe([
            'user_id' => $staff->id,
            'target_type' => 'product',
            'target_id' => (string) $product->id,
            'summary' => '商品 P-0001 の在庫を棚卸で調整しました（10 → 7個、差分 -3、理由: 月末棚卸）',
        ]);
});

test('棚卸調整: 増やす調整・0 への調整もできる（全角数字・桁区切りも受け付ける）', function () {
    $admin = User::factory()->admin()->create();
    $product = stockPageProduct(5);

    $this->actingAs($admin)->post(route('stock.adjust', $product), ['actual_quantity' => '１,２００', 'reason' => '入荷漏れ'])->assertSessionHasNoErrors();
    expect((new StockQuery)->stock($product->id))->toBe(1_200);

    $this->actingAs($admin)->post(route('stock.adjust', $product), ['actual_quantity' => '0', 'reason' => '全数廃棄'])->assertSessionHasNoErrors();
    expect((new StockQuery)->stock($product->id))->toBe(0)
        ->and(AuditLog::query()->where('action', StockAdjustment::ACTION)->count())->toBe(2);
});

test('棚卸調整: 差分が 0 なら記録しない（入出庫も操作履歴も増えない）', function () {
    $product = stockPageProduct(10);
    $movements = StockMovement::query()->count();

    $this->actingAs(User::factory()->staff()->create())
        ->post(route('stock.adjust', $product), ['actual_quantity' => '10', 'reason' => '棚卸'])
        ->assertRedirect(route('stock.show', $product))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '実数が現在庫と同じため、記録しませんでした']);

    expect(StockMovement::query()->count())->toBe($movements)
        ->and(AuditLog::query()->where('action', StockAdjustment::ACTION)->exists())->toBeFalse();
});

test('棚卸調整の入力の検証（日本語のメッセージ。エラーなら何も記録しない）', function (array $input, string $field, string $message) {
    $product = stockPageProduct(10);
    $movements = StockMovement::query()->count();

    $this->actingAs(User::factory()->staff()->create())
        ->post(route('stock.adjust', $product), [...['actual_quantity' => '5', 'reason' => '棚卸'], ...$input])
        ->assertSessionHasErrors([$field => $message]);

    expect(StockMovement::query()->count())->toBe($movements);
})->with([
    '実数が空' => [['actual_quantity' => ''], 'actual_quantity', '実数を入力してください'],
    '実数が負' => [['actual_quantity' => '-1'], 'actual_quantity', '実数は0以上の整数で入力してください'],
    '実数が小数' => [['actual_quantity' => '1.5'], 'actual_quantity', '実数は0以上の整数で入力してください'],
    '実数が文字' => [['actual_quantity' => 'abc'], 'actual_quantity', '実数は0以上の整数で入力してください'],
    '実数が上限超え' => [['actual_quantity' => '10000000'], 'actual_quantity', '実数は9,999,999以下で入力してください'],
    '理由が空' => [['reason' => ''], 'reason', '理由を入力してください'],
    '理由が空白だけ' => [['reason' => '　 '], 'reason', '理由を入力してください'],
    '理由が長すぎる' => [['reason' => str_repeat('あ', 201)], 'reason', '理由は200文字以内で入力してください'],
    '理由に改行' => [['reason' => "棚卸\n破損"], 'reason', '理由 に改行・タブなどの制御文字は使えません'],
]);

test('権限: viewer は棚卸調整が 403（何も記録しない）。閲覧は全ロール', function () {
    $product = stockPageProduct(10);

    $this->actingAs(User::factory()->create())
        ->post(route('stock.adjust', $product), ['actual_quantity' => '1', 'reason' => '棚卸'])
        ->assertForbidden();
    expect((new StockQuery)->stock($product->id))->toBe(10);

    foreach (Role::cases() as $role) {
        $user = User::factory()->role($role)->create();
        $this->actingAs($user)->get(route('stock.index'))->assertOk();
        $this->actingAs($user)->get(route('stock.show', $product))->assertOk();
    }
});

test('権限: Policy でも検査する（ルートの can: を外しても viewer は拒否される）', function () {
    $product = stockPageProduct(10);
    $viewer = User::factory()->create();

    // can: を付けないテスト用のルートで、Form Request の検査だけを確かめる
    Route::middleware(['web', 'auth'])->post('/_test/stock/{product}/adjust', [StockController::class, 'adjust']);
    $this->actingAs($viewer)
        ->post("/_test/stock/{$product->id}/adjust", ['actual_quantity' => '1', 'reason' => '棚卸'])
        ->assertForbidden();
    expect((new StockQuery)->stock($product->id))->toBe(10)
        ->and($viewer->can('adjustStock', $product))->toBeFalse()
        ->and(User::factory()->staff()->create()->can('adjustStock', $product))->toBeTrue()
        ->and(User::factory()->admin()->inactive()->create()->can('adjustStock', $product))->toBeFalse();
});

test('未ログインはログイン画面へ', function () {
    $product = stockPageProduct(1);

    $this->get(route('stock.index'))->assertRedirect(route('login'));
    $this->get(route('stock.show', $product))->assertRedirect(route('login'));
    $this->post(route('stock.adjust', $product), ['actual_quantity' => '1', 'reason' => 'x'])->assertRedirect(route('login'));
});
