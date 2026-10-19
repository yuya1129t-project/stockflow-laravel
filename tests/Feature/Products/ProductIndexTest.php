<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** 商品一覧（検索・並べ替え・ページ送り・現在庫と発注点割れ。docs/spec.md §5-3） */
function addStock(Product $product, int $quantity): void
{
    StockMovement::factory()->create([
        'product_id' => $product->id,
        'type' => $quantity > 0 ? StockMovementType::In : StockMovementType::Out,
        'quantity' => $quantity,
    ]);
}

/** @return list<string> */
function listedCodes(TestResponse $response): array
{
    return array_column($response->viewData('page')['props']['products'], 'code');
}

beforeEach(function () {
    $this->actingAs(User::factory()->create()); // viewer でも一覧は見られる
});

test('現在庫（入出庫の合計）と発注点割れを返す', function () {
    $low = Product::factory()->create(['code' => 'P-0001', 'reorder_point' => 10]);
    addStock($low, 12);
    addStock($low, -5);
    $enough = Product::factory()->create(['code' => 'P-0002', 'reorder_point' => 10]);
    addStock($enough, 10);
    Product::factory()->create(['code' => 'P-0003', 'reorder_point' => 0]); // 入出庫なし

    $this->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/Index')
            ->has('products', 3)
            ->where('products.0.code', 'P-0001')
            ->where('products.0.stock', 7)
            ->where('products.0.is_low_stock', true)
            ->where('products.1.stock', 10)
            ->where('products.1.is_low_stock', false)
            ->where('products.2.stock', 0)
            ->where('products.2.is_low_stock', false)
            ->where('pagination', ['total' => 3, 'page' => 1, 'totalPages' => 1, 'perPage' => 20])
            ->where('filters', ['q' => '', 'category' => '', 'status' => 'active', 'sort' => 'code', 'order' => 'asc', 'page' => 1])
            ->where('exportMaxRows', 10_000));
});

test('無効の商品は発注点割れにしない', function () {
    Product::factory()->inactive()->create(['reorder_point' => 10]);

    $this->get(route('products.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->where('products.0.is_low_stock', false));
});

test('キーワードはコード・名前・カテゴリの部分一致。カテゴリは完全一致で絞り込む', function () {
    Product::factory()->create(['code' => 'P-1001', 'name' => '油性ペン', 'category' => '文具']);
    Product::factory()->create(['code' => 'P-1002', 'name' => 'ノート', 'category' => '文具']);
    Product::factory()->create(['code' => 'X-1003', 'name' => '段ボール', 'category' => 'ペン関連']);
    Product::factory()->create(['code' => 'P-1004', 'name' => '洗剤', 'category' => '日用品']);

    expect(listedCodes($this->get(route('products.index', ['q' => 'ペン']))))->toBe(['P-1001', 'X-1003'])
        ->and(listedCodes($this->get(route('products.index', ['q' => 'p-100']))))->toBe(['P-1001', 'P-1002', 'P-1004'])
        ->and(listedCodes($this->get(route('products.index', ['category' => '文具']))))->toBe(['P-1001', 'P-1002'])
        ->and(listedCodes($this->get(route('products.index', ['q' => 'ノート', 'category' => '文具']))))->toBe(['P-1002']);
});

test('キーワードの % と _ は文字として扱う（LIKE のワイルドカードにしない）', function () {
    Product::factory()->create(['code' => 'P-0001', 'name' => '100%果汁']);
    Product::factory()->create(['code' => 'P-0002', 'name' => '100円']);
    Product::factory()->create(['code' => 'P_0003', 'name' => 'ノート']);

    expect(listedCodes($this->get(route('products.index', ['q' => '100%']))))->toBe(['P-0001'])
        ->and(listedCodes($this->get(route('products.index', ['q' => 'P_']))))->toBe(['P_0003']);
});

test('状態: 既定は有効のみ。無効も表示・無効のみに切り替えられる', function () {
    Product::factory()->create(['code' => 'P-0001']);
    Product::factory()->inactive()->create(['code' => 'P-0002']);

    expect(listedCodes($this->get(route('products.index'))))->toBe(['P-0001'])
        ->and(listedCodes($this->get(route('products.index', ['status' => 'all']))))->toBe(['P-0001', 'P-0002'])
        ->and(listedCodes($this->get(route('products.index', ['status' => 'inactive']))))->toBe(['P-0002']);
});

test('並べ替え: 許可した列で昇順・降順。同じ値はコード順', function () {
    $a = Product::factory()->create(['code' => 'P-0001', 'name' => 'う', 'sales_price' => 300, 'reorder_point' => 5]);
    $b = Product::factory()->create(['code' => 'P-0002', 'name' => 'あ', 'sales_price' => 100, 'reorder_point' => 5]);
    $c = Product::factory()->create(['code' => 'P-0003', 'name' => 'い', 'sales_price' => 200, 'reorder_point' => 1]);
    addStock($a, 5);
    addStock($b, 30);
    addStock($c, 10);

    $codes = fn (array $query) => listedCodes($this->get(route('products.index', $query)));

    expect($codes(['sort' => 'code', 'order' => 'desc']))->toBe(['P-0003', 'P-0002', 'P-0001'])
        ->and($codes(['sort' => 'name']))->toBe(['P-0002', 'P-0003', 'P-0001'])
        ->and($codes(['sort' => 'salesPrice', 'order' => 'desc']))->toBe(['P-0001', 'P-0003', 'P-0002'])
        ->and($codes(['sort' => 'reorderPoint']))->toBe(['P-0003', 'P-0001', 'P-0002'])
        ->and($codes(['sort' => 'stock']))->toBe(['P-0001', 'P-0003', 'P-0002'])
        ->and($codes(['sort' => 'stock', 'order' => 'desc']))->toBe(['P-0002', 'P-0003', 'P-0001']);
});

test('不正な検索条件は既定値にする（許可していない列では並べ替えない）', function () {
    Product::factory()->create(['code' => 'P-0002']);
    Product::factory()->create(['code' => 'P-0001']);

    $this->get(route('products.index', ['sort' => 'purchase_price; DROP TABLE products', 'order' => 'sideways', 'status' => 'x', 'page' => '-1', 'q' => ['a']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['q' => '', 'category' => '', 'status' => 'active', 'sort' => 'code', 'order' => 'asc', 'page' => 1])
            ->where('products.0.code', 'P-0001'));
});

test('ページ送り: 20 件ずつ。範囲外のページは最終ページに丸める', function () {
    foreach (range(1, 45) as $i) {
        Product::factory()->create(['code' => sprintf('P-%04d', $i)]);
    }

    $this->get(route('products.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 20)
            ->where('products.0.code', 'P-0021')
            ->where('pagination', ['total' => 45, 'page' => 2, 'totalPages' => 3, 'perPage' => 20]));

    $this->get(route('products.index', ['page' => 99]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 5)
            ->where('products.0.code', 'P-0041')
            ->where('pagination.page', 3)
            ->where('filters.page', 3));
});

test('カテゴリの選択肢は登録済みのカテゴリ（重複なし・昇順）', function () {
    Product::factory()->create(['category' => '文具']);
    Product::factory()->create(['category' => '日用品']);
    Product::factory()->inactive()->create(['category' => '文具']);

    $this->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', ['文具', '日用品']));
});

test('現在庫は N+1 にならない（商品・入出庫の件数によらず、問い合わせの回数が同じ）', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('products.index', ['sort' => 'stock']))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $product = Product::factory()->create();
    addStock($product, 5);
    $few = $countQueries();

    foreach (Product::factory()->count(19)->create() as $p) {
        addStock($p, 10);
        addStock($p, -3);
    }
    $many = $countQueries();

    expect($many)->toBe($few);
});
