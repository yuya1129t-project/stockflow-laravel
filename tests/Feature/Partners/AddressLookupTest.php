<?php

use App\Http\Controllers\PartnerController;
use App\Models\User;
use App\Services\Partners\AddressLookup;
use App\Services\Partners\AddressLookupFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 郵便番号 → 住所（zipcloud。docs/spec.md §6）。外部 API は Http::fake で差し替える
 * （TestCase で Http::preventStrayRequests() にしているので、差し替えていない要求は例外になり、CI から実 API を呼ばない）
 */
function zipcloudBody(array $results = [['zipcode' => '0310001', 'address1' => '青森県', 'address2' => '八戸市', 'address3' => '類家']], int $status = 200): array
{
    return ['message' => null, 'results' => $results, 'status' => $status];
}

function lookupAs(?User $user, string $postalCode = '031-0001')
{
    return test()->actingAs($user ?? User::factory()->staff()->create())
        ->getJson(route('partners.address-lookup', ['postal_code' => $postalCode]));
}

test('正常: サーバーから zipcloud を呼び、住所の候補を返す（タイムアウト 3 秒・リダイレクトをたどらない）', function () {
    $sent = [];
    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = ['url' => $request->url(), 'options' => $options];

        return Http::response(zipcloudBody(), 200, ['Content-Type' => 'text/plain;charset=utf-8']);
    });

    lookupAs(null, '０３１－０００１')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['postalCode' => '0310001', 'addresses' => ['青森県八戸市類家']]);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['url'])->toBe(AddressLookup::ENDPOINT.'?zipcode=0310001');
    expect($sent[0]['options']['timeout'])->toBe(3);
    expect($sent[0]['options']['connect_timeout'])->toBe(3);
    expect($sent[0]['options']['allow_redirects'])->toBeFalse();
});

test('admin も使える。1 つの郵便番号に複数の町域があれば候補をすべて返す（重複は除く）', function () {
    Http::fake([AddressLookup::ENDPOINT.'*' => Http::response(zipcloudBody([
        ['zipcode' => '0000001', 'address1' => 'デモ県', 'address2' => 'サンプル市', 'address3' => '北町'],
        ['zipcode' => '0000001', 'address1' => 'デモ県', 'address2' => 'サンプル市', 'address3' => '南町'],
        ['zipcode' => '0000001', 'address1' => 'デモ県', 'address2' => 'サンプル市', 'address3' => '南町'],
    ]))]);

    lookupAs(User::factory()->admin()->create(), '0000001')
        ->assertOk()
        ->assertExactJson(['postalCode' => '0000001', 'addresses' => ['デモ県サンプル市北町', 'デモ県サンプル市南町']]);
});

test('該当なしは 404 と「住所を手入力してください」', function () {
    Http::fake([AddressLookup::ENDPOINT.'*' => Http::response(zipcloudBody([]))]);

    lookupAs(null)->assertNotFound()->assertExactJson([
        'message' => '該当する住所が見つかりませんでした。住所を手入力してください',
        'reason' => 'not_found',
    ]);

    Http::fake([AddressLookup::ENDPOINT.'*' => Http::response(['message' => null, 'results' => null, 'status' => 200])]);
    lookupAs(null, '000-0002')->assertNotFound();
});

test('郵便番号の形式が違えば 400 で、外部 API は呼ばない', function (string $input) {
    Http::fake();

    lookupAs(null, $input)->assertStatus(400)->assertExactJson([
        'message' => '郵便番号は7桁の数字で入力してください（例 123-4567）。住所を手入力してください',
        'reason' => 'invalid_postal_code',
    ]);

    Http::assertNothingSent();
})->with(['', '123456', '12345678', 'abc-defg', '031-0001&zipcode=1000001', '031 0001']);

test('タイムアウトは 504 と「住所を手入力してください」。ログに原因を残す（郵便番号は伏せる）', function () {
    Http::fake([AddressLookup::ENDPOINT.'*' => Http::failedConnection(
        'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.AddressLookup::ENDPOINT.'?zipcode=0310001',
    )]);
    Log::spy();

    lookupAs(null)->assertStatus(504)->assertExactJson([
        'message' => '住所の検索がタイムアウトしました。住所を手入力してください',
        'reason' => 'timeout',
    ]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '[address-lookup]')
        && str_contains($message, 'timeout')
        && str_contains($message, 'cURL error 28')
        && ! str_contains($message, '0310001'));
});

test('通信エラー・HTTP エラーは 502 と「住所を手入力してください」', function (Closure $fake, string $logContains) {
    Http::fake([AddressLookup::ENDPOINT.'*' => $fake()]);
    Log::spy();

    lookupAs(null)->assertStatus(502)->assertExactJson([
        'message' => '住所の検索に失敗しました。住所を手入力してください',
        'reason' => 'unavailable',
    ]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'unavailable') && str_contains($message, $logContains));
})->with([
    '接続できない' => [fn () => Http::failedConnection(), 'cURL error 6'],
    'HTTP 500' => [fn () => Http::response('Internal Server Error', 500), 'HTTP 500'],
    'HTTP 503' => [fn () => Http::response('', 503), 'HTTP 503'],
    'zipcloud のエラー応答（本文の status）' => [fn () => Http::response(['message' => '必須パラメータが指定されていません。', 'results' => null, 'status' => 400]), '応答の status: 400'],
]);

test('リダイレクトはたどらず 502（宛先は固定）', function () {
    Http::fake([
        AddressLookup::ENDPOINT.'*' => Http::response('', 302, ['Location' => 'https://redirected.example.com/api/search?zipcode=0310001']),
        'redirected.example.com/*' => Http::response(zipcloudBody()),
    ]);

    lookupAs(null)->assertStatus(502)->assertJsonPath('reason', 'unavailable');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'redirected.example.com'));
});

test('不正な応答は 502（型・件数・長さ・制御文字・郵便番号の不一致）', function (mixed $body) {
    Http::fake([AddressLookup::ENDPOINT.'*' => Http::response($body)]);
    Log::spy();

    lookupAs(null)->assertStatus(502)->assertExactJson([
        'message' => '住所の検索に失敗しました。住所を手入力してください',
        'reason' => 'invalid_response',
    ]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'invalid_response'));
})->with([
    'JSON でない' => ['<html>メンテナンス中</html>'],
    'status がない' => [['results' => []]],
    'status が文字列' => [['status' => '200', 'results' => []]],
    'results が文字列' => [['status' => 200, 'results' => '青森県']],
    'results がオブジェクト' => [['status' => 200, 'results' => ['a' => ['zipcode' => '0310001', 'address1' => '青森県', 'address2' => '八戸市', 'address3' => '類家']]]],
    '住所の項目がない' => [zipcloudBody([['zipcode' => '0310001', 'address1' => '青森県']])],
    '住所が文字列でない' => [zipcloudBody([['zipcode' => '0310001', 'address1' => '青森県', 'address2' => 8, 'address3' => '類家']])],
    '住所に改行' => [zipcloudBody([['zipcode' => '0310001', 'address1' => '青森県', 'address2' => "八戸市\n", 'address3' => '類家']])],
    '住所に表示方向の制御' => [zipcloudBody([['zipcode' => '0310001', 'address1' => '青森県', 'address2' => "\u{202E}八戸市", 'address3' => '類家']])],
    '住所が長すぎる' => [zipcloudBody([['zipcode' => '0310001', 'address1' => str_repeat('あ', 201), 'address2' => '', 'address3' => '']])],
    '別の郵便番号の結果' => [zipcloudBody([['zipcode' => '1000001', 'address1' => '東京都', 'address2' => '千代田区', 'address3' => '千代田']])],
    '住所が空' => [zipcloudBody([['zipcode' => '0310001', 'address1' => '', 'address2' => '', 'address3' => '']])],
    '候補が多すぎる' => [zipcloudBody(array_fill(0, 101, ['zipcode' => '0310001', 'address1' => '青森県', 'address2' => '八戸市', 'address3' => '類家']))],
]);

test('結果は郵便番号ごとにキャッシュし、同じ郵便番号では外部 API を呼ばない（該当なしもキャッシュする）', function () {
    Http::fake([
        AddressLookup::ENDPOINT.'?zipcode=0310001' => Http::response(zipcloudBody()),
        AddressLookup::ENDPOINT.'?zipcode=0000009' => Http::response(zipcloudBody([])),
    ]);
    $staff = User::factory()->staff()->create();

    lookupAs($staff, '031-0001')->assertOk();
    lookupAs($staff, '0310001')->assertOk()->assertJsonPath('addresses', ['青森県八戸市類家']);
    lookupAs(User::factory()->admin()->create(), '０３１０００１')->assertOk();
    lookupAs($staff, '000-0009')->assertNotFound();
    lookupAs($staff, '0000009')->assertNotFound();

    Http::assertSentCount(2);
});

test('一時的な失敗（タイムアウト・HTTP エラー・不正な応答）はキャッシュせず、次は外部 API を呼び直す', function () {
    Http::fakeSequence(AddressLookup::ENDPOINT.'*')
        ->pushFailedConnection('cURL error 28: Operation timed out')
        ->push('', 500)
        ->push('<html>')
        ->push(zipcloudBody());

    lookupAs(null)->assertStatus(504);
    lookupAs(null)->assertStatus(502);
    lookupAs(null)->assertStatus(502);
    lookupAs(null)->assertOk();
    lookupAs(null)->assertOk();

    Http::assertSentCount(4);
});

test('回数制限: ユーザーごとに 1 分 30 回まで。超えたら 429 と Retry-After（別のユーザーは数えない）', function () {
    Http::fake([AddressLookup::ENDPOINT.'*' => Http::response(zipcloudBody())]);
    $staff = User::factory()->staff()->create();

    foreach (range(1, PartnerController::ADDRESS_LOOKUP_LIMIT) as $i) {
        lookupAs($staff)->assertOk();
    }

    $response = lookupAs($staff)->assertTooManyRequests()->assertExactJson([
        'message' => '住所の検索回数が多すぎます。しばらくしてからもう一度お試しいただくか、住所を手入力してください',
        'reason' => 'rate_limited',
    ]);
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);

    lookupAs(User::factory()->staff()->create())->assertOk();

    // キャッシュに当たった検索も数える。外部 API を呼んだのは最初の 1 回だけ
    Http::assertSentCount(1);

    // 1 分たてばまた使える
    $this->travel(61)->seconds();
    lookupAs($staff)->assertOk();
});

test('結果の解釈（AddressLookup::parse）は純粋な関数で、HTTP 200 のままのエラー応答を unavailable にする', function () {
    expect(AddressLookup::parse('0310001', zipcloudBody())->addresses)->toBe(['青森県八戸市類家']);
    expect(AddressLookup::parse('0310001', zipcloudBody([]))->failure)->toBe(AddressLookupFailure::NotFound);
    expect(AddressLookup::parse('0310001', ['status' => 500, 'results' => null, 'message' => 'error'])->failure)->toBe(AddressLookupFailure::Unavailable);
    expect(AddressLookup::parse('0310001', null)->failure)->toBe(AddressLookupFailure::InvalidResponse);
});
