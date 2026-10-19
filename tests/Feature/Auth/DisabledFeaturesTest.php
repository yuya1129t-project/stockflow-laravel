<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * 公開デモでデモアカウントを壊されないよう、スターターキットの機能のうち spec にないものは止めている
 * （config/fortify.php の features は空、Fortify のルートは登録させずログイン・ログアウトだけを routes/web.php に定義、
 * routes/settings.php は外観だけ）。ここで止めたことを固定する（Fortify の機能やルートを戻すと、該当のルートができてテストが落ちる）
 */
test('公開のユーザー登録はできない（GET・POST /register は 404 で、ユーザーは作られない）', function () {
    $this->get('/register')->assertNotFound();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    expect(Route::has('register'))->toBeFalse();
});

/** 止めた機能のルート（メソッド, URI） */
dataset('止めた機能のルート', [
    'ユーザー登録の画面' => ['GET', '/register'],
    'ユーザー登録' => ['POST', '/register'],
    'パスワードのリセット（申請画面）' => ['GET', '/forgot-password'],
    'パスワードのリセット（申請）' => ['POST', '/forgot-password'],
    'パスワードのリセット（再設定画面）' => ['GET', '/reset-password/token'],
    'パスワードのリセット（再設定）' => ['POST', '/reset-password'],
    'メールアドレスの確認（案内）' => ['GET', '/email/verify'],
    'メールアドレスの確認' => ['GET', '/email/verify/1/hash'],
    'メールアドレスの確認（再送）' => ['POST', '/email/verification-notification'],
    'プロフィールの画面' => ['GET', '/settings/profile'],
    '名前・メールアドレスの変更' => ['PATCH', '/settings/profile'],
    'アカウントの削除' => ['DELETE', '/settings/profile'],
    'セキュリティの画面' => ['GET', '/settings/security'],
    'パスワードの変更' => ['PUT', '/settings/password'],
    'プロフィールの変更（Fortify）' => ['PUT', '/user/profile-information'],
    'パスワードの変更（Fortify）' => ['PUT', '/user/password'],
    '2 要素認証の有効化' => ['POST', '/user/two-factor-authentication'],
    '2 要素認証の無効化' => ['DELETE', '/user/two-factor-authentication'],
    '2 要素認証の確定' => ['POST', '/user/confirmed-two-factor-authentication'],
    '2 要素認証の QR コード' => ['GET', '/user/two-factor-qr-code'],
    '2 要素認証の復旧コード' => ['GET', '/user/two-factor-recovery-codes'],
    '2 要素認証のログイン画面' => ['GET', '/two-factor-challenge'],
    '2 要素認証のログイン' => ['POST', '/two-factor-challenge'],
    'パスキーの登録' => ['POST', '/user/passkeys'],
    'パスキーの登録オプション' => ['GET', '/user/passkeys/options'],
    'パスキーでのログイン' => ['POST', '/passkeys/login'],
    'パスキーのログインオプション' => ['GET', '/passkeys/login/options'],
    'パスキーの案内' => ['GET', '/.well-known/passkey-endpoints'],
    'パスワードの再確認の画面' => ['GET', '/user/confirm-password'],
    'パスワードの再確認' => ['POST', '/user/confirm-password'],
    'パスワードの再確認の状態' => ['GET', '/user/confirmed-password-status'],
]);

test('未ログインでは 404', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with('止めた機能のルート');

test('ログイン中でも 404 で、アカウントは変わらない', function (string $method, string $uri) {
    $user = User::factory()->create(['name' => '元の名前', 'email' => 'original@example.com']);

    $this->actingAs($user)->call($method, $uri, [
        'name' => '変えた名前',
        'email' => 'changed@example.com',
        'current_password' => 'password',
        'password' => 'changed-password-1234',
        'password_confirmation' => 'changed-password-1234',
    ])->assertNotFound();

    $user->refresh();
    expect($user->name)->toBe('元の名前')
        ->and($user->email)->toBe('original@example.com')
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull();
})->with('止めた機能のルート');

test('メールアドレスが未確認でもダッシュボードを開ける（メールアドレスの確認は求めない）', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
