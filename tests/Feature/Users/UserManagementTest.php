<?php

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Users\DemoAccounts;
use App\Users\UserService;
use Database\Seeders\Demo\DemoData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SessionRequests;

/**
 * ユーザー管理（docs/spec.md §2・§3・§5-8）。admin のみ。自分自身の無効化・ロール変更は不可、デモ用アカウントは保護、
 * 無効化・再有効化・パスワード再設定でそのユーザーのセッション（sessions テーブル）を削除する。
 * セッションの確認は、実際のログイン（POST /login）で作った database セッションの Cookie で行う（SessionRequests）
 */
uses(SessionRequests::class);

const NEW_PASSWORD = 'new-password-5678';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => '管理者A', 'email' => 'admin-a@example.test']);
});

function sessionCount(User $user): int
{
    return DB::table('sessions')->where('user_id', $user->id)->count();
}

/** 対象の資格情報（パスワードのハッシュ・remember_token）とセッションの行（拒否された操作で変わっていないことの確認用） */
function credentialSnapshot(User $user): array
{
    $row = DB::table('users')->where('id', $user->id)->first(['password', 'remember_token', 'role', 'is_active']);

    return [
        'user' => (array) $row,
        'sessions' => DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->get(['id', 'payload'])->map(fn ($s) => (array) $s)->all(),
        'tokens' => DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->orderBy('id')->get(['id', 'revoked_at'])->map(fn ($t) => (array) $t)->all(),
    ];
}

describe('画面', function () {
    test('admin は一覧・登録・詳細を開ける（デモ用アカウント・自分自身の目印つき）', function () {
        $demo = User::factory()->create(['email' => 'viewer@example.com', 'name' => 'デモ閲覧者']);

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/Index')
                ->has('users', 2)
                ->where('users.0.email', 'admin-a@example.test')
                ->where('users.0.isSelf', true)
                ->where('users.0.isDemo', false)
                ->where('users.1.isDemo', true)
                ->missing('users.0.password')
                ->where('pagination.total', 2));

        $this->get(route('users.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('users/Create')->has('roleOptions', 3));
        $this->get(route('users.show', $demo))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('user.isDemo', true)
            ->where('maxActiveTokens', UserService::MAX_ACTIVE_TOKENS_PER_USER)
            ->has('tokens', 0));
    });

    test('範囲外・存在しない ID は 404（例外にしない）', function (string $id) {
        $this->actingAs($this->admin)->get("/users/{$id}")->assertNotFound();
        $this->post("/users/{$id}/deactivate")->assertNotFound();
        $this->put("/users/{$id}/role", ['role' => 'staff'])->assertNotFound();
    })->with([
        '存在しない' => ['999999'],
        'BIGINT の範囲外' => ['99999999999999999999999'],
    ]);
});

describe('権限（admin のみ）', function () {
    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    function userManagementRoutes(): array
    {
        return [
            '一覧' => ['get', '/users', []],
            '登録画面' => ['get', '/users/create', []],
            '登録' => ['post', '/users', ['name' => '新規', 'email' => 'new@example.test', 'role' => 'admin', 'password' => 'password-1234']],
            '詳細' => ['get', '/users/{target}', []],
            'ロール変更' => ['put', '/users/{target}/role', ['role' => 'admin']],
            '無効化' => ['post', '/users/{target}/deactivate', []],
            '再有効化' => ['post', '/users/{target}/reactivate', []],
            'パスワード再設定' => ['put', '/users/{target}/password', ['password' => NEW_PASSWORD]],
            'トークン発行' => ['post', '/users/{target}/tokens', ['name' => 'x']],
            'トークン失効' => ['post', '/users/{target}/tokens/{token}/revoke', []],
            '操作履歴' => ['get', '/audit-logs', []],
        ];
    }

    test('staff・viewer は 403、何も変わらない', function (Role $role, string $method, string $uri, array $data) {
        $actor = User::factory()->role($role)->create();
        $target = User::factory()->create();
        $token = $target->createToken('既存');
        $before = credentialSnapshot($target);
        $usersBefore = User::query()->count();
        $logsBefore = AuditLog::query()->count();

        $uri = str_replace(['{target}', '{token}'], [(string) $target->id, (string) $token->accessToken->getKey()], $uri);
        $this->actingAs($actor)->{$method}($uri, $data)->assertForbidden();
        $this->actingAs($actor)->{$method.'Json'}($uri, $data)->assertForbidden()->assertJson(['message' => 'この操作を行う権限がありません']);

        expect(credentialSnapshot($target))->toBe($before)
            ->and(User::query()->count())->toBe($usersBefore)
            ->and(AuditLog::query()->count())->toBe($logsBefore);
    })->with([
        'staff' => [Role::Staff],
        'viewer' => [Role::Viewer],
    ])->with(userManagementRoutes());

    test('未ログインはログイン画面へ（JSON は 401）', function (string $method, string $uri, array $data) {
        $target = User::factory()->create();
        $uri = str_replace(['{target}', '{token}'], [(string) $target->id, '1'], $uri);

        $this->{$method}($uri, $data)->assertRedirect(route('login'));
        $this->{$method.'Json'}($uri, $data)->assertUnauthorized();
    })->with(userManagementRoutes());

    test('サイドバーの「ユーザー管理」「操作履歴」は admin にだけ許可される', function () {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($p) => collect($p)->contains('user.manage') && collect($p)->contains('audit.view')));
        $this->actingAs(User::factory()->staff()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($p) => ! collect($p)->contains('user.manage') && ! collect($p)->contains('audit.view')));
    });

    test('拒否されたパスワード再設定では、対象の資格情報とセッションが変わらない（元のパスワード・Cookie が使え、指定したパスワードは使えない）', function (Role $role) {
        $target = User::factory()->create(['email' => 'target@example.test']);
        $session = $this->loginForSession('target@example.test', 'password');
        $actor = User::factory()->role($role)->create();
        $before = credentialSnapshot($target);

        $this->usingSession(null)->actingAs($actor)->put(route('users.password', $target), ['password' => NEW_PASSWORD, 'revoke_tokens' => true])->assertForbidden();

        expect(credentialSnapshot($target))->toBe($before);
        $this->dashboardWithSession($session)->assertOk();
        $this->usingSession(null)->post(route('login.store'), ['email' => 'target@example.test', 'password' => NEW_PASSWORD])->assertSessionHasErrors('email');
        $this->loginForSession('target@example.test', 'password');
    })->with([
        'staff' => [Role::Staff],
        'viewer' => [Role::Viewer],
    ]);

    test('認可の後に操作者が無効化・降格されていたら、どの操作も 403 で何も変わらない（ロックの後に確かめ直す）', function (string $change, string $method, string $uri, array $data) {
        $target = User::factory()->staff()->create();
        $token = $target->createToken('既存');
        $before = credentialSnapshot($target);
        $usersBefore = User::query()->count();
        $logsBefore = AuditLog::query()->count();

        // actingAs のユーザー（メモリ上）は有効な admin のまま。ルートの can: と Policy は通り、サービスがロックの後に DB から読み直して拒否する
        $this->actingAs($this->admin);
        DB::table('users')->where('id', $this->admin->id)->update($change === 'inactive' ? ['is_active' => false] : ['role' => 'staff']);

        $uri = str_replace(['{target}', '{token}'], [(string) $target->id, (string) $token->accessToken->getKey()], $uri);
        $this->{$method}($uri, $data)->assertForbidden();

        expect(credentialSnapshot($target))->toBe($before)
            ->and(User::query()->count())->toBe($usersBefore)
            ->and(AuditLog::query()->count())->toBe($logsBefore);
    })->with([
        '無効化された' => ['inactive'],
        '降格された' => ['demoted'],
    ])->with([
        '登録' => ['postJson', '/users', ['name' => '新規', 'email' => 'new@example.test', 'role' => 'admin', 'password' => 'password-1234']],
        'ロール変更' => ['put', '/users/{target}/role', ['role' => 'admin']],
        '無効化' => ['post', '/users/{target}/deactivate', []],
        'パスワード再設定' => ['put', '/users/{target}/password', ['password' => NEW_PASSWORD]],
        'トークン発行' => ['postJson', '/users/{target}/tokens', ['name' => 'x']],
        'トークン失効' => ['post', '/users/{target}/tokens/{token}/revoke', []],
    ]);
});

describe('登録', function () {
    test('登録したユーザーで、そのパスワードでログインできる（メールアドレスは小文字で保存・操作履歴を同じトランザクションで残す）', function () {
        $response = $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => '新しい担当者',
            'email' => 'New.Staff@Example.TEST',
            'role' => 'staff',
            'password' => ' pass word 1234 ',
        ]);

        $user = User::query()->where('email', 'new.staff@example.test')->firstOrFail();
        $response->assertRedirect(route('users.show', $user));
        expect($user->role)->toBe(Role::Staff)
            ->and($user->is_active)->toBeTrue()
            ->and($user->name)->toBe('新しい担当者')
            ->and(Hash::check(' pass word 1234 ', $user->password))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'create')->where('target_type', 'user')->sole()->only(['user_id', 'target_id', 'summary']))
            ->toBe(['user_id' => $this->admin->id, 'target_id' => (string) $user->id, 'summary' => 'ユーザー「新しい担当者」（new.staff@example.test）を担当者として登録しました']);

        // 前後の空白も含めてそのまま（trim しない）
        $session = $this->loginForSession('new.staff@example.test', ' pass word 1234 ');
        $this->dashboardWithSession($session)->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'staff'));
    });

    test('メールアドレスの重複は登録しない（大文字・小文字の違いも同じ扱い）', function () {
        User::factory()->create(['email' => 'dup@example.test']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => '重複', 'email' => 'DUP@example.test', 'role' => 'viewer', 'password' => 'password-1234',
        ])->assertSessionHasErrors(['email' => 'メールアドレス「dup@example.test」はすでに登録されています']);

        expect(User::query()->count())->toBe(2)->and(AuditLog::query()->count())->toBe(0);
    });

    test('検証の後に同じメールアドレスが登録されていたら（一意制約）、項目のエラーにする', function () {
        $service = app(UserService::class);
        $input = ['name' => '同時', 'email' => 'race@example.test', 'role' => Role::Viewer, 'password' => 'password-1234'];
        $service->create($this->admin, $input);

        expect(fn () => $service->create($this->admin, $input))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['email' => ['メールアドレス「race@example.test」はすでに登録されています']]));
        expect(User::query()->where('email', 'race@example.test')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'create')->count())->toBe(1);
    });

    test('入力の検証（日本語のエラー）', function (array $input, string $field, string $message) {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => '新規', 'email' => 'new@example.test', 'role' => 'viewer', 'password' => 'password-1234', ...$input,
        ])->assertSessionHasErrors([$field => $message]);

        expect(User::query()->count())->toBe(1);
    })->with([
        '名前が空' => [['name' => ''], 'name', '名前 を入力してください'],
        '名前に改行' => [['name' => "a\nb"], 'name', '名前 に改行・タブなどの制御文字は使えません'],
        'メールアドレスの形式' => [['email' => 'not-an-email'], 'email', 'メールアドレスの形式が正しくありません（例 user@example.com）'],
        'メールアドレスに全角' => [['email' => 'ｕｓｅｒ@example.test'], 'email', 'メールアドレスは半角の英数字・記号で入力してください（例 user@example.com）'],
        'ロールが不正' => [['role' => 'owner'], 'role', 'ロールを選択してください'],
        'パスワードが短い' => [['password' => 'short'], 'password', '初期パスワード は 8 文字以上で入力してください'],
        'パスワードが 72 バイトを超える' => [['password' => str_repeat('a', 73)], 'password', '初期パスワード は 72 バイト以内で入力してください（半角英数字・記号は 1 文字 1 バイト、全角は 1 文字 3 バイト）'],
        'パスワードに改行' => [['password' => "password\n1234"], 'password', '初期パスワード に改行・タブなどの制御文字は使えません'],
    ]);
});

describe('ロール変更', function () {
    test('ロールを変更し、変更前後を操作履歴に残す（同じロールなら何もしない）', function () {
        $target = User::factory()->create(['name' => '対象', 'email' => 'target@example.test']);

        $this->actingAs($this->admin)->put(route('users.role', $target), ['role' => 'admin'])->assertRedirect(route('users.show', $target));
        expect($target->fresh()->role)->toBe(Role::Admin)
            ->and(AuditLog::query()->where('action', 'role_change')->sole()->summary)->toBe('ユーザー「対象」（target@example.test）のロールを変更しました（閲覧者 → 管理者）');

        $this->put(route('users.role', $target), ['role' => 'admin'])->assertRedirect(route('users.show', $target));
        expect(AuditLog::query()->where('action', 'role_change')->count())->toBe(1);
    });

    test('自分自身のロールは変更できない', function () {
        $this->actingAs($this->admin)->put(route('users.role', $this->admin), ['role' => 'viewer'])
            ->assertSessionHasErrors(['form' => UserService::SELF_ROLE_MESSAGE]);

        expect($this->admin->fresh()->role)->toBe(Role::Admin)->and(AuditLog::query()->count())->toBe(0);
    });

    test('不正なロールは検証のエラー', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->put(route('users.role', $target), ['role' => 'root'])->assertSessionHasErrors(['role' => 'ロールを選択してください']);
        expect($target->fresh()->role)->toBe(Role::Viewer);
    });
});

describe('最後の有効な admin', function () {
    test('判定: 有効な admin が対象だけなら、降格・無効化は 0 人になる', function () {
        $service = app(UserService::class);
        // admin は $this->admin の 1 人だけ
        expect($service->removesLastAdmin($this->admin, Role::Staff, true))->toBeTrue()
            ->and($service->removesLastAdmin($this->admin, Role::Admin, false))->toBeTrue()
            ->and($service->removesLastAdmin($this->admin, Role::Admin, true))->toBeFalse();

        $other = User::factory()->admin()->create();
        expect($service->removesLastAdmin($this->admin, Role::Staff, true))->toBeFalse();

        // 無効な admin は数えない
        $other->forceFill(['is_active' => false])->save();
        expect($service->removesLastAdmin($this->admin, Role::Staff, true))->toBeTrue()
            // admin でない・無効な対象の変更は数えない
            ->and($service->removesLastAdmin($other->fresh(), Role::Staff, false))->toBeFalse();
    });

    test('ただ 1 人の admin は、自分を降格・無効化できない（自分自身の変更の拒否が先に効く）', function () {
        $this->actingAs($this->admin)->put(route('users.role', $this->admin), ['role' => 'staff'])->assertSessionHasErrors('form');
        $this->post(route('users.deactivate', $this->admin))->assertSessionHasErrors(['form' => UserService::SELF_DEACTIVATE_MESSAGE]);

        expect($this->admin->fresh()->only(['role', 'is_active']))->toBe(['role' => Role::Admin, 'is_active' => true]);
    });
});

describe('無効化・再有効化', function () {
    test('無効化すると、既存のセッション（2 本とも）・API トークン・ログインしたままにする Cookie が使えなくなる（ほかのユーザーのセッションは残る）', function () {
        $target = User::factory()->staff()->create(['name' => '対象', 'email' => 'target@example.test', 'remember_token' => 'old-remember-token']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $first = $this->loginForSession('target@example.test', 'password');
        $second = $this->loginForSession('target@example.test', 'password');
        $otherSession = $this->loginForSession('other@example.test', 'password');
        $tokens = [$target->createToken('連携1'), $target->createToken('連携2')];
        $tokens[1]->accessToken->forceFill(['revoked_at' => now()->subDay()])->save();
        expect(sessionCount($target))->toBe(2);

        $this->usingSession(null)->actingAs($this->admin)->post(route('users.deactivate', $target))->assertRedirect(route('users.show', $target));

        $target->refresh();
        expect($target->is_active)->toBeFalse()
            ->and($target->getRememberToken())->not->toBe('old-remember-token')
            ->and(sessionCount($target))->toBe(0)
            ->and(sessionCount($other))->toBe(1)
            ->and(ApiToken::query()->whereNull('revoked_at')->where('tokenable_id', $target->id)->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'deactivate')->sole()->summary)
            ->toBe('ユーザー「対象」（target@example.test）を無効にしました（ログイン中のセッション 2 件を終了、API トークン 1 件を失効）');
        $this->dashboardWithSession($first)->assertRedirect(route('login'));
        $this->dashboardWithSession($second)->assertRedirect(route('login'));
        $this->dashboardWithSession($otherSession)->assertOk();
        $this->usingSession(null)->post(route('login.store'), ['email' => 'target@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
    });

    test('自分自身は無効化できない', function () {
        $this->actingAs($this->admin)->post(route('users.deactivate', $this->admin))
            ->assertSessionHasErrors(['form' => UserService::SELF_DEACTIVATE_MESSAGE]);

        expect($this->admin->fresh()->is_active)->toBeTrue();
    });

    test('再有効化: 元のパスワードでログインでき、無効中に作られたセッションは削除する（トークンは戻らない）', function () {
        $target = User::factory()->create(['name' => '対象', 'email' => 'target@example.test']);
        $token = $target->createToken('連携');
        $this->actingAs($this->admin)->post(route('users.deactivate', $target));
        // 無効化と同時に進んでいたログインが、無効化の後にセッションを作った場合
        DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $target->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);

        $this->post(route('users.reactivate', $target))->assertRedirect(route('users.show', $target));

        expect($target->fresh()->is_active)->toBeTrue()
            ->and(sessionCount($target))->toBe(0)
            ->and($token->accessToken->fresh()->revoked_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'reactivate')->sole()->summary)->toBe('ユーザー「対象」（target@example.test）を再度有効にしました（セッション 1 件を削除）');
        $this->loginForSession('target@example.test', 'password');
    });

    test('すでにその状態なら何もしない（操作履歴も残さない）', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->post(route('users.reactivate', $target))->assertRedirect(route('users.show', $target));
        expect(AuditLog::query()->count())->toBe(0);
    });
});

describe('パスワード再設定', function () {
    test('ほかのユーザー: 新しいパスワードでだけログインでき、既存のセッションは終了する（トークンは既定で失効）', function () {
        $target = User::factory()->create(['name' => '対象', 'email' => 'target@example.test', 'remember_token' => 'old-remember-token']);
        $session = $this->loginForSession('target@example.test', 'password');
        $token = $target->createToken('連携');

        $this->usingSession(null)->actingAs($this->admin)->put(route('users.password', $target), ['password' => NEW_PASSWORD])
            ->assertRedirect(route('users.show', $target));

        expect(sessionCount($target))->toBe(0)
            ->and($target->fresh()->getRememberToken())->not->toBe('old-remember-token')
            ->and($token->accessToken->fresh()->revoked_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'password_reset')->sole()->summary)
            ->toBe('ユーザー「対象」（target@example.test）のパスワードを再設定しました（ログイン中のセッション 1 件を終了、API トークン 1 件を失効）');
        $this->dashboardWithSession($session)->assertRedirect(route('login'));
        $this->usingSession(null)->post(route('login.store'), ['email' => 'target@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->loginForSession('target@example.test', NEW_PASSWORD);
        // パスワードは履歴に残さない
        expect(AuditLog::query()->where('summary', 'like', '%'.NEW_PASSWORD.'%')->exists())->toBeFalse();
    });

    test('「API トークンもすべて失効させる」を外すと、トークンは残る（セッションは終了する）', function () {
        $target = User::factory()->create(['email' => 'target@example.test']);
        $this->loginForSession('target@example.test', 'password');
        $token = $target->createToken('連携');

        $this->usingSession(null)->actingAs($this->admin)->put(route('users.password', $target), ['password' => NEW_PASSWORD, 'revoke_tokens' => false]);

        expect(sessionCount($target))->toBe(0)
            ->and($token->accessToken->fresh()->revoked_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'password_reset')->sole()->summary)->toContain('API トークンは失効させず');
    });

    test('自分自身: 操作したセッションも含めて全セッションが終わり、ログイン画面へ移る', function () {
        $first = $this->loginForSession('admin-a@example.test', 'password');
        $second = $this->loginForSession('admin-a@example.test', 'password');

        $this->usingSession($first)->put(route('users.password', $this->admin), ['password' => NEW_PASSWORD])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'パスワードを再設定しました。新しいパスワードでログインしてください');

        expect(sessionCount($this->admin))->toBe(0);
        $this->dashboardWithSession($first)->assertRedirect(route('login'));
        $this->dashboardWithSession($second)->assertRedirect(route('login'));
        $this->loginForSession('admin-a@example.test', NEW_PASSWORD);
    });

    test('入力の検証', function (array $input, string $field, string $message) {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->put(route('users.password', $target), $input)->assertSessionHasErrors([$field => $message]);
        expect(Hash::check('password', $target->fresh()->password))->toBeTrue();
    })->with([
        '空' => [['password' => ''], 'password', '新しいパスワード を入力してください'],
        '短い' => [['password' => '1234567'], 'password', '新しいパスワード は 8 文字以上で入力してください'],
        'トークンの指定が不正' => [['password' => NEW_PASSWORD, 'revoke_tokens' => 'maybe'], 'revoke_tokens', 'API トークンの失効の指定が正しくありません'],
    ]);
});

describe('デモ用アカウントの保護', function () {
    beforeEach(function () {
        $this->demos = collect(DemoData::USERS)->map(fn (array $u) => User::factory()->role(Role::from($u['role']))->create(['email' => $u['email'], 'name' => $u['name']]));
    });

    test('無効化・ロール変更・パスワード再設定を拒否し、何も変わらない', function (string $method, string $route, array $data) {
        foreach ($this->demos as $demo) {
            $before = credentialSnapshot($demo);

            $this->actingAs($this->admin)->{$method}(route($route, $demo), $data)->assertSessionHasErrors(['form' => DemoAccounts::PROTECTED_MESSAGE]);

            expect(credentialSnapshot($demo))->toBe($before);
        }
        expect(AuditLog::query()->count())->toBe(0);
    })->with([
        '無効化' => ['post', 'users.deactivate', []],
        'ロール変更' => ['put', 'users.role', ['role' => 'staff']],
        'パスワード再設定' => ['put', 'users.password', ['password' => NEW_PASSWORD]],
    ]);

    test('デモの admin 自身でも、自分のパスワードは再設定できない', function () {
        $demoAdmin = $this->demos->first();

        $this->actingAs($demoAdmin)->put(route('users.password', $demoAdmin), ['password' => NEW_PASSWORD])
            ->assertSessionHasErrors(['form' => DemoAccounts::PROTECTED_MESSAGE]);
        expect(Hash::check('password', $demoAdmin->fresh()->password))->toBeTrue();
    });

    test('API トークンの発行・失効はできる（REST API を試せるように）', function () {
        $demo = $this->demos->last();

        $id = $this->actingAs($this->admin)->postJson(route('users.tokens.store', $demo), ['name' => 'お試し'])->assertCreated()->json('id');
        $this->post(route('users.tokens.revoke', ['user' => $demo, 'token' => $id]))->assertRedirect(route('users.show', $demo));

        expect(ApiToken::query()->findOrFail($id)->revoked_at)->not->toBeNull();
    });
});
