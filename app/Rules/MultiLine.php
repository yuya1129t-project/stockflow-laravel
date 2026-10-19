<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 複数行の文字列の項目（伝票の備考など）で、改行（LF）以外の制御文字を拒否する入力検証の規則（docs/spec.md §8）。
 * 改行は検証の前に LF にそろえておく（CRLF・CR → LF）。拒否する文字は SingleLine から改行（U+000A）を除いたもの
 */
class MultiLine implements ValidationRule
{
    public const string PATTERN = '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public const string MESSAGE = ':attribute にタブなどの制御文字は使えません（改行は使えます）';

    /** 制御文字（改行を除く）を含まない（不正な UTF-8 も通さない） */
    public static function passes(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 0;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! self::passes($value)) {
            $fail(self::MESSAGE);
        }
    }
}
