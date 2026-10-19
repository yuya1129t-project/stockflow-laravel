<?php

namespace App\ReorderNotifications;

/** 発注点割れの通知を送れなかった原因の種類（操作履歴 notify_failed に記録する。URL・応答本文・例外のメッセージは残さない） */
enum NotifyFailureReason: string
{
    /** Webhook URL の設定が不正（https の URL でない・認証情報入り） */
    case InvalidUrl = 'invalid_url';
    /** 3 秒以内に応答がない */
    case Timeout = 'timeout';
    /** HTTP エラー（2xx 以外。リダイレクトの 3xx を含む。リダイレクトはたどらない） */
    case HttpError = 'http_error';
    /** 通信エラー（接続できない・名前を解決できない など） */
    case NetworkError = 'network_error';
    /** キュー（jobs テーブル）に投入できなかった */
    case QueueError = 'queue_error';

    public function label(): string
    {
        return match ($this) {
            self::InvalidUrl => 'Webhook URL の設定が不正（https の URL ではない）',
            self::Timeout => 'タイムアウト（3 秒）',
            self::HttpError => 'HTTP エラー',
            self::NetworkError => '通信エラー（接続できない など）',
            self::QueueError => 'キューに投入できなかった',
        };
    }
}
