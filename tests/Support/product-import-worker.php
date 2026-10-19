<?php

/**
 * 並行取込のテスト（tests/Feature/Products/ProductImportConcurrencyTest.php）が別プロセスで起動する取込の 1 本。
 * 2 本を同時に動かし、DB の 2 接続で本当に同時に取込のトランザクションを走らせる。
 *
 * 使い方: php tests/Support/product-import-worker.php <payload.json>
 * payload: name・peer（相手の name）・barrierDir・userId・csv・fileName・injectDeadlock（任意）・ownLockUserId・peerLockUserId
 *
 * - 取込の最初の問い合わせ（code で既存の商品を引く SELECT）の直後で、相手が同じ地点に来るまで待つ（最大 5 秒）。
 *   ここから先（ロック・INSERT）を 2 本が同時に進める
 * - injectDeadlock: 1 回目の試行だけ、その地点で users の行を相手と逆の順にロックして、確実にデッドロックを起こす
 *   （取込のトランザクションの再試行が効いているかを確かめる）
 * - 結果を JSON で標準出力に出す: outcome（ok / validation / exception）・summary・message・attempts（試行回数）・synced
 */

use App\Models\User;
use App\Products\ProductImportParser;
use App\Products\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// テスト用 DB（testing）以外では動かさない（設定がキャッシュされていると環境変数が効かない）
if (! $app->environment('testing') || $app->configurationIsCached() || DB::connection()->getDatabaseName() !== 'testing') {
    fwrite(STDERR, "テスト用 DB ではないため中止しました\n");
    exit(2);
}

/** @var array{name: string, peer: string, barrierDir: string, userId: int, csv: string, fileName: string, injectDeadlock?: bool, ownLockUserId?: int, peerLockUserId?: int} $payload */
$payload = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

/** 相手が同じ地点に来るまで待つ（来なければ 5 秒で先に進む）。待ち合わせられたら true */
$barrier = function (string $point) use ($payload): bool {
    touch("{$payload['barrierDir']}/{$payload['name']}.{$point}");
    $deadline = microtime(true) + 5;
    while (! file_exists("{$payload['barrierDir']}/{$payload['peer']}.{$point}")) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(5_000);
    }

    return true;
};

// 試行回数 = 取込のトランザクションを始めた回数（デッドロックで再試行すると増える）
$attempts = 0;
Event::listen(TransactionBeginning::class, function () use (&$attempts) {
    $attempts++;
});

$synced = null;
$inBarrier = false;
DB::listen(function (QueryExecuted $query) use (&$synced, &$inBarrier, $barrier, $payload) {
    // 1 回目の試行の最初の問い合わせ（code で既存の商品を引く）の直後で待ち合わせる
    if ($synced !== null || $inBarrier || preg_match('/^select .* from `products` where `code` in /i', $query->sql) !== 1) {
        return;
    }

    $inBarrier = true;
    $synced = $barrier('read');
    if ($payload['injectDeadlock'] ?? false) {
        DB::select('select id from users where id = ? for update', [$payload['ownLockUserId']]);
        $barrier('locked');
        $inBarrier = false;
        // 相手は逆の順にロックしているので、どちらかがデッドロックで負ける
        DB::select('select id from users where id = ? for update', [$payload['peerLockUserId']]);
    }
    $inBarrier = false;
});

$result = ['attempts' => 0];
try {
    $parsed = ProductImportParser::parse($payload['csv']);
    if (! $parsed['ok']) {
        throw new RuntimeException('CSV が不正です: '.json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE));
    }
    $summary = app(ProductService::class)->import(User::query()->findOrFail($payload['userId']), $parsed['rows'], $payload['fileName']);
    $result = ['outcome' => 'ok', 'summary' => $summary];
} catch (ValidationException $e) {
    // 画面では取込画面に戻って日本語のエラーを出す（500 にはならない）
    $result = ['outcome' => 'validation', 'message' => $e->validator->errors()->first('file')];
} catch (Throwable $e) {
    // 画面では 500 になる
    $result = ['outcome' => 'exception', 'message' => $e::class.': '.$e->getMessage()];
}

echo json_encode([...$result, 'attempts' => $attempts, 'synced' => $synced], JSON_UNESCAPED_UNICODE);
