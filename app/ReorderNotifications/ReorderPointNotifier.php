<?php

namespace App\ReorderNotifications;

use App\Jobs\SendReorderPointNotification;
use App\Stock\StockChange;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 発注点割れの通知の Job を投入する（docs/spec.md §6-3）。
 *
 * 呼ぶ場所: 棚卸調整（StockController::adjust）と受注の出荷（SalesOrderController::changeStatus）の、StockTransaction::run が
 * 戻った後（トランザクションの確定後。run のコールバックの中では呼ばない）。発注の入荷は在庫が増えるだけなので呼ばない。
 * Job は ShouldQueueAfterCommit なので、万一トランザクションの中で呼ばれても、確定するまでキューに入らない
 * （その場合 jobs への INSERT は確定のときに行われ、失敗しても下の catch には届かない。今の呼び出し位置では起きない）
 *
 * - 対象の商品がない・Webhook URL が未設定なら投入しない（デモは未設定）
 * - 投入（jobs テーブルへの INSERT）に失敗しても例外を投げない（業務処理は確定済み）。操作履歴に notify_failed を残す
 * - キューが sync のときは Bus::dispatch の中で handle() が動く。そこから出た例外は Job の failed() が記録済みなので、
 *   queue_error として二重に記録しない（ログだけ）
 */
final class ReorderPointNotifier
{
    /**
     * @param  list<StockChange>  $changes  StockRecordResult::$fellBelowReorderPoint（無効の商品は含まれない）
     * @param  int|null  $userId  操作したユーザー
     * @return bool 投入したか
     */
    public function notify(array $changes, ReorderPointTrigger $trigger, ?int $userId): bool
    {
        if ($changes === [] || WebhookUrl::isUnset(config('services.reorder_notification.webhook_url'))) {
            return false;
        }

        try {
            Bus::dispatch(new SendReorderPointNotification($changes, $trigger, $userId));
        } catch (Throwable $e) {
            if (self::runsSynchronously()) {
                Log::error('[reorder-notification] 通知の Job が失敗しました（sync）: '.$e::class);

                return true;
            }
            Log::error('[reorder-notification] 通知をキューに投入できませんでした: '.$e::class);
            SendReorderPointNotification::record($changes, $trigger, $userId, NotifyFailureReason::QueueError);

            return false;
        }

        return true;
    }

    /** 既定のキューの接続が sync か（Job は接続を指定していないので既定の接続に入る） */
    private static function runsSynchronously(): bool
    {
        $connection = config('queue.default');

        return is_string($connection) && config("queue.connections.{$connection}.driver") === 'sync';
    }
}
