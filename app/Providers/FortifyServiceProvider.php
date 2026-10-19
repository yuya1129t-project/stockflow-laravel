<?php

namespace App\Providers;

use App\Actions\Fortify\RecordLogin;
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
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

/**
 * 認証（Fortify）の設定。使う機能はログイン・ログアウトだけ（config/fortify.php の features は空）。
 * ユーザーの登録・変更は admin のユーザー管理（docs/spec.md §5）で行う
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
        //
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
     * ログインの手順。パスワードを確かめたあとで無効ユーザーを拒否し、成功したログインを操作履歴に記録する
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $guard = Auth::guard('web');
            $credentials = $request->only(Fortify::username(), 'password');

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

            return $user;
        });

        // Fortify の既定の手順（試行回数の制限はルートの throttle で行う。2 要素認証は使わない）に、操作履歴の記録を足す
        Fortify::authenticateThrough(fn (): array => [
            CanonicalizeUsername::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
            RecordLogin::class,
        ]);
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

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * ログインの試行回数の制限（ルート POST /login の throttle:login）。成功・失敗を問わず数える。
     * 上限を超えたら、ログイン画面のメールアドレスの欄に日本語のメッセージを出す（JSON の要求には 429）
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string(Fortify::username())->value()));
            $tooManyAttempts = function (Request $request, array $headers): never {
                $seconds = $headers['Retry-After'] ?? 60;

                throw ValidationException::withMessages([
                    Fortify::username() => __('auth.throttle', ['seconds' => $seconds]),
                ])->status(429);
            };

            return [
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)->by('email:'.$email.'|'.$request->ip())->response($tooManyAttempts),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE_PER_IP)->by('ip:'.$request->ip())->response($tooManyAttempts),
            ];
        });
    }
}
