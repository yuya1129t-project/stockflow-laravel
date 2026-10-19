<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Database\Seeders\Demo\DemoData;
use Inertia\Testing\AssertableInertia as Assert;

test('ログイン画面にデモ用アカウント（シードの値）が表示される', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('demoPassword', DemoData::PASSWORD)
        ->has('demoAccounts', 3)
        ->where('demoAccounts.0', ['email' => 'admin@example.com', 'name' => 'デモ管理者', 'role' => 'admin'])
        ->where('demoAccounts.1.email', 'staff@example.com')
        ->where('demoAccounts.2.email', 'viewer@example.com'),
    );
});

test('画面に出しているデモ用アカウントで、シードしたデータにログインできる', function () {
    $this->seed();

    foreach (DemoData::USERS as $demo) {
        $this->post(route('login.store'), ['email' => $demo['email'], 'password' => DemoData::PASSWORD])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::where('email', $demo['email'])->sole());
        expect(auth()->user()->role->value)->toBe($demo['role']);

        $this->post(route('logout'));
        $this->assertGuest();
    }
});

test('正しいメールアドレスとパスワードでログインでき、ダッシュボードへ移る', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('メールアドレスの大文字・小文字は区別しない', function () {
    $user = User::factory()->create(['email' => 'taro@example.com']);

    $this->post(route('login.store'), ['email' => 'Taro@Example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('パスワードが違うとログインできず、日本語のメッセージが出る', function () {
    $user = User::factory()->create();

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません']);
});

test('存在しないメールアドレスでも同じメッセージになる', function () {
    $response = $this->post(route('login.store'), [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません']);
});

test('入力がないと日本語の検証メッセージが出る', function () {
    $response = $this->post(route('login.store'), ['email' => '', 'password' => '']);

    $response->assertSessionHasErrors([
        'email' => 'メールアドレス を入力してください',
        'password' => 'パスワード を入力してください',
    ]);
});

test('ログイン前に開こうとした画面に、ログイン後に戻る', function () {
    $user = User::factory()->create();

    $this->get('/settings/appearance')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/settings/appearance');
});

test('ログアウトできる', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();
});

test('ログイン済みでログイン画面を開くとダッシュボードへ移る', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard', absolute: false));
});

describe('操作履歴（audit_logs）', function () {
    test('成功したログインを 1 件記録する', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        expect(AuditLog::count())->toBe(1);
        $log = AuditLog::sole();
        expect($log->user_id)->toBe($user->id)
            ->and($log->action)->toBe('login')
            ->and($log->target_type)->toBe('user')
            ->and($log->target_id)->toBe((string) $user->id)
            ->and($log->summary)->toBe('ログインしました')
            ->and($log->created_at)->not->toBeNull();
    });

    test('失敗したログインは記録しない', function () {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'password']);

        expect(AuditLog::count())->toBe(0);
    });
});

describe('ログインの試行回数制限', function () {
    test('同じメールアドレス・IP から 1 分に 5 回まで。6 回目は正しいパスワードでも日本語のメッセージで拒否する', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません']);
        }

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'ログインの試行回数が多すぎます。']);
        $response->assertInvalid(['email' => ' 秒後にもう一度お試しください']);
        expect(AuditLog::count())->toBe(0);
    });

    test('1 分たつと、またログインできる', function () {
        $user = User::factory()->create();

        foreach (range(1, FortifyServiceProvider::LOGIN_ATTEMPTS_PER_MINUTE) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->travel(61)->seconds();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    });

    test('別のメールアドレスは同じ IP からでも 5 回の枠が別に数えられる', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, FortifyServiceProvider::LOGIN_ATTEMPTS_PER_MINUTE) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($other);
    });

    test('メールアドレスを変えながらの試行も、同じ IP からは 1 分に 20 回まで', function () {
        foreach (range(1, 20) as $i) {
            $this->post(route('login.store'), ['email' => "nobody{$i}@example.com", 'password' => 'password'])
                ->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません']);
        }

        $user = User::factory()->create();
        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $response->assertInvalid(['email' => 'ログインの試行回数が多すぎます']);
    });

    test('JSON の要求には 429 を返す', function () {
        $user = User::factory()->create();

        foreach (range(1, FortifyServiceProvider::LOGIN_ATTEMPTS_PER_MINUTE) as $_) {
            $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests()
            ->assertJsonPath('errors.email.0', fn (string $message) => str_starts_with($message, 'ログインの試行回数が多すぎます'));
        $this->assertGuest();
    });
});
