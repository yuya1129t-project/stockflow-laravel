<?php

use App\Support\Database\DestructiveOperationGuard;

/** @param  array<string, mixed>  $overrides */
function guardConnection(array $overrides = []): array
{
    return [
        'driver' => 'mysql',
        'url' => null,
        'host' => 'mysql',
        'port' => '3306',
        'database' => 'stockflow_laravel',
        'username' => 'sail',
        'password' => 'password',
        'unix_socket' => '',
        ...$overrides,
    ];
}

test('ローカルの DB は許可する（Sail の mysql・localhost・127.0.0.1・::1）', function (string $host) {
    $result = DestructiveOperationGuard::check(guardConnection(['host' => $host]), 'local', null);

    expect($result->ok)->toBeTrue()
        ->and($result->reason)->toBeNull();
})->with(['mysql', 'localhost', '127.0.0.1', '::1', '[::1]', 'LOCALHOST']);

test('ローカル以外の DB は、許可の環境変数がなければ拒否する', function (?string $flag) {
    $result = DestructiveOperationGuard::check(guardConnection(['host' => 'db.example.com']), 'production', $flag);

    expect($result->ok)->toBeFalse()
        ->and($result->reason)->toContain('ローカル以外')
        ->and($result->reason)->toContain(DestructiveOperationGuard::ALLOW_ENV)
        ->and($result->target)->toBe('db.example.com:3306/stockflow_laravel');
})->with([null, '', '0', 'true', 'yes']);

test('ローカル以外の DB でも STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1 なら許可する', function () {
    $result = DestructiveOperationGuard::check(guardConnection(['host' => 'db.example.com']), 'production', '1');

    expect($result->ok)->toBeTrue();
});

test('ローカルに似た名前のホストはローカル扱いしない', function (string $host) {
    $result = DestructiveOperationGuard::check(guardConnection(['host' => $host]), 'local', null);

    expect($result->ok)->toBeFalse();
})->with(['mysql.example.com', 'localhost.example.com', '127.0.0.2', '10.0.0.5', '']);

test('DB_URL の接続先も Laravel と同じ方法で読んで判定する', function () {
    $remote = DestructiveOperationGuard::check(guardConnection(['url' => 'mysql://user:secret@db.example.com:3306/demo']), 'production', null);
    $local = DestructiveOperationGuard::check(guardConnection(['url' => 'mysql://sail:password@127.0.0.1:33069/stockflow_laravel', 'host' => 'db.example.com']), 'local', null);

    expect($remote->ok)->toBeFalse()
        ->and($remote->target)->toBe('db.example.com:3306/demo')
        ->and($remote->reason)->not->toContain('secret')
        ->and($local->ok)->toBeTrue();
});

test('読み書き分離の設定は、どれか 1 つでもローカル以外なら拒否する', function () {
    $connection = guardConnection([
        'read' => ['host' => ['127.0.0.1']],
        'write' => ['host' => ['db.example.com']],
    ]);

    expect(DestructiveOperationGuard::check($connection, 'local', null)->ok)->toBeFalse();
});

test('テストはテスト用 DB（testing）でだけ実行する', function () {
    $dev = DestructiveOperationGuard::check(guardConnection(), 'testing', null);
    $test = DestructiveOperationGuard::check(guardConnection(['database' => 'testing']), 'testing', null);

    expect($dev->ok)->toBeFalse()
        ->and($dev->reason)->toContain('テスト用 DB')
        ->and($test->ok)->toBeTrue();
});

test('テスト以外の環境からテスト用 DB（testing）は消さない。許可の環境変数でも外せない', function (string $environment) {
    $result = DestructiveOperationGuard::check(guardConnection(['database' => 'testing']), $environment, '1');

    expect($result->ok)->toBeFalse()
        ->and($result->reason)->toContain('テスト専用');
})->with(['local', 'production']);

test('MySQL 以外の接続や DB 名のない設定は拒否する', function () {
    expect(DestructiveOperationGuard::check(guardConnection(['driver' => 'sqlite']), 'local', null)->ok)->toBeFalse()
        ->and(DestructiveOperationGuard::check(guardConnection(['database' => '']), 'local', null)->ok)->toBeFalse();
});
