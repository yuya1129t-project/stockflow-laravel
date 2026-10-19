<?php

namespace App\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * ダッシュボードの期間と前月比（純粋な関数。DB・フレームワークに依存しない）。docs/spec.md §1・§5-2
 *
 * - 「今月」「今日」は Asia/Tokyo の暦で決める（PHP の既定のタイムゾーン・config('app.timezone') によらない）
 * - 受注日・納期・入荷予定日は DB の date 型。比較の値は日付の文字列（YYYY-MM-DD）で持ち、そのまま SQL に渡す
 *   （date 列と日付の文字列の比較は日付どうしの比較になり、DB セッションの time_zone の影響を受けない）
 *
 * @phpstan-type MonthRange array{year: int, month: int, key: string, start: string, end: string}
 * @phpstan-type MonthOverMonth array{diff: int, rate: float|null, direction: 'up'|'down'|'flat', diff_label: string, rate_label: string}
 */
final class DashboardPeriod
{
    public const string TIMEZONE = 'Asia/Tokyo';

    /** 日時を JST の暦日（YYYY-MM-DD）にする */
    public static function jstDate(DateTimeInterface $now): string
    {
        return DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new DateTimeZone(self::TIMEZONE))
            ->format('Y-m-d');
    }

    /**
     * 暦月を n か月ずらす（n は負でもよい。年をまたぐ）
     *
     * @return array{year: int, month: int}
     */
    public static function addMonths(int $year, int $month, int $n): array
    {
        $index = $year * 12 + ($month - 1) + $n;
        $newYear = (int) floor($index / 12);

        return ['year' => $newYear, 'month' => $index - $newYear * 12 + 1];
    }

    /**
     * 暦月の範囲。start 以上 end 未満（どちらも YYYY-MM-DD。end は翌月 1 日。月末の日付を持たないので、うるう年も同じ扱い）
     *
     * @return MonthRange
     */
    public static function monthRange(int $year, int $month): array
    {
        $next = self::addMonths($year, $month, 1);

        return [
            'year' => $year,
            'month' => $month,
            'key' => self::monthKey($year, $month),
            'start' => self::monthKey($year, $month).'-01',
            'end' => self::monthKey($next['year'], $next['month']).'-01',
        ];
    }

    /**
     * JST の今月を最後にした直近 count か月（古い順）
     *
     * @return list<MonthRange>
     */
    public static function recentMonths(DateTimeInterface $now, int $count): array
    {
        $today = self::jstDate($now);
        $year = (int) substr($today, 0, 4);
        $month = (int) substr($today, 5, 2);

        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $ym = self::addMonths($year, $month, -$i);
            $months[] = self::monthRange($ym['year'], $ym['month']);
        }

        return $months;
    }

    /**
     * 前月比。diff = 今月 − 前月、rate = 増減率（%）。前月が 0 のときは計算できないので rate は null。
     * 画面に出す文字列もここで作る（画面は受け取った値を出すだけ）:
     * direction = 矢印の向き（up／down／flat。diff の符号）、diff_label = 符号付きの差額（+1,540・-250・0）、
     * rate_label = 増減率（小数 1 桁の +140.0%・-25.0%。丸めると 0 なら ±0%。前月 0 円なら「前月の受注なし」）
     *
     * @return MonthOverMonth
     */
    public static function monthOverMonth(int $current, int $previous): array
    {
        $diff = $current - $previous;
        // (diff × 100) / 前月 の順で割る（diff / 前月 × 100 より浮動小数の端数が出にくい）
        $rate = $previous === 0 ? null : (float) ($diff * 100) / $previous;

        return [
            'diff' => $diff,
            'rate' => $rate,
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            'diff_label' => ($diff > 0 ? '+' : '').number_format($diff),
            'rate_label' => $rate === null ? '前月の受注なし' : self::rateLabel($rate),
        ];
    }

    /** 増減率の表示（小数 1 桁）。丸めると 0 になる値は「±0%」（「+0.0%」「-0.0%」と出さない） */
    public static function rateLabel(float $rate): string
    {
        $rounded = round($rate, 1);
        if ($rounded == 0.0) {
            return '±0%';
        }

        return sprintf('%+.1f%%', $rounded);
    }

    /** "YYYY-MM"（集計結果と突き合わせるキー） */
    private static function monthKey(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }
}
