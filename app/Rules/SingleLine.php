<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 1 行の文字列の項目で、改行・タブなどの制御文字を拒否する入力検証の規則（docs/spec.md §8）。
 * 取引先・商品など、1 行で入力するすべての項目で共通に使う（`new SingleLine` を rules に足す）。
 *
 * - 拒否する文字: C0 制御（U+0000〜U+001F。改行・タブを含む）・DEL（U+007F）・C1 制御（U+0080〜U+009F）・
 *   行区切り（U+2028・U+2029）・表示方向の制御（U+200E・U+200F・U+202A〜U+202E・U+2066〜U+2069）・BOM（U+FEFF）。
 *   表示方向の制御は見た目と実際の文字列をずらせるため、画面・CSV・操作履歴で紛らわしい（Next.js 版と同じ範囲）
 * - 判定は「禁止文字を 1 つでも含むか」を探す形にしている（`/^[^…]*$/` の形は `$` が末尾の改行の直前にも一致し、
 *   "abc\n" を通してしまうため使わない）。不正な UTF-8 も通さない（preg_match が false を返す）
 * - 文字列以外の値は判定しない（型は string 規則で検査する）
 * - エラー文: 「:attribute に改行・タブなどの制御文字は使えません」（:attribute は項目名）
 */
class SingleLine implements ValidationRule
{
    public const string PATTERN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public const string MESSAGE = ':attribute に改行・タブなどの制御文字は使えません';

    /** 制御文字を含まない（不正な UTF-8 も通さない） */
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
