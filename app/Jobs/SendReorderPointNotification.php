<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\ReorderNotifications\NotifyFailureReason;
use App\ReorderNotifications\ReorderPointMessage;
use App\ReorderNotifications\ReorderPointTrigger;
use App\ReorderNotifications\WebhookUrl;
use App\ReorderNotifications\WebhookUrlProblem;
use App\Stock\StockChange;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 発注点割れの通知を Slack Incoming Webhook 形式で送る Job（docs/spec.md §6-3）。投入は App\ReorderNotifications\ReorderPointNotifier から。
 *
 * - ShouldQueueAfterCommit: トランザクションの中で投入されても、確定するまでキューに入れない（取り消されたら捨てる）。
 *   呼び出し側は StockTransaction::run が戻った後（確定後）に投入しているので、通常はすぐに入る
 * - Webhook URL は実行のときに設定（services.reorder_notification.webhook_url）から読む（キューの jobs テーブルに URL を残さない）
 * - タイムアウト 3 秒（接続から受信の終わりまで）、リダイレクトはたどらない（3xx は HTTP エラー）。応答本文は使わない
 * - 送信の失敗では例外を投げない（業務処理は確定済みで、取り消さない）。送れなかったら操作履歴（action = notify_failed）に
 *   原因の種類だけを 1 回記録する。URL・応答本文・例外のメッセージは操作履歴にもログにも failed_jobs にも残さない
 *   （送信で出た例外は ConnectionException 以外もここで捕まえ、クラス名だけをログに出す）
 * - handle() の外で Job が失敗したとき（想定外の例外・ワーカーのタイムアウト・試行回数の上限超過）は、failed() が
 *   notify_failed（worker_error）を記録する。handle() は記録した後に必ず正常に戻るので、両方で記録することはない
 *
 * 再試行（同じ通知を何度も送らないため、届いていないことが確かなときだけ）:
 * - 再試行するのは、接続の前に失敗したとき（cURL 6: 名前を解決できない・7: 接続できない）と HTTP 429（Slack の流量制限。
 *   投稿されない）だけ。RETRY_DELAYS 秒あけて最大 TRIES 回まで。最後まで失敗したら notify_failed を記録する
 * - キューが sync（または Job を直接 handle() した）ときは積み直せないので、再試行せずにすぐ notify_failed を記録する
 * - タイムアウト・5xx などのほかの失敗は再試行しない（相手が受け取って投稿した可能性があり、送り直すと二重になる）
 * - 想定外の例外（本文の組み立てなど、プログラムの誤り）は maxExceptions = 1 で再試行せずに失敗にする。ワーカーの
 *   タイムアウト（timeout 秒）も failOnTimeout で再試行しない（どちらも failed() で記録する）
 */
final class SendReorderPointNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** 失敗の操作履歴の action */
    public const string FAILED_ACTION = 'notify_failed';

    /** 送信のタイムアウト（秒。spec §6-3） */
    public const int TIMEOUT_SECONDS = 3;

    /** 試行の回数の上限（初回を含む） */
    public const int TRIES = 3;

    /** 再試行までの待ち（秒。1 回目の失敗の後・2 回目の失敗の後） */
    public const array RETRY_DELAYS = [10, 60];

    /** 再試行してよい cURL のエラー番号（接続の前の失敗。6: 名前を解決できない、7: 接続できない） */
    private const array RETRYABLE_CURL_ERRORS = [6, 7];

    /** cURL のエラー番号: タイムアウト */
    private const int CURL_TIMEOUT = 28;

    public int $tries = self::TRIES;

    public int $maxExceptions = 1;

    /** ワーカーが Job を打ち切るまでの秒数（送信 3 秒＋操作履歴の記録に十分な長さ） */
    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /**
     * @param  list<StockChange>  $changes  「発注点以上 → 未満」に変わった有効な商品（1 件以上。1 回の操作の分をまとめて 1 通）
     * @param  int|null  $userId  操作したユーザー（失敗の操作履歴に記録する）
     */
    public function __construct(
        public readonly array $changes,
        public readonly ReorderPointTrigger $trigger,
        public readonly ?int $userId,
    ) {}

    public function handle(): void
    {
        $webhook = WebhookUrl::parse(config('services.reorder_notification.webhook_url'));
        if ($webhook === WebhookUrlProblem::Unset) {
            // 投入した後に設定を外した
            return;
        }
        if ($webhook instanceof WebhookUrlProblem) {
            Log::error("[reorder-notification] STOCKFLOW_SLACK_WEBHOOK_URL が https の URL ではないため送信しません（{$webhook->value}）");
            $this->recordFailure(NotifyFailureReason::InvalidUrl);

            return;
        }

        // 組み立ての例外（プログラムの誤り）を通信エラーとして記録しないよう、送信の try の外で組み立てる
        $message = ReorderPointMessage::build($this->changes, $this->trigger, ReorderPointMessage::baseUrl(config('app.url')));

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->asJson()
                ->post($webhook, $message);
        } catch (ConnectionException $e) {
            // メッセージは URL を含むので、cURL のエラー番号だけを使う
            $curlError = self::curlErrorNumber($e->getMessage());
            $detail = $curlError !== null ? "cURL error {$curlError}" : '通信エラー';
            if (in_array($curlError, self::RETRYABLE_CURL_ERRORS, true) && $this->retry($detail)) {
                return;
            }
            $reason = $curlError === self::CURL_TIMEOUT ? NotifyFailureReason::Timeout : NotifyFailureReason::NetworkError;
            Log::warning("[reorder-notification] 送信に失敗しました: {$reason->value}（{$detail}）");
            $this->recordFailure($reason);

            return;
        } catch (Throwable $e) {
            // ConnectionException 以外（想定外）。メッセージは URL を含みうるので使わない（クラス名だけ）。再試行しない
            Log::warning('[reorder-notification] 送信に失敗しました: network_error（'.$e::class.'）');
            $this->recordFailure(NotifyFailureReason::NetworkError);

            return;
        }

        // 応答本文は使わない（Slack は "ok" などの短い文字列を返す）
        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        if ($status === 429 && $this->retry("HTTP {$status}")) {
            return;
        }
        Log::warning("[reorder-notification] 送信に失敗しました: http_error（HTTP {$status}）");
        $this->recordFailure(NotifyFailureReason::HttpError, $status);
    }

    /**
     * handle() の外で Job が失敗した（想定外の例外・ワーカーのタイムアウト・試行回数の上限超過）。種類だけを記録する
     * （例外のメッセージは使わない）。handle() の中で記録した場合は handle() が正常に戻るので、ここは呼ばれない
     */
    public function failed(?Throwable $e): void
    {
        Log::error('[reorder-notification] 通知の Job が失敗しました: '.($e !== null ? $e::class : '（例外なし）'));
        $this->recordFailure(NotifyFailureReason::WorkerError);
    }

    /**
     * 失敗を操作履歴（notify_failed）に記録する。記録にも失敗したらログに残すだけ（例外は投げない）。
     * キューに投入できなかったときは ReorderPointNotifier からも呼ぶ
     *
     * @param  list<StockChange>  $changes
     */
    public static function record(array $changes, ReorderPointTrigger $trigger, ?int $userId, NotifyFailureReason $reason, ?int $httpStatus = null): void
    {
        try {
            AuditLog::create([
                'user_id' => $userId,
                'action' => self::FAILED_ACTION,
                'target_type' => $trigger->targetType(),
                'target_id' => (string) $trigger->targetId,
                'summary' => ReorderPointMessage::failureSummary($changes, $trigger, $reason, $httpStatus),
            ]);
        } catch (Throwable $e) {
            Log::error('[reorder-notification] 送信失敗の操作履歴を記録できませんでした: '.$e::class);
        }
    }

    /** 再試行の回数が残っていれば、間をあけてキューに戻す（戻したら true）。sync・直接の handle() では積み直せないので false */
    private function retry(string $detail): bool
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            return false;
        }
        $attempt = $this->attempts();
        if ($attempt >= $this->tries) {
            return false;
        }
        $delay = self::RETRY_DELAYS[$attempt - 1] ?? self::RETRY_DELAYS[array_key_last(self::RETRY_DELAYS)];
        Log::warning("[reorder-notification] 送信に失敗しました（{$detail}）。{$delay} 秒後に再試行します（{$attempt}／{$this->tries} 回目）");
        $this->release($delay);

        return true;
    }

    private function recordFailure(NotifyFailureReason $reason, ?int $httpStatus = null): void
    {
        self::record($this->changes, $this->trigger, $this->userId, $reason, $httpStatus);
    }

    /** 例外のメッセージから cURL のエラー番号だけを取り出す（メッセージ自体は URL を含むので記録しない） */
    private static function curlErrorNumber(string $message): ?int
    {
        return preg_match('/cURL error (\d{1,3})\b/', $message, $m) === 1 ? (int) $m[1] : null;
    }
}
