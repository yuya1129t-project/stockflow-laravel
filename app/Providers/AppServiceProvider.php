<?php

namespace App\Providers;

use App\Support\Database\DestructiveOperationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
