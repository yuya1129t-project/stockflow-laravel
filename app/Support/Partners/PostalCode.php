<?php

namespace App\Support\Partners;

/**
 * 郵便番号の正規化と表示（docs/spec.md §3: 7 桁。ハイフン有無どちらも受け付け、保存はハイフンなし）。
 * 保存（PartnerRequest）と住所検索（AddressLookup）で同じ解釈をする
 */
final class PostalCode
{
    /**
     * 全角の数字を半角にし、ハイフンに見える文字（全角ハイフン・長音記号など）を "-" に揃える
     * （電話番号の正規化でも使う）
     */
    public static function normalizeDigitsAndHyphens(string $value): string
    {
        $value = strtr($value, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);

        return (string) preg_replace('/[－‐‑–—−ー─]/u', '-', $value);
    }

    /** 「ハイフンなしの 7 桁」にする（123-4567・1234567・全角も受け付ける）。形式が違えば null */
    public static function normalize(string $value): ?string
    {
        $normalized = self::normalizeDigitsAndHyphens(trim($value));

        return preg_match('/^(\d{3})-?(\d{4})$/', $normalized, $m) === 1 ? $m[1].$m[2] : null;
    }

    /** 保存した郵便番号（7 桁）を表示用に「123-4567」にする */
    public static function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_match('/^\d{7}$/', $value) === 1 ? substr($value, 0, 3).'-'.substr($value, 3) : $value;
    }
}
