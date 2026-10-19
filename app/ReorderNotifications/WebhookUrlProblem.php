<?php

namespace App\ReorderNotifications;

/** Webhook URL の設定を使えない理由 */
enum WebhookUrlProblem: string
{
    /** 未設定（送らない。失敗ではない） */
    case Unset = 'unset';
    /** URL として不正・認証情報入り */
    case Invalid = 'invalid';
    /** https ではない */
    case NotHttps = 'not_https';
}
