<?php

use App\Jobs\SendReorderPointNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\ReorderNotifications\ReorderPointMessage;
use App\ReorderNotifications\ReorderPointTrigger;
use App\Stock\StockChange;
use App\Stock\StockProduct;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 発注点割れの通知の Job（送信・失敗の記録・再試行。docs/spec.md §6-3）。外部へは送らない（Http::fake。
 * 差し替えていない要求は tests/TestCase.php の preventStrayRequests で例外になる）
 */
const REORDER_TEST_WEBHOOK = 'https://hooks.slack.example.test/services/T000/B000/SECRET-TOKEN';

function reorderJob(?int $userId = null, ?ReorderPointTrigger $trigger = null): SendReorderPointNotification
{
    return new SendReorderPointNotification(
        [new StockChange(new StockProduct(7, 'P-0007', '封筒', '箱', 10, true, 12), 12, 3)],
        $trigger ?? ReorderPointTrigger::stockAdjust(7),
        $userId,
    );
}

/** 失敗の操作履歴に秘密（URL・トークン・応答本文・例外のメッセージ）が入っていないこと */
function expectNoSecretsInAuditLogs(): void
{
    foreach (AuditLog::query()->pluck('summary') as $summary) {
        expect($summary)->not->toContain('SECRET-TOKEN')->not->toContain('hooks.slack')->not->toContain('応答の本文')->not->toContain('Operation timed out');
    }
}

beforeEach(function () {
    config([
        'services.reorder_notification.webhook_url' => REORDER_TEST_WEBHOOK,
        'app.url' => 'https://stockflow.example.test',
    ]);
    $this->user = User::factory()->staff()->create();
});

test('Job の設定: 確定後に投入（ShouldQueueAfterCommit）・試行 3 回まで・想定外の例外とワーカーのタイムアウトは再試行しない', function () {
    $job = reorderJob();

    expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->tries)->toBe(3)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(SendReorderPointNotification::RETRY_DELAYS)->toBe([10, 60]);
});

test('成功: 設定した URL に JSON で 1 回 POST する（タイムアウト 3 秒・リダイレクトをたどらない）。操作履歴は残さない', function () {
    $sent = [];
    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = [$request, $options];

        return Http::response('ok', 200);
    });

    reorderJob($this->user->id, ReorderPointTrigger::salesOrderShipped(3, 'SO-20261019-001'))->handle();

    expect($sent)->toHaveCount(1);
    [$request, $options] = $sent[0];
    expect($request->url())->toBe(REORDER_TEST_WEBHOOK)
        ->and($request->method())->toBe('POST')
        ->and($request->header('Content-Type'))->toBe(['application/json'])
        ->and($request->data())->toBe(ReorderPointMessage::build(reorderJob()->changes, ReorderPointTrigger::salesOrderShipped(3, 'SO-20261019-001'), 'https://stockflow.example.test'))
        ->and($request->data()['text'])->toBe('発注点を下回った商品が 1 件あります（受注 SO-20261019-001 の出荷）')
        ->and($options['timeout'])->toBe(3)
        ->and($options['connect_timeout'])->toBe(3)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

test('HTTP エラー: 再試行せず、操作履歴に notify_failed（原因の種類と HTTP ステータスだけ。URL・応答本文は残さない）', function (int $status) {
    Http::fake(['*' => Http::response('応答の本文 SECRET-TOKEN', $status)]);
    Log::spy();
    $job = reorderJob($this->user->id)->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
    Http::assertSentCount(1);
    $log = AuditLog::query()->sole();
    expect($log->only(['user_id', 'action', 'target_type', 'target_id', 'summary']))->toBe([
        'user_id' => $this->user->id,
        'action' => 'notify_failed',
        'target_type' => 'product',
        'target_id' => '7',
        'summary' => "発注点割れの通知を送信できませんでした（棚卸調整・商品 P-0007）。原因: HTTP エラー（{$status}）",
    ]);
    expectNoSecretsInAuditLogs();
    Log::shouldHaveReceived('warning')->once()->with("[reorder-notification] 送信に失敗しました: http_error（HTTP {$status}）");
})->with([400, 403, 404, 500, 503]);

test('リダイレクト（3xx）はたどらず HTTP エラー（転送先には送らない）', function () {
    Http::fake([
        'hooks.slack.example.test/*' => Http::response('', 302, ['Location' => 'https://evil.example.test/collect']),
        'evil.example.test/*' => Http::response('ok', 200),
    ]);

    reorderJob($this->user->id)->handle();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.test'));
    expect(AuditLog::query()->sole()->summary)->toEndWith('原因: HTTP エラー（302）');
});

test('タイムアウト（cURL 28）: 再試行せず timeout を記録する（例外のメッセージ・URL はログにも残さない）', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.REORDER_TEST_WEBHOOK));
    Log::spy();
    $job = reorderJob($this->user->id)->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
    expect(AuditLog::query()->sole()->summary)->toBe('発注点割れの通知を送信できませんでした（棚卸調整・商品 P-0007）。原因: タイムアウト（3 秒）');
    expectNoSecretsInAuditLogs();
    Log::shouldHaveReceived('warning')->once()->with('[reorder-notification] 送信に失敗しました: timeout（cURL error 28）');
});

test('接続の前の失敗（cURL 6・7）と HTTP 429 は、届いていないので間をあけて再試行する（操作履歴はまだ残さない）', function (array|Closure $fake, int $attempt, int $delay) {
    Http::fake($fake);
    $job = reorderJob($this->user->id)->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    $job->handle();

    $job->assertReleased($delay);
    expect(AuditLog::query()->count())->toBe(0);
})->with([
    '接続できない（1 回目）' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.REORDER_TEST_WEBHOOK), 1, 10],
    '名前を解決できない（2 回目）' => [fn () => fn () => throw new ConnectionException('cURL error 6: Could not resolve host'), 2, 60],
    'HTTP 429（1 回目）' => [fn () => ['*' => Http::response('rate_limited', 429)], 1, 10],
]);

test('再試行しても最後（3 回目）まで失敗したら、notify_failed を 1 回記録して終える', function (array|Closure $fake, string $cause) {
    Http::fake($fake);
    $job = reorderJob($this->user->id)->withFakeQueueInteractions();
    $job->job->attempts = 3;

    $job->handle();

    $job->assertNotReleased();
    expect(AuditLog::query()->sole()->summary)->toEndWith("原因: {$cause}");
    expectNoSecretsInAuditLogs();
})->with([
    '接続できない' => [fn () => fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.REORDER_TEST_WEBHOOK), '通信エラー（接続できない など）'],
    'HTTP 429' => [fn () => ['*' => Http::response('rate_limited', 429)], 'HTTP エラー（429）'],
]);

test('ほかの通信エラー（cURL の番号なし・TLS など）は再試行せず network_error', function (string $message) {
    Http::fake(fn () => throw new ConnectionException($message));
    $job = reorderJob($this->user->id)->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
    expect(AuditLog::query()->sole()->summary)->toEndWith('原因: 通信エラー（接続できない など）');
    expectNoSecretsInAuditLogs();
})->with(['cURL error 35: SSL connect error for '.REORDER_TEST_WEBHOOK, 'Connection reset '.REORDER_TEST_WEBHOOK]);

test('Webhook URL が不正（http・認証情報入り・URL でない）なら送らずに invalid_url を記録する（URL は残さない）', function (string $url) {
    config(['services.reorder_notification.webhook_url' => $url]);
    Http::fake();
    Log::spy();

    reorderJob($this->user->id)->handle();

    Http::assertNothingSent();
    expect(AuditLog::query()->sole()->summary)->toEndWith('原因: Webhook URL の設定が不正（https の URL ではない）');
    expectNoSecretsInAuditLogs();
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => ! str_contains($message, 'SECRET-TOKEN') && ! str_contains($message, 'hooks.slack'));
})->with([
    'http://hooks.slack.example.test/services/T000/B000/SECRET-TOKEN',
    'https://user:SECRET-TOKEN@hooks.slack.example.test/services/x',
    'hooks.slack.example.test/SECRET-TOKEN',
]);

test('投入した後に Webhook URL の設定を外したら、何もしない（送らない・記録しない）', function () {
    config(['services.reorder_notification.webhook_url' => '']);
    Http::fake();

    reorderJob($this->user->id)->handle();

    Http::assertNothingSent();
    expect(AuditLog::query()->count())->toBe(0);
});

test('失敗の操作履歴を記録できなくても、例外を投げない（ログに例外の種類だけ残す）', function () {
    Http::fake(['*' => Http::response('', 500)]);
    AuditLog::creating(fn () => throw new RuntimeException('操作履歴の保存に失敗（テスト） '.REORDER_TEST_WEBHOOK));
    Log::spy();

    reorderJob($this->user->id)->handle();

    Log::shouldHaveReceived('error')->once()->with('[reorder-notification] 送信失敗の操作履歴を記録できませんでした: RuntimeException');
});

test('受注の出荷の通知の失敗は、受注を対象に記録する', function () {
    Http::fake(['*' => Http::response('', 500)]);

    reorderJob($this->user->id, ReorderPointTrigger::salesOrderShipped(42, 'SO-20261019-007'))->handle();

    expect(AuditLog::query()->sole()->only(['target_type', 'target_id', 'summary']))->toBe([
        'target_type' => 'sales_order',
        'target_id' => '42',
        'summary' => '発注点割れの通知を送信できませんでした（受注 SO-20261019-007 の出荷・商品 P-0007）。原因: HTTP エラー（500）',
    ]);
});
