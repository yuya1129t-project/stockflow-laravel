<?php

use App\Support\Database\DestructiveOperationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * --database で既定以外の接続を選んだ破壊コマンドも、実行の直前に止まること（AppServiceProvider の CommandStarting）。
 *
 * 拒否される接続 guard_probe は、実在する別の DB（testing_guard_probe。テスト用 DB とは別の名前なので拒否対象）を指す。
 * 中に目印のテーブルを作り、コマンドが削除処理まで進まなかったことを目印が残っていることで確かめる
 */
beforeEach(function () {
    $mysql = config('database.connections.mysql');
    config([
        'database.connections.guard_probe' => [...$mysql, 'database' => 'testing_guard_probe'],
        // DDL は暗黙にコミットされるので、テストのトランザクションとは別の接続（別セッション）で行う
        'database.connections.guard_admin' => $mysql,
    ]);
    DB::connection('guard_admin')->statement('CREATE DATABASE IF NOT EXISTS testing_guard_probe');
    DB::connection('guard_admin')->statement('CREATE TABLE IF NOT EXISTS testing_guard_probe.sentinel (id INT)');

    // テスト中の Laravel は Symfony のコマンドイベントを CommandStarting に中継しないので、本番と同じく中継させる
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);
});

afterEach(function () {
    DB::connection('guard_admin')->statement('DROP DATABASE IF EXISTS testing_guard_probe');
});

test('既定の接続が安全でも、--database で指定した接続が危なければ削除処理まで進まない', function (string $command) {
    expect(DestructiveOperationGuard::checkDefaultConnection()->ok)->toBeTrue()
        ->and(DestructiveOperationGuard::checkConnection('guard_probe')->ok)->toBeFalse();

    expect(fn () => Artisan::call($command, ['--database' => 'guard_probe', '--force' => true]))
        ->toThrow(RuntimeException::class, "{$command} を中止しました（接続 guard_probe）");

    $tables = DB::connection('guard_admin')->select('SHOW TABLES FROM testing_guard_probe');
    expect($tables)->toHaveCount(1);
})->with(DestructiveOperationGuard::DESTRUCTIVE_COMMANDS);

test('破壊コマンド以外は止めない', function () {
    expect(Artisan::call('migrate:status', ['--database' => 'mysql']))->toBe(0);
});
