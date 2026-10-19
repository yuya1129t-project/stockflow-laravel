<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 1 行で入力する項目に、改行・タブなどの制御文字と、表示方向の制御文字を含まない（Next.js 版 src/lib/validation/text.ts と同じ文字）。
 *
 * 拒否する文字: C0 制御（U+0000〜U+001F。改行・タブを含む）、DEL（U+007F）、C1 制御（U+0080〜U+009F）、
 * 行区切り（U+2028・U+2029）、表示方向の制御（U+200E・U+200F・U+202A〜U+202E・U+2066〜U+2069）、BOM（U+FEFF）。
 * 表示方向の制御は、見た目と実際の文字列をずらせるため画面・CSV で紛らわしい。UTF-8 として不正な文字列も拒否する
 */
final class SingleLine implements ValidationRule
{
    public const string FORBIDDEN = '\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}';

    public static function passes(string $value): bool
    {
        return preg_match('/^[^'.self::FORBIDDEN.']*$/u', $value) === 1;
    }

    /** 拒否する文字を取り除く（ファイル名など、拒否せずに表示用に整える値に使う） */
    public static function strip(string $value): string
    {
        return (string) preg_replace('/['.self::FORBIDDEN.']/u', '', $value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail(':attributeに改行・タブなどの制御文字は使えません');
        }
    }
}
