<?php

use App\Api\V1\ApiJsonBody;
use App\Auth\Permission;
use App\Enums\PartnerKind;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Orders\OrderAmounts;
use App\SalesOrders\SalesOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\Support\OpenApiContract;
use Tests\Support\SessionRequests;

/**
 * 外部向け REST API（/api/v1。docs/spec.md §6-2）の結合テスト（MySQL）と契約テスト。Next.js 版 tests/integration/rest-api.test.ts と同じ観点。
 *
 * 応答はすべて docs/openapi.json（Next.js 版と共通の契約）で検証し（tests/Support/OpenApiContract.php。opis/json-schema）、
 * 最後に、定義した応答（パス × メソッド × ステータス）をすべて一度は実際に返して検証したことを確かめる（実装と定義の食い違いの検出）。
 * 定義にない 405・未定義のパスの 404 は、本文を Problem のスキーマで検証する。
 *
 * テストごとに専用のデータを作る（RefreshDatabase）。時刻は JST 2026-10-19 12:00 に固定する（受注日・採番・納期の判定）
 */
uses(SessionRequests::class);

beforeAll(function () {
    // 単体テスト（tests/Unit/Api）で作った見本の応答の記録を持ち込まない
    OpenApiContract::$checked = [];
});

/**
 * REST API を呼ぶ（Bearer トークン・ヘッダー・本文）。同じアプリのまま要求を続けるので、読み込み済みのユーザーを捨ててから送る
 *
 * @param  array{token?: string|null, headers?: array<string, string>, body?: mixed, raw?: string}  $options
 */
function restApi(string $method, string $uri, array $options = []): TestResponse
{
    app('auth')->forgetGuards();
    $server = [];
    if (($options['token'] ?? null) !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$options['token'];
    }
    foreach ($options['headers'] ?? [] as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_{$key}"] = $value;
    }
    $content = $options['raw'] ?? null;
    if (array_key_exists('body', $options)) {
        $content = json_encode($options['body'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $server['CONTENT_TYPE'] ??= 'application/json';
    }
    $response = test()->call($method, $uri, [], [], [], $server, $content);
    // Bearer の要求で auth:sanctum が既定のガードを sanctum に切り替えたままになるので、次の Web の要求のために戻す
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return $response;
}

/** 成功の応答を契約で検証して本文を返す */
function restApiOk(string $method, string $template, TestResponse $response, int $status = 200): array
{
    expect($response->getStatusCode())->toBe($status, (string) $response->getContent())
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private');
    $body = OpenApiContract::assertResponse($method, $template, $response);
    assert(is_array($body));

    return $body;
}

/** エラーの応答（RFC 9457）を契約で検証して本文を返す */
function restApiProblem(string $method, string $template, TestResponse $response, int $status): array
{
    expect($response->getStatusCode())->toBe($status, (string) $response->getContent())
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private');
    $body = OpenApiContract::assertResponse($method, $template, $response);
    assert(is_array($body));
    expect($body['status'])->toBe($status)->and($body['type'])->toBe('about:blank');

    return $body;
}

/** 定義にないエラー（405・未定義のパスの 404）は、本文を Problem のスキーマで検証する */
function restApiUndocumentedProblem(TestResponse $response, int $status): array
{
    expect($response->getStatusCode())->toBe($status, (string) $response->getContent())
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json');
    $body = $response->json();
    expect(OpenApiContract::schemaErrorFor('Problem', $body))->toBeNull()
        ->and($body['status'])->toBe($status)
        ->and($body['type'])->toBe('about:blank');

    return $body;
}

/** @return list<string> 項目ごとのエラーの位置（pointer か parameter） */
function restApiFields(array $body): array
{
    return array_map(fn (array $e) => $e['pointer'] ?? $e['parameter'], $body['errors'] ?? []);
}

function restApiProduct(string $code, int $salesPrice, int $reorderPoint, int $stock, bool $active = true): Product
{
    $product = Product::factory()->create([
        'code' => $code,
        'name' => "API テスト商品 {$code}",
        'category' => 'API テスト',
        'unit' => '個',
        'sales_price' => $salesPrice,
        'purchase_price' => 50,
        'reorder_point' => $reorderPoint,
        'is_active' => $active,
    ]);
    if ($stock !== 0) {
        StockMovement::factory()->create(['product_id' => $product->id, 'type' => StockMovementType::Adjust, 'quantity' => $stock]);
    }

    return $product;
}

function restApiValidBody(): array
{
    return [
        'customerCode' => 'za-c001',
        'dueDate' => null,
        'note' => 'API からの登録',
        'lines' => [
            ['productCode' => 'ZA-P001', 'quantity' => 3],
            ['productCode' => 'za-p003', 'quantity' => 2],
        ],
    ];
}

function restApiOrderCount(): int
{
    return SalesOrder::query()->count();
}

/**
 * 一覧・絞り込みの確認用の受注（9 日 × 2〜4 件）。同じ日の中では番号の大きい順に作る（id の順と番号の順が逆になる）
 *
 * @return list<string> 受注日（昇順）
 */
function restApiSeedOrders(Partner $customer, Product $product): array
{
    $statuses = SalesOrderStatus::cases();
    $dates = [];
    $n = 0;
    for ($i = 0; $i < 9; $i++) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($i * 3)->toDateString();
        $dates[] = $date;
        for ($seq = ($i % 3) + 2; $seq >= 1; $seq--) {
            $order = SalesOrder::factory()->create([
                'number' => sprintf('SO-%s-%03d', str_replace('-', '', $date), $seq),
                'customer_id' => $customer->id,
                'order_date' => $date,
                'status' => $statuses[$n++ % count($statuses)],
                'subtotal' => 100 * $seq,
                'tax' => 10 * $seq,
                'total' => 110 * $seq,
            ]);
            SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $seq, 'unit_price' => 100]);
        }
    }

    return $dates;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 03:00:00', 'UTC'));

    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
    $this->viewer = User::factory()->create();
    $inactive = User::factory()->staff()->create();

    $this->tokens = [
        'admin' => $this->admin->createToken('za-api-test')->plainTextToken,
        'staff' => $this->staff->createToken('za-api-test')->plainTextToken,
        'viewer' => $this->viewer->createToken('za-api-test')->plainTextToken,
        'inactive' => $inactive->createToken('za-api-test')->plainTextToken,
    ];
    $revoked = $this->staff->createToken('za-api-test revoked');
    $revoked->accessToken->forceFill(['revoked_at' => now()])->save();
    $this->tokens['revoked'] = $revoked->plainTextToken;
    // トークンを発行した後に持ち主だけ無効にする（失効はさせない）
    DB::table('users')->where('id', $inactive->id)->update(['is_active' => false]);
    $this->staffTokenId = (int) explode('|', $this->tokens['staff'])[0];

    $partner = fn (string $code, PartnerKind $kind, bool $active = true) => Partner::factory()->create(['code' => $code, 'name' => "API テスト取引先 {$code}", 'kind' => $kind, 'is_active' => $active]);
    $this->customer = $partner('ZA-C001', PartnerKind::Customer);
    $partner('ZA-S001', PartnerKind::Supplier);
    $partner('ZA-C002', PartnerKind::Customer, false);
    $this->product = restApiProduct('ZA-P001', 1200, 5, 10);
    restApiProduct('ZA-P002', 333, 0, 0, false);
    $this->lowProduct = restApiProduct('ZA-P003', 455, 10, 3);
});

describe('認証（Bearer トークンのみ。失敗は理由を区別しない 401）', function () {
    test('Authorization ヘッダーがなければ 401（WWW-Authenticate: Bearer）', function (string $method, string $template, string $uri) {
        $response = restApi(strtoupper($method), $uri, $method === 'post' ? ['body' => []] : []);

        expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="StockFlow API"');
        $body = restApiProblem($method, $template, $response, 401);
        expect($body['title'])->toBe('Unauthorized')
            ->and($body['detail'])->toBe('認証が必要です。有効な API トークンを Authorization: Bearer で指定してください');
    })->with([
        'GET 商品一覧' => ['get', '/api/v1/products', '/api/v1/products'],
        'GET 現在庫' => ['get', '/api/v1/products/{code}/stock', '/api/v1/products/ZA-P001/stock'],
        'GET 受注一覧' => ['get', '/api/v1/sales-orders', '/api/v1/sales-orders'],
        'GET 受注詳細' => ['get', '/api/v1/sales-orders/{number}', '/api/v1/sales-orders/SO-20000101-001'],
        'POST 受注の登録' => ['post', '/api/v1/sales-orders', '/api/v1/sales-orders'],
    ]);

    test('形式違い・存在しない・失効済み・無効ユーザーのトークンは、すべて同じ 401（理由を区別しない）', function () {
        $wellFormed = '999999|sf_'.str_repeat('a', 40).hash('crc32b', str_repeat('a', 40));
        $variants = [
            'Basic dXNlcjpwYXNz',
            'Bearer',
            'Bearer not-a-token',
            "Bearer {$wellFormed}", // 形式は正しいが存在しない
            "Bearer {$this->tokens['revoked']}",
            "Bearer {$this->tokens['inactive']}",
            "bearer {$this->tokens['staff']}x",
        ];
        $bodies = [];
        foreach ($variants as $authorization) {
            $response = restApi('GET', '/api/v1/products', ['headers' => ['Authorization' => $authorization]]);
            expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="StockFlow API"');
            $bodies[] = restApiProblem('get', '/api/v1/products', $response, 401);
        }
        expect(array_unique(array_map(fn (array $b) => json_encode($b), $bodies)))->toHaveCount(1);
    });

    test('スキーム名は大文字・小文字を区別しない（RFC 6750）', function () {
        $response = restApi('GET', '/api/v1/products?perPage=1', ['headers' => ['Authorization' => "bearer {$this->tokens['viewer']}"]]);

        restApiOk('get', '/api/v1/products', $response);
    });

    test('画面のログイン（Cookie）だけでは 401。Cookie があっても Bearer トークンで認証する', function () {
        $session = $this->loginForSession($this->staff->email, 'password');
        // 画面ではこの Cookie でログインできている
        $this->dashboardWithSession($session)->assertOk();
        $body = ['customerCode' => 'ZA-C001', 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1]]];
        $before = restApiOrderCount();

        restApiProblem('get', '/api/v1/products', $this->usingSession($session)->getJson('/api/v1/products'), 401);
        restApiProblem('post', '/api/v1/sales-orders', $this->usingSession($session)->postJson('/api/v1/sales-orders', $body), 401);
        expect(restApiOrderCount())->toBe($before);

        // Cookie はあっても無視し、Bearer トークン（viewer）の権限で判定する
        restApiProblem('post', '/api/v1/sales-orders', $this->usingSession($session)->postJson('/api/v1/sales-orders', $body, ['Authorization' => "Bearer {$this->tokens['viewer']}"]), 403);

        // テストのログイン（Web のガードにユーザーがいる状態）でも通らない（直前の Bearer の要求で既定のガードが sanctum になっているので戻す）
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        restApiProblem('get', '/api/v1/products', $this->usingSession(null)->actingAs($this->admin)->getJson('/api/v1/products'), 401);
        expect(restApiOrderCount())->toBe($before);
    });

    test('認証・認可の前に本文を読まない（トークンなしの不正な JSON・大きすぎる本文は 401、viewer は 403）', function () {
        restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', [
            'headers' => ['Content-Type' => 'application/json'],
            'raw' => '{not json',
        ]), 401);
        // 上限（65,536 バイト）を超える申告でも 401。PHP の post_max_size（CI は 8M、Sail は 100M）を超える申告は、Laravel の
        // ValidatePostSize が認証より前に 413 にする（本文は読まない）ので、それより小さい値で確かめる
        restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', [
            'headers' => ['Content-Type' => 'application/json', 'Content-Length' => '70000'],
            'raw' => '{}',
        ]), 401);
        restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['viewer'],
            'headers' => ['Content-Type' => 'text/plain'],
            'raw' => '{not json',
        ]), 403);
    });

    test('権限はトークンの持ち主のいまのロール（ロールを変えると次の要求から効く）', function () {
        $body = ['customerCode' => 'ZA-C001', 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1]]];
        restApiOk('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', ['token' => $this->tokens['staff'], 'body' => $body]), 201);

        DB::table('users')->where('id', $this->staff->id)->update(['role' => 'viewer']);

        restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', ['token' => $this->tokens['staff'], 'body' => $body]), 403);
        restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products', ['token' => $this->tokens['staff']]));
    });

    test('参照の権限（view）がなければ GET は 403（ルートで検査している。いまのロールはすべて参照できるので権限表を差し替えて確かめる）', function (string $template, string $uri) {
        Gate::define(Permission::View->value, fn () => false);

        $body = restApiProblem('get', $template, restApi('GET', $uri, ['token' => $this->tokens['admin']]), 403);
        expect($body['detail'])->toBe('この操作を行う権限がありません');
    })->with([
        '商品一覧' => ['/api/v1/products', '/api/v1/products'],
        '現在庫' => ['/api/v1/products/{code}/stock', '/api/v1/products/ZA-P001/stock'],
        '受注一覧' => ['/api/v1/sales-orders', '/api/v1/sales-orders'],
        '受注詳細' => ['/api/v1/sales-orders/{number}', '/api/v1/sales-orders/SO-20000101-001'],
    ]);
});

describe('GET /api/v1/products', function () {
    test('既定は 1 ページ目・20 件。全件数を返し、商品コードの昇順。全ロールが参照できる', function () {
        Product::factory()->count(22)->create();
        $total = Product::query()->count();

        foreach (['admin', 'staff', 'viewer'] as $role) {
            $body = restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products', ['token' => $this->tokens[$role]]));
            expect($body['meta'])->toBe(['page' => 1, 'perPage' => 20, 'total' => $total])
                ->and($body['data'])->toHaveCount(20);
            $codes = array_column($body['data'], 'code');
            $sorted = $codes;
            sort($sorted, SORT_STRING);
            expect($codes)->toBe($sorted)
                ->and($codes[0])->toBe(Product::query()->orderBy('code')->value('code'));
        }
    });

    test('q は商品コード・名前・カテゴリの部分一致（大文字・小文字を区別しない）。無効な商品・現在庫・発注点割れを含む', function () {
        $body = restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products?q=za-p', ['token' => $this->tokens['viewer']]));

        expect($body['meta'])->toBe(['page' => 1, 'perPage' => 20, 'total' => 3])
            ->and($body['data'][0])->toBe([
                'code' => 'ZA-P001',
                'name' => 'API テスト商品 ZA-P001',
                'category' => 'API テスト',
                'unit' => '個',
                'salesPrice' => 1200,
                'purchasePrice' => 50,
                'reorderPoint' => 5,
                'isActive' => true,
                'stock' => 10,
                'belowReorderPoint' => false,
            ])
            // 無効な商品は発注点割れにしない（在庫一覧・通知と同じ定義）
            ->and($body['data'][1])->toMatchArray(['code' => 'ZA-P002', 'isActive' => false, 'stock' => 0, 'belowReorderPoint' => false])
            ->and($body['data'][2])->toMatchArray(['code' => 'ZA-P003', 'stock' => 3, 'reorderPoint' => 10, 'belowReorderPoint' => true]);

        // 名前・カテゴリでも探せる。% と _ はワイルドカードにしない
        expect(restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products?q='.rawurlencode('api テスト'), ['token' => $this->tokens['viewer']]))['meta']['total'])->toBe(3)
            ->and(restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products?q=%25', ['token' => $this->tokens['viewer']]))['meta']['total'])->toBe(0)
            ->and(restApiOk('get', '/api/v1/products', restApi('GET', '/api/v1/products?q=ZA_P', ['token' => $this->tokens['viewer']]))['meta']['total'])->toBe(0);
    });

    test('ページ送りの境界: 最後のページは端数、全件数を超えるページは空（丸めない）', function () {
        $page = fn (string $query) => restApiOk('get', '/api/v1/products', restApi('GET', "/api/v1/products{$query}", ['token' => $this->tokens['viewer']]));

        $second = $page('?q=ZA-P&perPage=2&page=2');
        expect(array_column($second['data'], 'code'))->toBe(['ZA-P003'])
            ->and($second['meta'])->toBe(['page' => 2, 'perPage' => 2, 'total' => 3]);

        $beyond = $page('?q=ZA-P&perPage=2&page=3');
        expect($beyond['data'])->toBe([])->and($beyond['meta'])->toBe(['page' => 3, 'perPage' => 2, 'total' => 3]);

        expect($page('?perPage=100&page=1000000'))->toBe(['data' => [], 'meta' => ['page' => 1000000, 'perPage' => 100, 'total' => 3]])
            ->and($page('?q='.rawurlencode('該当なしの検索語')))->toBe(['data' => [], 'meta' => ['page' => 1, 'perPage' => 20, 'total' => 0]])
            // 空の値は省略と同じ（q）、知らないパラメーターは無視する
            ->and($page('?q=&sort=name&status=inactive')['meta']['total'])->toBe(3);
    });

    test('不正なクエリは 400（項目ごとのエラーは parameter）', function (string $query, array $parameters) {
        $body = restApiProblem('get', '/api/v1/products', restApi('GET', "/api/v1/products{$query}", ['token' => $this->tokens['viewer']]), 400);

        expect($body['title'])->toBe('Bad Request')
            ->and($body['detail'])->toBe('入力内容に誤りがあります。errors の各項目を確認してください')
            ->and(restApiFields($body))->toBe($parameters);
    })->with([
        'perPage が上限超え' => ['?perPage=101', ['perPage']],
        'perPage が 0' => ['?perPage=0', ['perPage']],
        'perPage が数字でない' => ['?perPage=abc', ['perPage']],
        'perPage が小数' => ['?perPage=1.5', ['perPage']],
        'perPage が負' => ['?perPage=-1', ['perPage']],
        'perPage に符号' => ['?perPage=%2B1', ['perPage']],
        'page が 0' => ['?page=0', ['page']],
        'page が上限超え' => ['?page=1000001', ['page']],
        'page を 2 回指定' => ['?page=1&page=2', ['page']],
        'q が長すぎる' => ['?q='.str_repeat('a', 101), ['q']],
        'q に NUL' => ['?q=%00', ['q']],
        'q が UTF-8 として正しくない' => ['?q=%FF', ['q']],
        '複数の誤り' => ['?q=%00&page=0&perPage=0', ['q', 'page', 'perPage']],
    ]);
});

describe('GET /api/v1/products/{code}/stock', function () {
    test('現在庫と発注点を返す（商品コードの大文字・小文字は区別しない）', function (string $code) {
        $body = restApiOk('get', '/api/v1/products/{code}/stock', restApi('GET', '/api/v1/products/'.rawurlencode($code).'/stock', ['token' => $this->tokens['viewer']]));

        expect($body)->toBe([
            'code' => 'ZA-P003',
            'name' => 'API テスト商品 ZA-P003',
            'unit' => '個',
            'isActive' => true,
            'stock' => 3,
            'reorderPoint' => 10,
            'belowReorderPoint' => true,
        ]);
    })->with(['ZA-P003', 'za-p003']);

    test('パスに NUL があれば 400（DB には渡さない）。UTF-8 として正しくない URL も 400', function () {
        $body = restApiProblem('get', '/api/v1/products/{code}/stock', restApi('GET', '/api/v1/products/%00/stock', ['token' => $this->tokens['viewer']]), 400);
        expect($body['errors'])->toBe([['parameter' => 'code', 'detail' => 'NUL 文字（U+0000）は使えません']]);

        $body = restApiProblem('get', '/api/v1/products/{code}/stock', restApi('GET', '/api/v1/products/%FF/stock', ['token' => $this->tokens['viewer']]), 400);
        expect($body['detail'])->toBe('URL に UTF-8 として正しくない文字が含まれています');
    });

    test('存在しない商品は 404', function () {
        $body = restApiProblem('get', '/api/v1/products/{code}/stock', restApi('GET', '/api/v1/products/za-nope/stock', ['token' => $this->tokens['viewer']]), 404);

        expect($body['title'])->toBe('Not Found')->and($body['detail'])->toBe('商品「ZA-NOPE」が見つかりません');
    });
});

describe('GET /api/v1/sales-orders', function () {
    test('既定は受注日の新しい順（同じ日は受注番号の降順。作成の順ではない）・20 件', function () {
        restApiSeedOrders($this->customer, $this->product);

        $body = restApiOk('get', '/api/v1/sales-orders', restApi('GET', '/api/v1/sales-orders', ['token' => $this->tokens['viewer']]));

        expect($body['meta'])->toBe(['page' => 1, 'perPage' => 20, 'total' => SalesOrder::query()->count()])
            ->and($body['data'])->toHaveCount(20);
        $keys = array_map(fn (array $o) => "{$o['orderDate']} {$o['number']}", $body['data']);
        $sorted = $keys;
        rsort($sorted, SORT_STRING);
        expect($keys)->toBe($sorted)
            ->and($body['data'][0]['number'])->toBe('SO-20260925-004');

        $first = SalesOrder::query()->where('number', 'SO-20260925-004')->firstOrFail();
        expect($body['data'][0])->toBe([
            'number' => 'SO-20260925-004',
            'orderDate' => '2026-09-25',
            'dueDate' => null,
            'status' => $first->status->value,
            'customer' => ['code' => 'ZA-C001', 'name' => 'API テスト取引先 ZA-C001'],
            'subtotal' => 400,
            'tax' => 40,
            'total' => 440,
            'note' => null,
            'lineCount' => 1,
        ]);
    });

    test('from・to・status で絞り込める（両端を含む）。from・to のどちらを無視しても件数が合わなくなる期間で確かめる', function () {
        $dates = restApiSeedOrders($this->customer, $this->product);
        // 期間の境界は、受注日の一覧の 1/3 と 2/3 の位置の日（どちらの側にも期間外の受注がある）
        $from = $dates[intdiv(count($dates), 3)];
        $to = $dates[intdiv(count($dates) * 2, 3)];
        $all = SalesOrder::query()->count();
        $cases = [
            ["from={$from}", fn ($q) => $q->where('order_date', '>=', $from)],
            ["to={$to}", fn ($q) => $q->where('order_date', '<=', $to)],
            ["from={$from}&to={$to}", fn ($q) => $q->whereBetween('order_date', [$from, $to])],
            ["from={$to}&to={$to}", fn ($q) => $q->where('order_date', $to)],
            ["status=shipped&from={$from}&to={$to}", fn ($q) => $q->where('status', 'shipped')->whereBetween('order_date', [$from, $to])],
        ];
        foreach ($cases as [$query, $where]) {
            $expected = $where(SalesOrder::query())->count();
            // 0 件や全件の条件では、絞り込みを無視する誤りを検出できないので先に確かめる
            expect($expected)->toBeGreaterThan(0, $query)->toBeLessThan($all, $query);

            $body = restApiOk('get', '/api/v1/sales-orders', restApi('GET', "/api/v1/sales-orders?{$query}&perPage=100", ['token' => $this->tokens['viewer']]));

            expect($body['meta']['total'])->toBe($expected, $query)
                ->and($body['data'])->toHaveCount(min(100, $expected));
            parse_str($query, $params);
            foreach ($body['data'] as $order) {
                if (isset($params['from'])) {
                    expect($order['orderDate'] >= $params['from'])->toBeTrue($query);
                }
                if (isset($params['to'])) {
                    expect($order['orderDate'] <= $params['to'])->toBeTrue($query);
                }
                if (isset($params['status'])) {
                    expect($order['status'])->toBe($params['status'], $query);
                }
            }
        }
    });

    test('不正なクエリは 400（項目ごとのエラーは parameter）', function (string $query, array $parameters) {
        $body = restApiProblem('get', '/api/v1/sales-orders', restApi('GET', "/api/v1/sales-orders{$query}", ['token' => $this->tokens['viewer']]), 400);

        expect(restApiFields($body))->toBe($parameters);
    })->with([
        'status が選択肢にない' => ['?status=open', ['status']],
        'status が大文字' => ['?status=DRAFT', ['status']],
        'status に NUL' => ['?status=%00', ['status']],
        'from が範囲より前' => ['?from=1999-12-31', ['from']],
        'to が範囲より後' => ['?to=2100-01-01', ['to']],
        'from が存在しない日付' => ['?from=2026-02-30', ['from']],
        'to の形式が違う' => ['?to=20261013', ['to']],
        'to が from より前' => ['?from=2026-10-13&to=2026-10-12', ['to']],
        'from を 2 回指定' => ['?from=2026-10-01&from=2026-10-02', ['from']],
        'perPage が上限超え' => ['?perPage=101', ['perPage']],
    ]);
});

describe('GET /api/v1/sales-orders/{number}', function () {
    test('明細を含む詳細を返す（受注番号の大文字・小文字は区別しない。明細は登録の順）', function (bool $lower) {
        $order = SalesOrder::factory()->create([
            'number' => 'SO-20261001-001',
            'customer_id' => $this->customer->id,
            'order_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'status' => SalesOrderStatus::Confirmed,
            'note' => "1 行目\n2 行目",
            'subtotal' => 4510,
            'tax' => 451,
            'total' => 4961,
        ]);
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $this->lowProduct->id, 'quantity' => 2, 'unit_price' => 455]);
        SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 1200]);
        $order->refresh();

        $number = $lower ? 'so-20261001-001' : 'SO-20261001-001';
        $body = restApiOk('get', '/api/v1/sales-orders/{number}', restApi('GET', "/api/v1/sales-orders/{$number}", ['token' => $this->tokens['viewer']]));

        expect($body)->toBe([
            'number' => 'SO-20261001-001',
            'orderDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'status' => 'confirmed',
            'customer' => ['code' => 'ZA-C001', 'name' => 'API テスト取引先 ZA-C001'],
            'note' => "1 行目\n2 行目",
            'subtotal' => 4510,
            'tax' => 451,
            'total' => 4961,
            'lines' => [
                ['product' => ['code' => 'ZA-P003', 'name' => 'API テスト商品 ZA-P003', 'unit' => '個'], 'quantity' => 2, 'unitPrice' => 455, 'amount' => 910],
                ['product' => ['code' => 'ZA-P001', 'name' => 'API テスト商品 ZA-P001', 'unit' => '個'], 'quantity' => 3, 'unitPrice' => 1200, 'amount' => 3600],
            ],
            // 日時は UTC（JST 2026-10-19 12:00 に固定している）
            'createdAt' => '2026-10-19T03:00:00.000Z',
            'updatedAt' => '2026-10-19T03:00:00.000Z',
        ]);
    })->with(['大文字' => false, '小文字' => true]);

    test('パスに NUL があれば 400', function () {
        $body = restApiProblem('get', '/api/v1/sales-orders/{number}', restApi('GET', '/api/v1/sales-orders/%00', ['token' => $this->tokens['viewer']]), 400);

        expect($body['errors'])->toBe([['parameter' => 'number', 'detail' => 'NUL 文字（U+0000）は使えません']]);
    });

    test('存在しない受注は 404', function () {
        $body = restApiProblem('get', '/api/v1/sales-orders/{number}', restApi('GET', '/api/v1/sales-orders/so-20000101-999', ['token' => $this->tokens['viewer']]), 404);

        expect($body['detail'])->toBe('受注「SO-20000101-999」が見つかりません');
    });
});

describe('POST /api/v1/sales-orders', function () {
    test('staff は draft で登録できる。画面と同じ規則（採番・単価は売価・金額の計算・操作履歴）で作られる', function () {
        // 画面から 1 件作り、その次の番号で採番されることを確かめる
        $this->actingAs($this->staff)->post(route('sales-orders.store'), [
            'customer_id' => (string) $this->customer->id,
            'due_date' => '',
            'note' => '',
            'lines' => [['product_id' => (string) $this->product->id, 'quantity' => '1', 'unit_price' => '1200']],
        ])->assertSessionHasNoErrors();
        $viaScreen = SalesOrder::query()->sole();
        expect($viaScreen->number)->toBe('SO-20261019-001');

        $response = restApi('POST', '/api/v1/sales-orders', ['token' => $this->tokens['staff'], 'body' => [...restApiValidBody(), 'dueDate' => '2026-10-19']]);

        $body = restApiOk('post', '/api/v1/sales-orders', $response, 201);
        expect($response->headers->get('Location'))->toBe('/api/v1/sales-orders/SO-20261019-002');
        // 3 × 1200 + 2 × 455 = 4510、税 451（10%・切り捨て）、合計 4961
        expect(OrderAmounts::calculate([['quantity' => 3, 'unit_price' => 1200], ['quantity' => 2, 'unit_price' => 455]])?->toArray())
            ->toBe(['subtotal' => 4510, 'tax' => 451, 'total' => 4961]);
        expect($body)->toMatchArray([
            'number' => 'SO-20261019-002',
            'orderDate' => '2026-10-19',
            'dueDate' => '2026-10-19',
            'status' => 'draft',
            'customer' => ['code' => 'ZA-C001', 'name' => 'API テスト取引先 ZA-C001'],
            'note' => 'API からの登録',
            'subtotal' => 4510,
            'tax' => 451,
            'total' => 4961,
            'lines' => [
                ['product' => ['code' => 'ZA-P001', 'name' => 'API テスト商品 ZA-P001', 'unit' => '個'], 'quantity' => 3, 'unitPrice' => 1200, 'amount' => 3600],
                ['product' => ['code' => 'ZA-P003', 'name' => 'API テスト商品 ZA-P003', 'unit' => '個'], 'quantity' => 2, 'unitPrice' => 455, 'amount' => 910],
            ],
            'createdAt' => '2026-10-19T03:00:00.000Z',
        ]);

        $saved = SalesOrder::query()->where('number', 'SO-20261019-002')->sole();
        expect($saved->only(['status', 'customer_id', 'subtotal', 'tax', 'total']))->toBe([
            'status' => SalesOrderStatus::Draft, 'customer_id' => $this->customer->id, 'subtotal' => 4510, 'tax' => 451, 'total' => 4961,
        ])
            ->and(SalesOrderLine::query()->where('sales_order_id', $saved->id)->orderBy('id')->get()->map(fn (SalesOrderLine $l) => [$l->product_id, $l->quantity, $l->unit_price])->all())
            ->toBe([[$this->product->id, 3, 1200], [$this->lowProduct->id, 2, 455]]);

        // 操作履歴: トークンの持ち主が作成し、REST API から作成したことが分かる（画面から作ったものには付かない）
        $log = AuditLog::query()->where('target_type', SalesOrderService::TARGET_TYPE)->where('target_id', (string) $saved->id)->sole();
        expect($log->only(['user_id', 'action']))->toBe(['user_id' => $this->staff->id, 'action' => 'create'])
            ->and($log->summary)->toBe("受注 SO-20261019-002 を作成しました（顧客 ZA-C001「API テスト取引先 ZA-C001」・明細 2 行・合計 4,961 円・REST API（トークン #{$this->staffTokenId}）から作成）")
            ->and(AuditLog::query()->where('target_id', (string) $viaScreen->id)->where('target_type', SalesOrderService::TARGET_TYPE)->sole()->summary)->not->toContain('REST API');

        // 作成した受注は詳細の API でも同じ内容で読める
        expect(restApiOk('get', '/api/v1/sales-orders/{number}', restApi('GET', '/api/v1/sales-orders/SO-20261019-002', ['token' => $this->tokens['viewer']])))->toBe($body);
    });

    test('admin も登録できる。dueDate・note は省略できる。数量の 2.0 は整数として受け付ける', function () {
        $response = restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['admin'],
            'body' => ['customerCode' => 'ZA-C001', 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 2.0]]],
        ]);

        expect(restApiOk('post', '/api/v1/sales-orders', $response, 201))->toMatchArray([
            'dueDate' => null,
            'note' => null,
            'subtotal' => 2400,
            'tax' => 240,
            'total' => 2640,
        ]);
    });

    test('Content-Type は application/json に charset="UTF-8"・+json も受け付ける。先頭の BOM は無視する', function (string $contentType, string $prefix) {
        $response = restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['staff'],
            'headers' => ['Content-Type' => $contentType],
            'raw' => $prefix.json_encode(restApiValidBody()),
        ]);

        restApiOk('post', '/api/v1/sales-orders', $response, 201);
    })->with([
        'charset="UTF-8"' => ['application/json; charset="UTF-8"', ''],
        '+json' => ['application/vnd.stockflow+json', ''],
        'BOM' => ['application/json', "\xEF\xBB\xBF"],
    ]);

    test('viewer は 403（作成されない）', function () {
        $before = restApiOrderCount();

        $body = restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', ['token' => $this->tokens['viewer'], 'body' => restApiValidBody()]), 403);

        expect($body['title'])->toBe('Forbidden')
            ->and($body['detail'])->toBe('この操作を行う権限がありません')
            ->and(restApiOrderCount())->toBe($before);
    });

    test('本文の誤りは 400 で、項目ごとのエラーを JSON Pointer で返す（作成しない）', function (Closure $makeBody, array $pointers, bool $violatesSchema) {
        $body = $makeBody();
        // 本文の型・必須・余分な項目などの誤りは openapi.json のスキーマにも違反し、業務の検証（画面と同じ検証・サービス）の誤りは違反しない
        expect(OpenApiContract::schemaErrorFor('SalesOrderCreate', $body) !== null)->toBe($violatesSchema);
        $before = restApiOrderCount();

        $problem = restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', ['token' => $this->tokens['staff'], 'body' => $body]), 400);

        expect($problem['detail'])->toBe('入力内容に誤りがあります。errors の各項目を確認してください')
            ->and(restApiFields($problem))->toBe($pointers);
        foreach ($problem['errors'] as $error) {
            // エラー文は日本語
            expect($error['detail'])->toMatch('/[ぁ-んァ-ヶ一-龠]/u');
        }
        expect(restApiOrderCount())->toBe($before);
    })->with([
        '余分な項目（トップレベル）' => [fn () => [...restApiValidBody(), 'total' => 1], ['#/total'], true],
        '余分な項目（明細の単価は指定できない）' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1, 'unitPrice' => 1]]], ['#/lines/0/unitPrice'], true],
        '数量が文字列（型違い）' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => '3']]], ['#/lines/0/quantity'], true],
        '数量が小数' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1.5]]], ['#/lines/0/quantity'], true],
        '数量が 0' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 0]]], ['#/lines/0/quantity'], true],
        '数量が上限を超える' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 10_000_000]]], ['#/lines/0/quantity'], true],
        '明細が空' => [fn () => [...restApiValidBody(), 'lines' => []], ['#/lines'], true],
        '明細が 101 行' => [fn () => [...restApiValidBody(), 'lines' => array_fill(0, 101, ['productCode' => 'ZA-P001', 'quantity' => 1])], ['#/lines'], true],
        '明細が配列でない' => [fn () => [...restApiValidBody(), 'lines' => 'ZA-P001'], ['#/lines'], true],
        '明細の行がオブジェクトでない' => [fn () => [...restApiValidBody(), 'lines' => [1]], ['#/lines/0'], true],
        '必須の項目がない' => [fn () => ['note' => 'x'], ['#/customerCode', '#/lines'], true],
        '本文が配列' => [fn () => [restApiValidBody()], ['#'], true],
        '本文が null' => [fn () => null, ['#'], true],
        '顧客コードが空' => [fn () => [...restApiValidBody(), 'customerCode' => ''], ['#/customerCode'], true],
        '顧客コードが長すぎる' => [fn () => [...restApiValidBody(), 'customerCode' => str_repeat('C', 21)], ['#/customerCode'], true],
        '顧客コードが数値' => [fn () => [...restApiValidBody(), 'customerCode' => 1], ['#/customerCode'], true],
        '納期が存在しない日付' => [fn () => [...restApiValidBody(), 'dueDate' => '2026-02-30'], ['#/dueDate'], true],
        '納期が範囲外（2100 年）' => [fn () => [...restApiValidBody(), 'dueDate' => '2100-01-01'], ['#/dueDate'], true],
        '納期の形式が違う' => [fn () => [...restApiValidBody(), 'dueDate' => '20261031'], ['#/dueDate'], true],
        '納期が数値' => [fn () => [...restApiValidBody(), 'dueDate' => 20261031], ['#/dueDate'], true],
        '備考が 501 文字' => [fn () => [...restApiValidBody(), 'note' => str_repeat('あ', 501)], ['#/note'], true],
        '備考が数値' => [fn () => [...restApiValidBody(), 'note' => 1], ['#/note'], true],
        'キーに NUL（先頭）' => [fn () => [...restApiValidBody(), "\0x" => 1], ["#/\0x"], true],
        '顧客コードに NUL' => [fn () => [...restApiValidBody(), 'customerCode' => "C\0"], ['#/customerCode'], false],
        '商品コードに NUL' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => "P\0", 'quantity' => 1]]], ['#/lines/0/productCode'], false],
        '納期が受注日より前（サービスの検証）' => [fn () => [...restApiValidBody(), 'dueDate' => '2026-10-18'], ['#/dueDate'], false],
        '存在しない顧客' => [fn () => [...restApiValidBody(), 'customerCode' => 'ZA-C999'], ['#/customerCode'], false],
        '空白だけの顧客コード' => [fn () => [...restApiValidBody(), 'customerCode' => '  '], ['#/customerCode'], false],
        '仕入先を顧客に指定（サービスの検証）' => [fn () => [...restApiValidBody(), 'customerCode' => 'ZA-S001'], ['#/customerCode'], false],
        '無効な顧客（サービスの検証）' => [fn () => [...restApiValidBody(), 'customerCode' => 'ZA-C002'], ['#/customerCode'], false],
        '存在しない商品' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1], ['productCode' => 'ZA-P999', 'quantity' => 1]]], ['#/lines/1/productCode'], false],
        '無効な商品（サービスの検証）' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P002', 'quantity' => 1]]], ['#/lines/0/productCode'], false],
        '同じ商品が 2 行（画面と同じ検証）' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 1], ['productCode' => 'za-p001', 'quantity' => 2]]], ['#/lines/1/productCode'], false],
        '備考にタブ（画面と同じ検証）' => [fn () => [...restApiValidBody(), 'note' => "a\tb"], ['#/note'], false],
        '合計が上限を超える（画面と同じ検証）' => [fn () => [...restApiValidBody(), 'lines' => [['productCode' => 'ZA-P001', 'quantity' => 9_999_999]]], ['#/lines'], false],
    ]);

    test('JSON として正しくない・空・UTF-8 として正しくない本文は 400（項目ごとのエラーなし）', function (string $raw, string $detail) {
        $body = restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['staff'],
            'headers' => ['Content-Type' => 'application/json'],
            'raw' => $raw,
        ]), 400);

        expect($body['detail'])->toBe($detail)->and($body)->not->toHaveKey('errors');
    })->with([
        'JSON として正しくない' => ['{"customerCode": ', '本文が JSON として正しくありません'],
        '空' => ['', '本文が空です'],
        '空白だけ' => [" \n", '本文が空です'],
        'UTF-8 として正しくない' => ["{\"customerCode\": \"\xFF\"}", '本文が UTF-8 として正しくありません'],
    ]);

    test('引用符のない巨大な整数は文字列の項目に通さない（JSON の型のまま検査する）・数量は範囲外', function (string $raw, string $pointer, string $detail) {
        $before = restApiOrderCount();

        $body = restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['staff'],
            'headers' => ['Content-Type' => 'application/json'],
            'raw' => $raw,
        ]), 400);

        expect($body['errors'])->toBe([['pointer' => $pointer, 'detail' => $detail]])
            ->and(restApiOrderCount())->toBe($before);
    })->with([
        '顧客コード' => ['{"customerCode":9223372036854775808,"lines":[{"productCode":"ZA-P001","quantity":1}]}', '#/customerCode', '文字列で指定してください'],
        '商品コード' => ['{"customerCode":"ZA-C001","lines":[{"productCode":9223372036854775808,"quantity":1}]}', '#/lines/0/productCode', '文字列で指定してください'],
        '備考' => ['{"customerCode":"ZA-C001","note":9223372036854775808,"lines":[{"productCode":"ZA-P001","quantity":1}]}', '#/note', '文字列で指定してください'],
        '数量' => ['{"customerCode":"ZA-C001","lines":[{"productCode":"ZA-P001","quantity":9223372036854775808}]}', '#/lines/0/quantity', '9,999,999 以下で指定してください'],
        '数量（負）' => ['{"customerCode":"ZA-C001","lines":[{"productCode":"ZA-P001","quantity":-9223372036854775809}]}', '#/lines/0/quantity', '1 以上で指定してください'],
    ]);

    test('Content-Type が JSON（UTF-8）でなければ 415（Accept-Post: application/json）', function (string $contentType) {
        $before = restApiOrderCount();
        $response = restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['staff'],
            'headers' => ['Content-Type' => $contentType],
            'raw' => json_encode(restApiValidBody()),
        ]);

        expect($response->headers->get('Accept-Post'))->toBe('application/json');
        $body = restApiProblem('post', '/api/v1/sales-orders', $response, 415);
        expect($body['title'])->toBe('Unsupported Media Type')->and(restApiOrderCount())->toBe($before);
    })->with([
        'text/plain' => ['text/plain'],
        'フォーム' => ['application/x-www-form-urlencoded'],
        'charset が UTF-8 でない' => ['application/json; charset=shift_jis'],
        'なし（空）' => [''],
    ]);

    test('本文が上限（65,536 バイト）を超えると 413（Content-Length の申告でも、読んだ大きさでも）', function () {
        $before = restApiOrderCount();
        // ちょうど size バイトの本文（備考が長すぎるので、上限以内なら 400）
        $sized = function (int $size): string {
            $base = json_encode([...restApiValidBody(), 'note' => '']);
            $raw = str_replace('"note":""', '"note":"'.str_repeat('a', $size - strlen($base)).'"', $base);
            expect(strlen($raw))->toBe($size);

            return $raw;
        };
        $post = fn (string $raw, array $headers = []) => restApi('POST', '/api/v1/sales-orders', [
            'token' => $this->tokens['staff'],
            'headers' => ['Content-Type' => 'application/json', ...$headers],
            'raw' => $raw,
        ]);

        // Content-Length なし（読みながら数える）: 上限ちょうどは読んで検証に進み、1 バイト超えると 413
        $atLimit = restApiProblem('post', '/api/v1/sales-orders', $post($sized(ApiJsonBody::MAX_BYTES)), 400);
        expect(restApiFields($atLimit))->toBe(['#/note']);
        $over = restApiProblem('post', '/api/v1/sales-orders', $post($sized(ApiJsonBody::MAX_BYTES + 1)), 413);
        expect($over['title'])->toBe('Content Too Large')->and($over['detail'])->toBe('本文が大きすぎます（上限 65,536 バイト）');
        restApiProblem('post', '/api/v1/sales-orders', $post(json_encode([...restApiValidBody(), 'note' => str_repeat('あ', 30_000)])), 413);

        // Content-Length の申告が上限を超えていれば、本文が正しくても読まずに 413
        restApiProblem('post', '/api/v1/sales-orders', $post(json_encode(restApiValidBody()), ['Content-Length' => '65537']), 413);
        restApiProblem('post', '/api/v1/sales-orders', $post(json_encode(restApiValidBody()), ['Content-Length' => '99999999999999999999999']), 413);

        expect(restApiOrderCount())->toBe($before);
    });
});

describe('定義していないメソッド・パス', function () {
    test('定義したパスに定義していないメソッドは 405（Allow 付き。認証より先）', function (string $method, string $uri, string $allow) {
        $body = restApiUndocumentedProblem(restApi($method, $uri), 405);

        expect(restApi($method, $uri, ['token' => $this->tokens['admin']])->headers->get('Allow'))->toBe($allow)
            ->and($body['title'])->toBe('Method Not Allowed')
            ->and($body['detail'])->toBe('このメソッドは使えません（使えるメソッド: '.str_replace(['HEAD, ', ', HEAD'], '', $allow).'）');
    })->with([
        ['POST', '/api/v1/products', 'GET, HEAD'],
        ['PUT', '/api/v1/products', 'GET, HEAD'],
        ['PATCH', '/api/v1/products', 'GET, HEAD'],
        ['DELETE', '/api/v1/products', 'GET, HEAD'],
        ['POST', '/api/v1/products/ZA-P001/stock', 'GET, HEAD'],
        ['DELETE', '/api/v1/products/ZA-P001/stock', 'GET, HEAD'],
        ['PUT', '/api/v1/sales-orders', 'GET, HEAD, POST'],
        ['PATCH', '/api/v1/sales-orders', 'GET, HEAD, POST'],
        ['DELETE', '/api/v1/sales-orders', 'GET, HEAD, POST'],
        ['POST', '/api/v1/sales-orders/SO-20261019-001', 'GET, HEAD'],
        ['PUT', '/api/v1/sales-orders/SO-20261019-001', 'GET, HEAD'],
        ['DELETE', '/api/v1/sales-orders/SO-20261019-001', 'GET, HEAD'],
        ['POST', '/api/v1/openapi.json', 'GET, HEAD'],
    ]);

    test('/api/v1 の下の未定義のパスは RFC 9457 の 404', function (string $method, string $uri) {
        $body = restApiUndocumentedProblem(restApi($method, $uri, ['token' => $this->tokens['admin']]), 404);

        expect($body['title'])->toBe('Not Found')->and($body['detail'])->toBe('この URL の API はありません（/api-docs を参照してください）');
    })->with([
        ['GET', '/api/v1'],
        ['GET', '/api/v1/nope'],
        ['POST', '/api/v1/nope'],
        ['GET', '/api/v1/products/ZA-P001'],
        ['GET', '/api/v1/products/ZA-P001/stock/x'],
    ]);
});

describe('API の範囲の判定（デコードしたパス）と全体のミドルウェア', function () {
    test('エンコードした接頭辞でもルートに一致し、RFC 9457 の形で返す（401・403・404・405）', function () {
        restApiOk('get', '/api/v1/products', restApi('GET', '/%61pi/v1/products', ['token' => $this->tokens['viewer']]));

        $unauthorized = restApi('GET', '/%61pi/v1/products');
        expect($unauthorized->headers->get('WWW-Authenticate'))->toBe('Bearer realm="StockFlow API"');
        restApiProblem('get', '/api/v1/products', $unauthorized, 401);
        restApiProblem('post', '/api/v1/sales-orders', restApi('POST', '/api/%761/sales-orders', ['token' => $this->tokens['viewer'], 'body' => restApiValidBody()]), 403);
        restApiUndocumentedProblem(restApi('GET', '/%61pi/v1/nope', ['token' => $this->tokens['viewer']]), 404);
        $notAllowed = restApi('PUT', '/api/%761/products', ['token' => $this->tokens['viewer']]);
        restApiUndocumentedProblem($notAllowed, 405);
        expect($notAllowed->headers->get('Allow'))->toBe('GET, HEAD');
    });

    test('/api/v1 の外（画面・JSON の要求・似たパス）はこれまでどおりの応答', function () {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->getJson('/dashboard')->assertUnauthorized()->assertExactJson(['message' => 'ログインが必要です'])
            ->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('WWW-Authenticate');
        $similar = $this->getJson('/api/v1x/products')->assertNotFound();
        expect($similar->headers->get('Content-Type'))->toBe('application/json');
        $this->actingAs($this->viewer)->getJson('/%70roducts/create')->assertForbidden()->assertExactJson(['message' => 'この操作を行う権限がありません']);
    });

    test('前後の空白の除去・空文字の null 化（TrimStrings・ConvertEmptyStringsToNull）は /api/v1 では行わない', function (string $uri, bool $api) {
        $request = Request::createFromBase(SymfonyRequest::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"note":"  a  ","x":""}'));

        app(ConvertEmptyStringsToNull::class)->handle($request, fn ($r) => $r);
        app(TrimStrings::class)->handle($request, fn ($r) => $r);

        expect($request->json('note'))->toBe($api ? '  a  ' : 'a')
            ->and($request->json('x'))->toBe($api ? '' : null);
    })->with([
        'API' => ['/api/v1/sales-orders', true],
        'API（エンコードした接頭辞）' => ['/%61pi/v1/sales-orders', true],
        '画面' => ['/sales-orders', false],
        '似たパス' => ['/api/v1x', false],
    ]);
});

describe('GET /api/v1/openapi.json', function () {
    test('認証なしで docs/openapi.json をそのまま返す', function () {
        $response = restApi('GET', '/api/v1/openapi.json');

        restApiOk('get', '/api/v1/openapi.json', $response);
        expect($response->getContent())->toBe(file_get_contents(base_path('docs/openapi.json')));
    });
});

describe('OpenAPI の定義との対応', function () {
    test('定義した応答（パス × メソッド × ステータス）を、すべてこのファイルで実際に返して検証した', function () {
        $missing = array_values(array_filter(OpenApiContract::documentedResponses(), fn (string $key) => ! isset(OpenApiContract::$checked[$key])));

        expect($missing)->toBe([]);
    });
});
