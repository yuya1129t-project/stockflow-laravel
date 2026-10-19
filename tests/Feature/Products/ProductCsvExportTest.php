<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Products\ProductListParams;
use App\Products\ProductQuery;
use App\Support\Csv\CsvParser;
use Illuminate\Support\Facades\DB;

/** 商品の CSV 出力（docs/spec.md §7） */
beforeEach(function () {
    $this->actingAs(User::factory()->create()); // viewer も出力できる
});

test('UTF-8（BOM 付き）・見出し行あり・CRLF。現在庫と状態を含む', function () {
    $product = Product::factory()->create([
        'code' => 'P-0001', 'name' => 'ペン, 黒', 'category' => '文具', 'unit' => '本',
        'sales_price' => 150, 'purchase_price' => 90, 'reorder_point' => 20,
    ]);
    StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::In, 'quantity' => 12]);

    $response = $this->get(route('products.export'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="products_'.now()->format('Ymd').'.csv"')
        ->assertHeader('Cache-Control', 'no-store, private');
    $body = $response->getContent();
    expect(substr((string) $body, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(substr((string) $body, 3))->toBe(
            "商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点,現在庫,状態\r\n"
            ."P-0001,\"ペン, 黒\",文具,本,150,90,20,12,有効\r\n",
        );
});

test('一覧と同じ絞り込み・並べ替えを反映する（ページ送りはしない）', function () {
    foreach (range(1, 25) as $i) {
        Product::factory()->create(['code' => sprintf('P-%04d', $i), 'category' => '文具']);
    }
    Product::factory()->create(['code' => 'X-0001', 'category' => '日用品']);
    Product::factory()->inactive()->create(['code' => 'P-0099', 'category' => '文具']);

    $codes = function (array $query): array {
        $records = CsvParser::parse((string) $this->get(route('products.export', $query))->getContent());

        return array_map(fn ($r) => $r->cells[0], array_slice($records, 1));
    };

    expect($codes(['category' => '文具', 'page' => 2]))->toHaveCount(25)
        ->and($codes(['q' => 'X-']))->toBe(['X-0001'])
        ->and($codes(['status' => 'inactive']))->toBe(['P-0099'])
        ->and(array_slice($codes(['sort' => 'code', 'order' => 'desc', 'status' => 'all']), 0, 2))->toBe(['X-0001', 'P-0099']);
});

test('数式として解釈されうるセルは無害化する', function () {
    Product::factory()->create(['code' => 'P-0001', 'name' => '=HYPERLINK("http://example.com")', 'category' => '+81', 'unit' => '@x']);
    Product::factory()->create(['code' => 'P-0002', 'name' => "'-特価品", 'category' => '-1', 'unit' => '個']);

    $records = CsvParser::parse((string) $this->get(route('products.export'))->getContent());

    expect(array_slice($records[1]->cells, 1, 3))->toBe(["'=HYPERLINK(\"http://example.com\")", "'+81", "'@x"])
        ->and(array_slice($records[2]->cells, 1, 3))->toBe(["''-特価品", "'-1", '個']);
});

test('上限を超える件数は出力せず 413 と日本語のメッセージ（黙って切り捨てない）', function () {
    $now = now();
    foreach (array_chunk(range(1, ProductQuery::EXPORT_MAX_ROWS + 1), 1000) as $chunk) {
        DB::table('products')->insert(array_map(fn (int $i) => [
            'code' => sprintf('Z-%05d', $i), 'name' => "商品{$i}", 'category' => '文具', 'unit' => '個',
            'sales_price' => 100, 'purchase_price' => 50, 'reorder_point' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $this->get(route('products.export'))
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSeeText('件数が多すぎます。絞り込んでから出力してください（上限 10,000 件）');

    // 絞り込めば出力できる
    $this->get(route('products.export', ['q' => 'Z-0000']))->assertOk();
});

test('上限ちょうどまでは出力する（forExport の境界）', function () {
    Product::factory()->count(3)->create();
    $query = app(ProductQuery::class);
    $params = ProductListParams::fromQuery([]);

    expect($query->forExport($params, 3))->toHaveCount(3)
        ->and($query->forExport($params, 2))->toBeNull();
});
