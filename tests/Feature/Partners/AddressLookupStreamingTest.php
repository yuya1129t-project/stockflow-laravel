<?php

use App\Services\Partners\AddressLookup;
use App\Services\Partners\AddressLookupFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * 住所検索の受信の上限（64KB）とタイムアウト（3 秒）を、本物の通信で確かめる。
 * Http::fake は通信をしないので、受信の途中での打ち切りは確かめられない。そこでテスト用のローカルのサーバー
 * （tests/Support/raw-http-server.php。127.0.0.1 だけ）を立て、AddressLookup の呼び出し先をそこに向ける。
 * 外部（zipcloud）には出ない（許可するのは 127.0.0.1 のそのポートだけ）
 */
function startRawHttpServer(string $mode): array
{
    $process = new Process([PHP_BINARY, base_path('tests/Support/raw-http-server.php'), $mode]);
    $process->start();
    $process->waitUntil(fn (string $type, string $output) => str_contains($output, "\n"));
    $port = (int) trim($process->getOutput());
    test()->beforeApplicationDestroyed(fn () => $process->stop(0));

    $endpoint = "http://127.0.0.1:{$port}/api/search";
    Http::allowStrayRequests(["http://127.0.0.1:{$port}/*"]);

    return [new AddressLookup($endpoint), $process];
}

test('chunked の正しい応答は、通常どおり住所になる（受信の上限の仕組みが正常な応答を妨げない）', function () {
    [$lookup] = startRawHttpServer('json-chunked');

    $result = $lookup->lookup('031-0001');

    expect($result->ok())->toBeTrue();
    expect($result->addresses)->toBe(['青森県八戸市類家']);
});

test('64KB を超える本文は、受信の途中で打ち切って invalid_response にする', function (string $mode) {
    [$lookup] = startRawHttpServer($mode);
    Log::spy();

    $started = microtime(true);
    $result = $lookup->lookup('031-0001');
    $elapsed = microtime(true) - $started;

    // サーバーは 16KB ずつ 50ms おきに 3MB 以上を送る（最後まで受けると 10 秒かかり、3 秒のタイムアウトになる）。
    // 上限で打ち切れば、受信を始めてから 1 秒もかからない
    expect($result->failure)->toBe(AddressLookupFailure::InvalidResponse);
    expect($elapsed)->toBeLessThan(1.5);
    Log::shouldHaveReceived('warning')->once()->with('[address-lookup] zipcloud の呼び出しに失敗しました: invalid_response（応答が大きすぎます）');
})->with([
    'Content-Length も chunked もない応答' => ['large-nolength'],
    'chunked の応答' => ['large-chunked'],
    'Content-Length が上限を超える応答' => ['large-length'],
]);

test('接続から受信の終わりまでの 3 秒の制限は残る（少しずつ届く応答はタイムアウト）', function () {
    [$lookup] = startRawHttpServer('slow');

    $started = microtime(true);
    $result = $lookup->lookup('031-0001');
    $elapsed = microtime(true) - $started;

    expect($result->failure)->toBe(AddressLookupFailure::Timeout);
    expect($elapsed)->toBeGreaterThanOrEqual(2.9)->toBeLessThan(4.5);
});
