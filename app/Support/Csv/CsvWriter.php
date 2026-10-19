<?php

namespace App\Support\Csv;

/**
 * CSV の生成（RFC 4180。docs/spec.md §7）。区切りはカンマ、改行は CRLF、末尾にも改行を付ける。
 *
 * - 文字列のセルは数式として解釈されないよう無害化する（CsvFormula）。整数のセルはそのまま出す
 * - カンマ・ダブルクォート・改行を含むか、前後に空白がある値はダブルクォートで囲む（中の " は ""）
 * - Excel で文字化けしないための BOM は、ファイル全体の先頭に呼び出し側で付ける（BOM）
 */
final class CsvWriter
{
    /** UTF-8 の BOM */
    public const string BOM = "\u{FEFF}";

    /**
     * @param  iterable<array<int, string|int|null>>  $rows
     */
    public static function write(iterable $rows): string
    {
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(self::formatCell(...), $row))."\r\n";
        }

        return $csv;
    }

    private static function formatCell(string|int|null $cell): string
    {
        if ($cell === null) {
            return '';
        }
        if (is_int($cell)) {
            return (string) $cell;
        }

        $text = CsvFormula::sanitize($cell);
        $needsQuotes = strpbrk($text, "\",\r\n") !== false || $text !== trim($text);

        return $needsQuotes ? '"'.str_replace('"', '""', $text).'"' : $text;
    }
}
