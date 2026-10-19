<?php

use App\Auth\Permission;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Users\ApiTokenAuth;
use App\Users\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\SessionRequests;

/**
 * API トークン（Sanctum。docs/spec.md §6-2）の発行・失効と、REST API（#10）で使う Bearer 認証。
 * REST API のルートはまだないので、認証の仕組みはテスト用のルート（api ミドルウェア＋ApiTokenAuth::MIDDLEWARE）で確かめる
 */
uses(SessionRequests::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['email' => 'admin-a@example.test']);

    Route::middleware(['api', ApiTokenAuth::MIDDLEWARE])->group(function () {
        Route::get('/api/_test/me', fn (Request $request) => ['id' => $request->user()?->getAuthIdentifier()]);
        // 権限はトークンの持ち主のロールで、Web と同じ Gate で検査する
        Route::get('/api/_test/edit', fn () => ['ok' => true])->can(Permission::MasterEdit);
    });
    // Cookie のセッションが付く（web ミドルウェアの）ルートでも、Bearer 以外では通らないことの確認用
    Route::middleware(['web', ApiTokenAuth::MIDDLEWARE])->get('/api/_test/web-me', fn (Request $request) => ['id' => $request->user()?->getAuthIdentifier()]);
});

/** 発行（画面と同じ JSON の要求）して平文のトークンを返す */
function issueToken(User $admin, User $target, string $name = '連携'): string
{
    $token = test()->actingAs($admin)->postJson(route('users.tokens.store', $target), ['name' => $name])->assertCreated()->json('token');
    expect($token)->toBeString();

    return $token;
}

/** Bearer トークンで API を呼ぶ（テストでは同じアプリのまま続けるので、読み込み済みのユーザーを捨ててから） */
function callApi(?string $authorization, string $uri = '/api/_test/me'): TestResponse
{
    app('auth')->forgetGuards();
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];
    $response = test()->getJson($uri, $headers);
    // Bearer の要求で Authenticate（auth:sanctum）が既定のガードを sanctum に切り替えたままになるので、同じテストの次の
    // Web の要求のために web に戻す（本番はリクエストごとにアプリを作り直すので起きない）
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return $response;
}

describe('発行', function () {
    test('平文は応答で 1 回だけ返し、DB には SHA-256 のハッシュだけを保存する（操作履歴を残す）', function () {
        $target = User::factory()->staff()->create(['name' => '連携用', 'email' => 'api@example.test']);

        $response = $this->actingAs($this->admin)->postJson(route('users.tokens.store', $target), ['name' => '在庫連携']);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $plain = $response->json('token');
        expect($plain)->toMatch('/\A\d+\|sf_[A-Za-z0-9]{40}[0-9a-f]{8}\z/')
            ->and(ApiTokenAuth::isTokenFormat($plain))->toBeTrue();
        [$id, $secret] = explode('|', $plain, 2);
        $row = DB::table('personal_access_tokens')->where('id', $id)->first();
        expect($row->token)->toBe(hash('sha256', $secret))
            ->and($row->tokenable_id)->toBe($target->id)
            ->and($row->name)->toBe('在庫連携')
            ->and($row->revoked_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'token_create')->sole()->only(['user_id', 'target_type', 'target_id', 'summary']))->toBe([
                'user_id' => $this->admin->id,
                'target_type' => 'api_token',
                'target_id' => $id,
                'summary' => 'ユーザー「連携用」（api@example.test）の API トークン「在庫連携」を発行しました',
            ]);

        // 詳細画面の一覧にはトークン本体を出さない
        $this->get(route('users.show', $target))->assertOk()->assertDontSee($secret)->assertDontSee($row->token);
        expect(AuditLog::query()->where('summary', 'like', '%'.$secret.'%')->exists())->toBeFalse();
    });

    test('平文のトークンはセッションに保存しない（フラッシュに載せない）', function () {
        $this->loginForSession('admin-a@example.test', 'password');
        $session = $this->loginForSession('admin-a@example.test', 'password');
        $target = User::factory()->create();

        $plain = $this->usingSession($session)->postJson(route('users.tokens.store', $target), ['name' => '連携'])->assertCreated()->json('token');
        [, $secret] = explode('|', $plain, 2);
        $containsSecret = fn () => DB::table('sessions')->get('payload')->contains(fn ($s) => str_contains(base64_decode($s->payload), $secret));

        // 発行の直後（次の要求でフラッシュが消費される前）に、操作したセッションが保存されていて、平文を含まない
        expect(DB::table('sessions')->where('id', $session)->exists())->toBeTrue()
            ->and($containsSecret())->toBeFalse();

        $this->usingSession($session)->get(route('users.show', $target))->assertOk();
        expect($containsSecret())->toBeFalse();
    });

    test('有効なトークンは 1 ユーザー 10 件まで（失効させれば発行できる）', function () {
        $target = User::factory()->create();
        for ($i = 1; $i <= UserService::MAX_ACTIVE_TOKENS_PER_USER; $i++) {
            issueToken($this->admin, $target, "連携{$i}");
        }

        $this->postJson(route('users.tokens.store', $target), ['name' => '11 件目'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form' => '有効な API トークンは 1 ユーザーにつき 10 件までです。使っていないトークンを失効させてから発行してください']);

        $first = $target->tokens()->orderBy('id')->firstOrFail();
        $this->post(route('users.tokens.revoke', ['user' => $target, 'token' => $first->id]));
        $this->postJson(route('users.tokens.store', $target), ['name' => '11 件目'])->assertCreated();
        expect($target->tokens()->whereNull('revoked_at')->count())->toBe(10);
    });

    test('無効なユーザーには発行しない', function () {
        $target = User::factory()->inactive()->create();

        $this->actingAs($this->admin)->postJson(route('users.tokens.store', $target), ['name' => '連携'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form' => UserService::INACTIVE_TOKEN_MESSAGE]);
        expect(ApiToken::query()->count())->toBe(0);
    });

    test('名前の検証', function (string $name, string $message) {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->postJson(route('users.tokens.store', $target), ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => $message]);
    })->with([
        '空' => ['', 'トークンの名前 を入力してください'],
        '長すぎる' => [str_repeat('あ', 51), 'トークンの名前 は 50 文字以内で入力してください'],
        '改行' => ["a\nb", 'トークンの名前 に改行・タブなどの制御文字は使えません'],
    ]);
});

describe('失効', function () {
    test('失効させると行は残り（一覧に「失効」）、操作履歴を残す。もう一度失効させても何もしない', function () {
        $target = User::factory()->create(['name' => '対象', 'email' => 'target@example.test']);
        $plain = issueToken($this->admin, $target, '連携');
        $id = (int) explode('|', $plain)[0];

        $this->post(route('users.tokens.revoke', ['user' => $target, 'token' => $id]))->assertRedirect(route('users.show', $target));
        $this->post(route('users.tokens.revoke', ['user' => $target, 'token' => $id]))->assertRedirect(route('users.show', $target));

        expect(ApiToken::query()->findOrFail($id)->revoked_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'token_revoke')->sole()->summary)->toBe('ユーザー「対象」（target@example.test）の API トークン「連携」を失効させました');
        $this->get(route('users.show', $target))->assertInertia(fn ($page) => $page->has('tokens', 1)->whereNot('tokens.0.revokedAt', null));
    });

    test('ほかのユーザーのトークン・存在しない・範囲外の ID は「見つかりません」で何も変わらない', function (string $tokenId) {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plain = issueToken($this->admin, $owner);
        $id = explode('|', $plain)[0];

        $uri = '/users/'.$other->id.'/tokens/'.str_replace('{owner}', $id, $tokenId).'/revoke';
        $this->post($uri)->assertSessionHasErrors(['form' => UserService::TOKEN_NOT_FOUND_MESSAGE]);

        expect(ApiToken::query()->whereNotNull('revoked_at')->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'token_revoke')->count())->toBe(0);
    })->with([
        'ほかのユーザーのトークン' => ['{owner}'],
        '存在しない' => ['999999'],
        'PHP の整数の範囲外' => ['99999999999999999999999'],
    ]);
});

describe('Bearer 認証（#10 の REST API で使う）', function () {
    test('発行したトークンで認証でき、最終使用日時を記録する', function () {
        $target = User::factory()->staff()->create();
        $plain = issueToken($this->admin, $target);

        callApi("Bearer {$plain}")->assertOk()->assertJson(['id' => $target->id]);
        // スキーム名は大文字・小文字を区別しない
        callApi("bearer {$plain}")->assertOk();

        expect($target->tokens()->sole()->last_used_at)->not->toBeNull();
    });

    test('失効したトークンは 401（ヘッダーがないときと同じ応答）', function () {
        $target = User::factory()->create();
        $plain = issueToken($this->admin, $target);
        $id = (int) explode('|', $plain)[0];
        $this->post(route('users.tokens.revoke', ['user' => $target, 'token' => $id]));

        $revoked = callApi("Bearer {$plain}")->assertUnauthorized();
        $missing = callApi(null)->assertUnauthorized();
        expect($revoked->json())->toBe($missing->json());
    });

    test('トークンが失効していなくても、持ち主が無効なら 401', function () {
        $target = User::factory()->create();
        $plain = issueToken($this->admin, $target);
        callApi("Bearer {$plain}")->assertOk();

        // 失効させずにユーザーだけ無効にする（無効化の操作は失効もさせるので、ここでは直接変える）
        DB::table('users')->where('id', $target->id)->update(['is_active' => false]);

        callApi("Bearer {$plain}")->assertUnauthorized();
        expect($target->tokens()->sole()->revoked_at)->toBeNull();
    });

    test('無効化の操作のあとは 401（失効もしている）。再有効化してもトークンは戻らない', function () {
        $target = User::factory()->create();
        $plain = issueToken($this->admin, $target);

        $this->actingAs($this->admin)->post(route('users.deactivate', $target));
        callApi("Bearer {$plain}")->assertUnauthorized();
        $this->actingAs($this->admin)->post(route('users.reactivate', $target));
        callApi("Bearer {$plain}")->assertUnauthorized();
    });

    test('権限は持ち主のいまのロールに従う（ロール変更は次のリクエストから効く）', function () {
        $target = User::factory()->staff()->create();
        $plain = issueToken($this->admin, $target);
        callApi("Bearer {$plain}", '/api/_test/edit')->assertOk();

        $this->actingAs($this->admin)->put(route('users.role', $target), ['role' => Role::Viewer->value]);

        callApi("Bearer {$plain}", '/api/_test/edit')->assertForbidden();
        callApi("Bearer {$plain}")->assertOk();
    });

    test('不正な形式・存在しないトークンは 401（DB の範囲外の ID でも例外にしない）', function (string $authorization) {
        $target = User::factory()->create();
        $plain = issueToken($this->admin, $target);
        [$id, $secret] = explode('|', $plain, 2);
        $authorization = str_replace(['{id}', '{secret}', '{plain}'], [$id, $secret, $plain], $authorization);

        callApi($authorization)->assertUnauthorized()->assertJson(['message' => 'ログインが必要です']);
    })->with([
        'Bearer でない' => ['Basic {plain}'],
        'トークンなし' => ['Bearer '],
        'id なし（ハッシュの照合だけ）' => ['Bearer {secret}'],
        '形式が違う' => ['Bearer abc'],
        '秘密の部分が違う' => ['Bearer {id}|sf_'.str_repeat('a', 40).'00000000'],
        '存在しない id' => ['Bearer 999999|{secret}'],
        'BIGINT の範囲外の id' => ['Bearer 99999999999999999999|{secret}'],
        '接頭辞なし' => ['Bearer {id}|'.str_repeat('a', 40).'00000000'],
        '2 つ並べる' => ['Bearer {plain} {plain}'],
        '前に文字' => ['xBearer {plain}'],
    ]);

    test('Cookie のセッション（ログイン中）だけでは API に入れない', function () {
        // actingAs: Web のガードにログイン中のユーザーがいる状態（Sanctum の guard を空にしていないと、これで通ってしまう）
        $this->actingAs($this->admin);
        $this->getJson('/api/_test/me')->assertUnauthorized();

        // 実際のログインの Cookie を付けて、セッションのある（web ミドルウェアの）ルートに送る
        $session = $this->loginForSession('admin-a@example.test', 'password');
        $this->dashboardWithSession($session)->assertOk();
        $this->usingSession($session)->getJson('/api/_test/web-me')->assertUnauthorized();
    });

    test('Web の画面は Bearer トークンでは開けない（Web はセッションだけ）', function () {
        $plain = issueToken($this->admin, $this->admin);
        app('auth')->forgetGuards();

        $this->usingSession(null)->getJson(route('dashboard'), ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    });
});
