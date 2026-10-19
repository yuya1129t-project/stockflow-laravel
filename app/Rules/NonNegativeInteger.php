<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * min（既定 0）以上 max 以下の整数（半角数字だけの文字列で受け取る）。
 * 全角数字・桁区切りのカンマは検証の前に正規化しておく（App\Products\ProductValidation::normalizeDigits）
 */
final class NonNegativeInteger implements ValidationRule
{
    public function __construct(private readonly int $max, private readonly int $min = 0) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1 || (int) $value < $this->min) {
            $fail(":attributeは{$this->min}以上の整数で入力してください");

            return;
        }

        // 桁が多すぎる値は int に変換できないので、先頭の 0 を除いた桁数でも判定する
        $digits = ltrim($value, '0');
        if (strlen($digits) > strlen((string) $this->max) || (int) $value > $this->max) {
            $fail(':attributeは'.number_format($this->max).'以下で入力してください');
        }
    }
}
