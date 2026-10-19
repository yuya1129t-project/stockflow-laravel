<?php

namespace App\Support\Csv;

/**
 * CSV インジェクション対策（表計算ソフトで数式として解釈されうるセルの無害化）。
 *
 * - 出力: 数式として解釈されうる文字（= + - @ タブ CR）で始まる文字列の先頭に ' を 1 つ付ける。
 *   元から ' が並んだ後に危険な文字が続く値（'-特価品 など）にも 1 つ付ける
 * - 取込: 1 つ以上の ' の後に危険な文字が続く値から ' を 1 つ外す
 *
 * 付ける側と外す側を対称にしているので、このアプリで出力した CSV をそのまま取り込むと元の値に戻る
 * （'-特価品 → ''-特価品 → '-特価品、'abc はどちらでも変わらない）。数値のセルには使わない
 */
final class CsvFormula
{
    /** 無害化が必要な値（' が 0 個以上並んだ後に危険な文字） */
    private const string FORMULA_PREFIX = '/^\'*[=+\-@\t\r]/';

    /** 無害化で付けた ' がある値（' が 1 個以上並んだ後に危険な文字） */
    private const string SANITIZED_PREFIX = '/^\'+[=+\-@\t\r]/';

    public static function sanitize(string $value): string
    {
        return preg_match(self::FORMULA_PREFIX, $value) === 1 ? "'".$value : $value;
    }

    public static function unsanitize(string $value): string
    {
        return preg_match(self::SANITIZED_PREFIX, $value) === 1 ? substr($value, 1) : $value;
    }
}
