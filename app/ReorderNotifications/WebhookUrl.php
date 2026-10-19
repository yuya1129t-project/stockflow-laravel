<?php

namespace App\ReorderNotifications;

/**
 * Webhook URL（環境変数 STOCKFLOW_SLACK_WEBHOOK_URL）の検証。純粋な関数。
 * 空・空白だけは未設定（送らない）。https 以外（http の平文など）・認証情報入り・URL として不正なものは使わない
 */
final class WebhookUrl
{
    /** 設定値が未設定（null・空・空白だけ）か */
    public static function isUnset(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    /** 使える URL（前後の空白を除いたもの）か、使えない理由 */
    public static function parse(mixed $value): string|WebhookUrlProblem
    {
        if (self::isUnset($value)) {
            return WebhookUrlProblem::Unset;
        }
        assert(is_string($value));
        $url = trim($value);
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return WebhookUrlProblem::Invalid;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return WebhookUrlProblem::Invalid;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return WebhookUrlProblem::NotHttps;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return WebhookUrlProblem::Invalid;
        }

        return $url;
    }
}
