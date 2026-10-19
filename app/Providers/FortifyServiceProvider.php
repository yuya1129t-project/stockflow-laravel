<?php

namespace App\Providers;

use App\Models\User;
use Database\Seeders\Demo\DemoData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認証（Fortify）の設定。使う機能はログイン・ログアウトだけ（config/fortify.php の features は空）。
 * Fortify のルートは登録させず、ログイン・ログアウトだけを routes/web.php で定義する
 * （features に関係なく登録されるパスワードの再確認のルートも使わないため）。
 * ユーザーの登録・変更は admin のユーザー管理（docs/spec.md §5）で行う。
 * 成功したログインの操作履歴は App\Listeners\RecordLogin（Login イベント）で記録する
 */
class FortifyServiceProvider extends ServiceProvider
{
    /** ログインの試行回数の上限（1 分あたり。メールアドレス＋IP ごと） */
    public const int LOGIN_ATTEMPTS_PER_MINUTE = 5;

    /** ログインの試行回数の上限（1 分あたり。IP ごと。メールアドレスを変えながらの試行を抑える） */
    public const int LOGIN_ATTEMPTS_PER_MINUTE_PER_IP = 20;

    /** 無効ユーザーがログインしようとしたときのメッセージ */
    public const string INACTIVE_MESSAGE = 'このアカウントは無効になっています。管理者に連絡してください';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * ログインの入力のメールアドレス（試行回数の制限のキーと照合の両方で使う値）。小文字にそろえる
     * （Fortify の CanonicalizeUsername と同じ）
     */
    public static function loginEmail(Request $request): string
    {
        return Str::lower($request->string(Fortify::username())->value());
    }

    /**
     * ログインの手順。パスワードを確かめたあとで無効ユーザーを拒否する
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = self::loginEmail($request);

            // ASCII の表示できる文字以外を含むメールアドレスは照合しない（通常の失敗にする）。users.email の照合順序
            // （utf8mb4_unicode_ci）はゼロ幅スペースなどを無視するため、そのまま照合すると、試行回数の制限のキーだけが
            // 別になる変形したアドレス（例: staff@exa\u{200B}mple.com）で同じユーザーにログインできてしまう
            if (preg_match('/\A[\x21-\x7E]+\z/', $email) !== 1) {
                return null;
            }

            $guard = Auth::guard('web');
            $credentials = [Fortify::username() => $email, 'password' => $request->string('password')->value()];

            // validate() は照合だけを行う（ログインは Fortify が行う）。ユーザーの有無で応答時間が変わらないよう、
            // SessionGuard は照合を一定時間（Timebox）で行う
            if (! $guard->validate($credentials)) {
                return null;
            }

            $user = $guard->getLastAttempted();
            if (! $user instanceof User) {
                return null;
            }

            // 正しいパスワードを知っている人にだけ「無効」と伝える（知らない人には通常の失敗と同じ応答）
            if (! $user->is_active) {
                throw ValidationException::withMessages([Fortify::username() => self::INACTIVE_MESSAGE]);
            }

            // 標準のログイン（SessionGuard::attempt）と同じく、ハッシュの設定（コストなど）が変わっていたら作り直す
            if (config('hashing.rehash_on_login', true)) {
                $guard->getProvider()->rehashPasswordIfRequired($user, $credentials);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        // デモ用アカウントは、シードの値（DemoData）をそのまま画面に出す（二重に持たない）
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
            'demoAccounts' => array_map(fn (array $user): array => [
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
            ], DemoData::USERS),
            'demoPassword' => DemoData::PASSWORD,
        ]));
    }

    /**
     * ログインの試行回数の制限（ルート POST /login の throttle:login）。成功・失敗を問わず数える。
     * 上限を超えたら、ログイン画面のメールアドレスの欄に日本語のメッセージを出す（JSON の要求には 429）
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = self::loginEmail($request);
            $tooManyAttempts = function (Request $request, array $headers): Response {
                $message = __('auth.throttle', ['seconds' => $headers['Retry-After'] ?? 60]);

                // JSON の要求には、待つ秒数を Retry-After などのヘッダーでも返す
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message, 'errors' => [Fortify::username() => [$message]]], 429, $headers);
                }

                throw ValidationException::withMessages([Fortify::username() => $message]);
            };

            return [
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)->by('email:'.$email.'|'.$request->ip())->response($tooManyAttempts),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE_PER_IP)->by('ip:'.$request->ip())->response($tooManyAttempts),
            ];
        });
    }
}
