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
    $result = DestructiveOperationGuard::check(guardConnection(['host' => 'db.example.com']), 'local', $flag);

    expect($result->ok)->toBeFalse()
        ->and($result->reason)->toContain('ローカル以外')
        ->and($result->reason)->toContain(DestructiveOperationGuard::ALLOW_ENV)
        ->and($result->target)->toBe('db.example.com:3306/stockflow_laravel');
})->with([null, '', '0', 'true', 'yes']);

test('ローカル以外の DB でも STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1 なら許可する', function () {
    $result = DestructiveOperationGuard::check(guardConnection(['host' => 'db.example.com']), 'production', '1');

    expect($result->ok)->toBeTrue();
});

test('開発環境以外（production・staging など）は、ホストが mysql・localhost・ソケットでも許可の環境変数が必要', function (string $environment, array $overrides) {
    $connection = guardConnection($overrides);

    $refused = DestructiveOperationGuard::check($connection, $environment, null);
    expect($refused->ok)->toBeFalse()
        ->and($refused->reason)->toContain('ローカルの開発環境以外')
        ->and($refused->reason)->toContain(DestructiveOperationGuard::ALLOW_ENV)
        ->and(DestructiveOperationGuard::check($connection, $environment, '1')->ok)->toBeTrue();
})->with([
    'production・mysql' => ['production', ['host' => 'mysql']],
    'production・127.0.0.1' => ['production', ['host' => '127.0.0.1']],
    'production・ソケット' => ['production', ['host' => 'db.example.com', 'unix_socket' => '/var/run/mysqld/mysqld.sock']],
    'staging・localhost' => ['staging', ['host' => 'localhost']],
]);

test('ソケットで接続する設定はローカル扱い（ホストの値は使われない）', function () {
    $connection = guardConnection(['host' => 'db.example.com', 'unix_socket' => '/var/run/mysqld/mysqld.sock']);

    expect(DestructiveOperationGuard::check($connection, 'local', null)->ok)->toBeTrue();
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

test('読み書き分離の設定は、Laravel と同じ規則で実際に書き込む先を判定する', function (array $overrides, string $environment, bool $ok) {
    $connection = guardConnection(['database' => 'testing', ...$overrides]);

    expect(DestructiveOperationGuard::check($connection, $environment, null)->ok)->toBe($ok);
})->with([
    'write でリモートのホストに上書き' => [['read' => ['host' => ['127.0.0.1']], 'write' => ['host' => ['db.example.com']]], 'testing', false],
    'write で開発用 DB に上書き' => [['read' => ['host' => '127.0.0.1'], 'write' => ['host' => 'mysql', 'database' => 'stockflow_laravel']], 'testing', false],
    'write の候補の配列にリモートが混ざる' => [['read' => ['host' => '127.0.0.1'], 'write' => [['host' => 'mysql'], ['host' => 'db.example.com']]], 'testing', false],
    'write の候補の配列で DB 名を上書き' => [['read' => ['host' => '127.0.0.1'], 'write' => [['host' => 'mysql', 'database' => 'stockflow_laravel']]], 'testing', false],
    'write の候補がすべてローカルの testing' => [['read' => ['host' => 'db.example.com'], 'write' => [['host' => 'mysql'], ['host' => '127.0.0.1']]], 'testing', true],
    'read がなければ write は使われない（トップレベルで判定）' => [['write' => ['host' => 'db.example.com']], 'testing', true],
    'write がない' => [['read' => ['host' => '127.0.0.1']], 'testing', false],
    'write の候補が配列でない' => [['read' => ['host' => '127.0.0.1'], 'write' => ['mysql']], 'testing', false],
    'ホストの候補が文字列でない' => [['host' => ['mysql', ['db.example.com']]], 'testing', false],
]);

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

test('テストの実行は、環境が testing で実際の DB も testing のときだけ許可する（設定のキャッシュがあれば中止）', function (string $environment, bool $cached, string $actualDatabase, ?string $reason) {
    $result = DestructiveOperationGuard::checkTestRun(guardConnection(['database' => $actualDatabase]), $environment, $cached, $actualDatabase);

    expect($result->ok)->toBe($reason === null);
    if ($reason !== null) {
        expect($result->reason)->toContain($reason);
    }
})->with([
    'testing・testing' => ['testing', false, 'testing', null],
    '設定がキャッシュされている' => ['testing', true, 'testing', '設定がキャッシュ'],
    'APP_ENV=local と開発用 DB' => ['local', false, 'stockflow_laravel', 'testing ではありません'],
    'APP_ENV=local と testing' => ['local', false, 'testing', 'testing ではありません'],
    '環境は testing だが DB が開発用' => ['testing', false, 'stockflow_laravel', 'テスト用 DB'],
]);

test('テストの実行はリモートの DB を許可しない', function () {
    $result = DestructiveOperationGuard::checkTestRun(guardConnection(['host' => 'db.example.com', 'database' => 'testing']), 'testing', false, 'testing');

    expect($result->ok)->toBeFalse();
});
