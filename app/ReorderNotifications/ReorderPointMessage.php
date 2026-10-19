<?php

namespace App\ReorderNotifications;

use App\Stock\StockChange;
use LogicException;

/**
 * 発注点割れの通知の本文（Slack Incoming Webhook の {text, blocks}）と、失敗の操作履歴の要約。純粋な関数（docs/spec.md §6-3）。
 * Next.js 版（stockflow-next の src/lib/notifications/reorder-point.ts）と同じ文面・同じ規則:
 *
 * - 1 回の操作につき 1 通。商品ごとの行は MAX_LISTED_PRODUCTS 件まで、超えた分は「ほか N 件」にまとめる
 *   （blocks は見出し 1＋商品 20＋まとめ 1 の最大 22 個。Slack の上限は 50 個）
 * - 利用者の入力（商品コード・商品名・単位）は & < > をエスケープし、verbatim: true の mrkdwn（blocks）にだけ入れる。
 *   verbatim で Slack の自動解析（@here・@channel などのメンション化、URL・チャンネル名のリンク化）を止める。
 *   明示的に組み立てた <URL|ラベル> のリンクは verbatim でも解釈される
 * - トップレベルの text（通知のプレビュー）は verbatim を指定できないので、件数ときっかけだけ（利用者の入力を入れない）
 * - 商品名は書式（*…* など）で囲まない（名前の * _ ~ で書式が崩れないように）
 * - 改行・制御文字は商品の入力検証（SingleLine。画面・CSV 取込で共通）が拒否している前提で、ここでは取り除かない
 *
 * @phpstan-type SlackMrkdwn array{type: 'mrkdwn', text: string, verbatim: true}
 * @phpstan-type SlackBlock array{type: 'section', text: SlackMrkdwn}|array{type: 'context', elements: list<SlackMrkdwn>}
 * @phpstan-type SlackMessage array{text: string, blocks: list<SlackBlock>}
 */
final class ReorderPointMessage
{
    /** 1 通に商品ごとの行を出す上限 */
    public const int MAX_LISTED_PRODUCTS = 20;

    /** 失敗の操作履歴に商品コードを並べる上限 */
    public const int MAX_SUMMARY_CODES = 10;

    /**
     * @param  list<StockChange>  $changes  「発注点以上 → 未満」に変わった有効な商品（1 件以上）
     * @param  string|null  $baseUrl  在庫画面へのリンクの基準（baseUrl() の結果）。null ならリンクを付けない
     * @return SlackMessage
     */
    public static function build(array $changes, ReorderPointTrigger $trigger, ?string $baseUrl): array
    {
        if ($changes === []) {
            throw new LogicException('通知する商品がありません');
        }
        $title = sprintf('発注点を下回った商品が %s 件あります（%s）', number_format(count($changes)), self::escape($trigger->label()));
        $listed = array_slice($changes, 0, self::MAX_LISTED_PRODUCTS);
        $rest = count($changes) - count($listed);

        $blocks = [['type' => 'section', 'text' => self::mrkdwn("*{$title}*")]];
        foreach ($listed as $change) {
            $p = $change->product;
            $label = self::escape($p->code).' '.self::escape($p->name);
            $unit = self::escape($p->unit);
            $link = $baseUrl !== null ? "<{$baseUrl}/stock/{$p->id}|{$label}>" : $label;
            $detail = sprintf(
                '現在庫 %s%s（発注点 %s%s・変更前 %s%s）',
                number_format($change->after),
                $unit,
                number_format($p->reorderPoint),
                $unit,
                number_format($change->before),
                $unit,
            );
            $blocks[] = ['type' => 'section', 'text' => self::mrkdwn("{$link}\n{$detail}")];
        }
        if ($rest > 0) {
            $blocks[] = ['type' => 'context', 'elements' => [self::mrkdwn(
                'ほか '.number_format($rest).' 件'.($baseUrl !== null ? "（<{$baseUrl}/stock?level=low|在庫一覧の発注点割れ>で確認してください）" : ''),
            )]];
        }

        // text は件数ときっかけだけ（受注番号はシステムが採番した値）
        return ['text' => $title, 'blocks' => $blocks];
    }

    /** Slack の mrkdwn で特別な意味を持つ & < > をエスケープする（& を先に） */
    public static function escape(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /**
     * 在庫画面へのリンクの基準（APP_URL）。http／https で、ホストがあり、認証情報・空白・< > | を含まないときだけ。
     * それ以外は null（リンクなしで送る）。末尾の "/" は除く
     */
    public static function baseUrl(mixed $appUrl): ?string
    {
        if (! is_string($appUrl) || trim($appUrl) === '') {
            return null;
        }
        $url = trim($appUrl);
        if (preg_match('/[\x00-\x20\x7F<>|]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return "{$scheme}://{$parts['host']}{$port}{$path}";
    }

    /**
     * 送れなかったときの操作履歴の要約（きっかけ・商品コード・原因の種類だけ。URL・応答本文・例外のメッセージは含めない）
     *
     * @param  list<StockChange>  $changes
     */
    public static function failureSummary(array $changes, ReorderPointTrigger $trigger, NotifyFailureReason $reason, ?int $httpStatus = null): string
    {
        $codes = array_map(fn (StockChange $c) => $c->product->code, $changes);
        $shown = implode('、', array_slice($codes, 0, self::MAX_SUMMARY_CODES));
        $more = count($codes) > self::MAX_SUMMARY_CODES ? ' ほか '.(count($codes) - self::MAX_SUMMARY_CODES).' 件' : '';
        $cause = $reason === NotifyFailureReason::HttpError && $httpStatus !== null
            ? "{$reason->label()}（{$httpStatus}）"
            : $reason->label();

        return "発注点割れの通知を送信できませんでした（{$trigger->label()}・商品 {$shown}{$more}）。原因: {$cause}";
    }

    /** @return SlackMrkdwn */
    private static function mrkdwn(string $text): array
    {
        return ['type' => 'mrkdwn', 'text' => $text, 'verbatim' => true];
    }
}
