<?php

/**
 * 住所検索の回数制限の同時要求のテスト用のワーカー（tests/Feature/Partners/ConcurrencyTest.php から別プロセスで起動する）。
 * アプリを起動し、AddressLookupRateLimiter::attempt を指定の時刻に指定の回数呼んで、結果（allowed／denied）を 1 行ずつ出す。
 * 接続先（テスト用 DB）とキャッシュのストア（database）は、起動するテストが環境変数で渡す。
 * 使い方: php address-lookup-rate-limit-worker.php <ユーザー ID> <開始時刻（UNIX 秒・小数）> <回数>
 */

use App\Services\Partners\AddressLookupRateLimiter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $startAt, $times] = $argv;

// 全員が同じ時刻に始める（同時の要求にする）
$wait = (float) $startAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$limiter = $app->make(AddressLookupRateLimiter::class);
for ($i = 0; $i < (int) $times; $i++) {
    echo ($limiter->attempt((int) $userId) === null ? 'allowed' : 'denied')."\n";
}
