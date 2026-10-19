<?php

namespace App\Orders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;

/**
 * 伝票の競合の検出（楽観的ロック）。受注・発注で共通。
 *
 * 画面を開いた時点の updated_at（version）を編集・ステータス変更の操作と一緒に送り、サーバーで伝票の行をロックした後
 * （または条件付き更新の WHERE で）今の updated_at と比べる。違っていれば他の人が先に更新しているので、操作を行わずに
 * 競合として返す（見ていない内容のまま保存・確定しないため）。
 *
 * - 伝票の updated_at はマイクロ秒まで持つ（TIMESTAMP(6)）。秒の精度だと同じ 1 秒の中の更新を見分けられない
 * - 伝票を更新するときは、updated_at を next() の値にする（今の値より必ず大きくする。時計が戻っても同じ値にならない）
 * - Eloquent の save は updated_at を秒の精度で書くので、伝票の更新には使わない（クエリビルダーの update で next() を書く）
 */
final class OrderVersion
{
    /** version の形式（Asia/Tokyo の壁時計。DB に保存している値と同じ） */
    public const string FORMAT = 'Y-m-d H:i:s.u';

    private const string PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\z/';

    /** 画面に渡す version */
    public static function of(?CarbonInterface $updatedAt): string
    {
        return $updatedAt?->format(self::FORMAT) ?? '';
    }

    /**
     * 送られた version。形式が違う・実在しない日時（2026-02-30、99:99:99 など）なら null（競合として扱う）。
     * 解析した結果を同じ書式に戻して入力と完全に一致するかを確かめる（繰り上がった値を条件付き更新の WHERE に渡さない）
     */
    public static function parse(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value);

        return $parsed !== false && $parsed->format(self::FORMAT) === $value ? $value : null;
    }

    /** 今の updated_at が、画面を開いた時点のものと同じか */
    public static function matches(?CarbonInterface $current, string $expected): bool
    {
        return $current !== null && self::of($current) === $expected;
    }

    /**
     * 更新後の updated_at（DB に書く文字列）。今の値より必ず大きい（今の時刻か、今の値 + 1 マイクロ秒の大きい方）
     *
     * @param  CarbonInterface|string|null  $current  今の updated_at（文字列は FORMAT の形式）
     */
    public static function next(CarbonInterface|string|null $current): string
    {
        $now = CarbonImmutable::now();
        if ($current === null) {
            return $now->format(self::FORMAT);
        }
        $base = is_string($current) ? CarbonImmutable::createFromFormat(self::FORMAT, $current) : CarbonImmutable::instance($current);
        if (! $base instanceof CarbonImmutable) {
            return $now->format(self::FORMAT);
        }
        $after = $base->addMicrosecond();

        return ($now->greaterThan($after) ? $now : $after)->format(self::FORMAT);
    }

    /** 競合のメッセージ（documentLabel は「発注」「受注」、notDone は行わなかった操作。例「保存していません」） */
    public static function conflictMessage(string $documentLabel, string $notDone): string
    {
        return "他の人がこの{$documentLabel}を更新しました。{$notDone}。画面を開き直して最新の内容を確認してください";
    }
}
