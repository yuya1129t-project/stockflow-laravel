<?php

use App\Api\V1\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\OpenApiContract;

/**
 * 契約テストの検証器（tests/Support/OpenApiContract.php）が、docs/openapi.json との食い違いを実際に検出することの確認。
 * 見本の応答は実装の応答の部品（ApiResponse）で作り、1 か所ずつ壊して落ちることを見る
 */
beforeEach(function () {
    // 先に動いた結合テストの記録を持ち込まない
    OpenApiContract::$checked = [];
});

afterEach(function () {
    // 見本の応答の記録を、REST API の結合テスト（定義した応答をすべて返したかの確認）に持ち込まない
    OpenApiContract::$checked = [];
});

function contractProduct(array $override = []): array
{
    return [
        'code' => 'P-0001',
        'name' => '商品',
        'category' => '文具',
        'unit' => '個',
        'salesPrice' => 100,
        'purchasePrice' => 50,
        'reorderPoint' => 5,
        'isActive' => true,
        'stock' => 3,
        'belowReorderPoint' => true,
        ...$override,
    ];
}

function contractOrder(array $override = []): array
{
    return [
        'number' => 'SO-20261019-001',
        'orderDate' => '2026-10-19',
        'dueDate' => null,
        'status' => 'draft',
        'customer' => ['code' => 'C-0001', 'name' => '顧客'],
        'note' => null,
        'subtotal' => 100,
        'tax' => 10,
        'total' => 110,
        'lines' => [['product' => ['code' => 'P-0001', 'name' => '商品', 'unit' => '個'], 'quantity' => 1, 'unitPrice' => 100, 'amount' => 100]],
        'createdAt' => '2026-10-19T03:00:00.000Z',
        'updatedAt' => '2026-10-19T03:00:00.000Z',
        ...$override,
    ];
}

function contractList(array $item): TestResponse
{
    return TestResponse::fromBaseResponse(ApiResponse::json(['data' => [$item], 'meta' => ['page' => 1, 'perPage' => 20, 'total' => 1]]));
}

test('定義どおりの応答は通り、検証した応答を記録する', function () {
    OpenApiContract::assertResponse('get', '/api/v1/products', contractList(contractProduct()));
    OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::unauthorized()));
    OpenApiContract::assertResponse('post', '/api/v1/sales-orders', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(), 201, ['Location' => '/api/v1/sales-orders/SO-20261019-001'])));
    OpenApiContract::assertResponse('post', '/api/v1/sales-orders', TestResponse::fromBaseResponse(ApiResponse::validation([['pointer' => '#/lines', 'detail' => '必須の項目です']])));

    expect(array_keys(OpenApiContract::$checked))->toBe([
        'get /api/v1/products 200',
        'get /api/v1/products 401',
        'post /api/v1/sales-orders 201',
        'post /api/v1/sales-orders 400',
    ]);
});

test('定義との食い違いを検出する', function (Closure $check, string $message) {
    expect($check)->toThrow(AssertionFailedError::class, $message);
    expect(OpenApiContract::$checked)->toBe([]);
})->with([
    '定義にない項目（内部の ID）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', contractList(contractProduct(['id' => 1]))), '定義のスキーマに合いません'],
    '必須の項目がない' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', contractList(array_diff_key(contractProduct(), ['stock' => true]))), '定義のスキーマに合いません'],
    '型が違う（金額が文字列）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', contractList(contractProduct(['salesPrice' => '100']))), '定義のスキーマに合いません'],
    'null にできない項目が null' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/sales-orders/{number}', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(['note' => null, 'customer' => null])))), '定義のスキーマに合いません'],
    '日時の形式が違う' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/sales-orders/{number}', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(['createdAt' => '2026-10-19 12:00:00'])))), '定義のスキーマに合いません'],
    '日付が範囲外（2100 年）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/sales-orders/{number}', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(['orderDate' => '2100-01-01'])))), '定義のスキーマに合いません'],
    'ステータスの値が定義にない' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/sales-orders/{number}', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(['status' => 'open'])))), '定義のスキーマに合いません'],
    'perPage が上限超え' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::json(['data' => [], 'meta' => ['page' => 1, 'perPage' => 101, 'total' => 0]]))), '定義のスキーマに合いません'],
    'Problem に定義にない項目' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(new JsonResponse(['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'x', 'instance' => '/x'], 400, ['Content-Type' => 'application/problem+json']))), '定義のスキーマに合いません'],
    '項目ごとのエラーに定義にない項目' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::validation([['field' => 'q', 'detail' => 'x']]))), '定義のスキーマに合いません'],
    '定義にないステータス（GET 一覧の 404）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::problem(404, 'x'))), '定義にないステータスです'],
    '定義にないステータス（405）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::problem(405, 'x'))), '定義にないステータスです'],
    '定義にない Content-Type（エラーを application/json で返す）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(new JsonResponse(['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'x'], 400))), '定義にない Content-Type です'],
    '定義にあるヘッダーがない（401 の WWW-Authenticate）' => [fn () => OpenApiContract::assertResponse('get', '/api/v1/products', TestResponse::fromBaseResponse(ApiResponse::problem(401, 'x'))), '定義にあるヘッダーがありません'],
    '定義にあるヘッダーがない（201 の Location）' => [fn () => OpenApiContract::assertResponse('post', '/api/v1/sales-orders', TestResponse::fromBaseResponse(ApiResponse::json(contractOrder(), 201))), '定義にあるヘッダーがありません'],
    '定義にあるヘッダーがない（415 の Accept-Post）' => [fn () => OpenApiContract::assertResponse('post', '/api/v1/sales-orders', TestResponse::fromBaseResponse(ApiResponse::problem(415, 'x'))), '定義にあるヘッダーがありません'],
    '定義にない操作' => [fn () => OpenApiContract::assertResponse('post', '/api/v1/products', contractList(contractProduct())), '定義にない操作です'],
]);

test('定義にある応答の一覧（パス × メソッド × ステータス）', function () {
    expect(OpenApiContract::documentedResponses())->toContain('get /api/v1/products 200', 'post /api/v1/sales-orders 413', 'get /api/v1/openapi.json 200')
        ->and(count(OpenApiContract::documentedResponses()))->toBe(25);
});
