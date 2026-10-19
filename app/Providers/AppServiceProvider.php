<?php

namespace App\Providers;

use App\Support\Database\DestructiveOperationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
