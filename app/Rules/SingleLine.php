<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 1 行の文字列の項目で、改行・タブなどの制御文字を拒否する（docs/spec.md §8）。
 *
 * C0 制御（U+0000〜U+001F）・DEL（U+007F）・C1 制御（U+0080〜U+009F）に加えて、行区切り（U+2028・U+2029）、
 * 表示方向の制御（U+200E・U+200F・U+202A〜U+202E・U+2066〜U+2069）、BOM（U+FEFF）を拒否する。
 * 表示方向の制御は見た目と実際の文字列をずらせるため、画面・CSV・操作履歴で紛らわしい（Next.js 版と同じ範囲）
 */
class SingleLine implements ValidationRule
{
    public const string PATTERN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /** 制御文字を含まない（不正な UTF-8 も通さない） */
    public static function passes(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 0;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! self::passes($value)) {
            $fail(':attribute に改行・タブなどの制御文字は使えません');
        }
    }
}
