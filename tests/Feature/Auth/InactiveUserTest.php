<?php

use App\Auth\Permission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\AuditLog;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\Gate;

/** 無効ユーザー（is_active = false）はログインできない（docs/spec.md §3） */
test('無効ユーザーは正しいパスワードでもログインできない', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => FortifyServiceProvider::INACTIVE_MESSAGE]);
    expect(AuditLog::count())->toBe(0);
});

test('パスワードが違うときは、無効かどうかを明かさず通常の失敗と同じメッセージになる', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません']);
});

test('ログイン中に無効化されたら、次のリクエストでログアウトしてログイン画面へ移る', function () {
    $user = User::factory()->create();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();
    // 実際のリクエストでは毎回 DB からユーザーを読む。テストでは同じアプリのまま続けるので、読み込み済みのユーザーを捨てる
    $this->app['auth']->forgetGuards();

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', EnsureUserIsActive::MESSAGE);
    $this->assertGuest();

    // 有効に戻しても、ログイン済みには戻らない（ログアウトしている）
    $user->forceFill(['is_active' => true])->save();
    $this->app['auth']->forgetGuards();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('ログイン中に無効化されたら、JSON の要求には 401 を返してログアウトする', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->forceFill(['is_active' => false])->save();

    $this->getJson(route('dashboard'))
        ->assertUnauthorized()
        ->assertJson(['message' => EnsureUserIsActive::MESSAGE]);
    $this->assertGuest();
});

test('Gate も無効ユーザーには許可しない（ミドルウェアと二重の確認）', function () {
    $admin = User::factory()->admin()->inactive()->create();

    foreach (Permission::cases() as $permission) {
        expect(Gate::forUser($admin)->allows($permission))->toBeFalse();
    }
});
