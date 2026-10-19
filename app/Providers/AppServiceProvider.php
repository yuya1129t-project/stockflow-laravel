<?php

namespace App\Providers;

use App\Auth\Permission;
use App\Models\User;
use App\Support\Database\DestructiveOperationGuard;
use App\Users\ApiTokenAuth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
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
        $this->configureDefaults();
        $this->configurePermissions();
        // 外部向け REST API の Bearer トークン（Sanctum）。Cookie のセッションでは通さない・失効と持ち主の有効を毎回確かめる
        ApiTokenAuth::register();
    }

    /**
     * 権限表（App\Auth\Permission）を Gate のアビリティとして登録する。
     * モデル単位の判定（例: 自分自身は無効化できない）が必要になったら Policy を足し、その中でもこの表を使う
     */
    protected function configurePermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            // 無効ユーザーはミドルウェア（EnsureUserIsActive）でログアウトさせるが、ここでも拒否する（二重の確認）
            Gate::define($permission->value, fn (User $user): bool => $user->is_active && $permission->allows($user->role));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // migrate:fresh・db:wipe など DB を消すコマンドは、本番と、シードと同じ判定で危ない接続先では実行しない
        // （ローカル以外の DB・テスト用 DB の取り違え。DestructiveOperationGuard）
        DB::prohibitDestructiveCommands(
            app()->isProduction() || ! DestructiveOperationGuard::checkDefaultConnection()->ok,
        );
        // --database で既定以外の接続を選んだときのため、実行の直前にも実際に使う接続を確かめる
        Event::listen(CommandStarting::class, DestructiveOperationGuard::ensureCommandIsSafe(...));

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
