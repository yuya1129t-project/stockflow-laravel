<?php

use App\Enums\Role;
use App\Models\AuditLog;
use Tests\Browser\Support\Browser;

/**
 * ログイン・ログアウト（ブラウザ）。3 ロールそれぞれで、ログイン → ヘッダーにロール名 → ログアウト → ログイン画面、
 * ログアウトの後はセッションが残っていない（ダッシュボードを開くとログイン画面へ戻される）
 */
test('ロールごとにログインしてログアウトできる', function (Role $role) {
    $user = Browser::user($role);

    $page = Browser::login($user);
    Browser::assertText($page, 'h1', 'ダッシュボード');
    expect(AuditLog::query()->where('action', 'login')->where('user_id', $user->id)->count())->toBe(1);

    $page->click('@header-logout-button');
    $page->assertPathIs('/login');
    Browser::open($page, '/dashboard');
    $page->assertPathIs('/login');
})->with([
    'admin' => [Role::Admin],
    'staff' => [Role::Staff],
    'viewer' => [Role::Viewer],
]);

test('パスワードが違うとログインできず、日本語のエラーを表示する（ログイン画面のまま）', function () {
    $user = Browser::user(Role::Staff);

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'wrong-password')
        ->click('@login-button');
    $page->assertSee('メールアドレスまたはパスワードが正しくありません');
    $page->assertPathIs('/login');

    // ログインしていない（ダッシュボードはログイン画面へ戻される）。成功したログインの操作履歴もない
    Browser::open($page, '/dashboard');
    $page->assertPathIs('/login');
    expect(AuditLog::query()->where('action', 'login')->exists())->toBeFalse();
});
