<?php

namespace App\Users;

use App\Rules\SingleLine;
use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * ユーザー管理・API トークンの入力検証の規則（docs/spec.md §3「User」・§8）。Form Request（App\Http\Requests\Users\*）で使う
 */
final class UserValidation
{
    public const array MAX = [
        'name' => 100,
        'email' => 254,
        'token_name' => 50,
    ];

    /**
     * パスワードの上限（バイト）。bcrypt は 72 バイトを超える部分を無視するため、それより長いパスワードは受け付けない
     * （受け付けると、73 バイト目以降が違うパスワードでもログインできてしまう）
     */
    public const int PASSWORD_MAX_BYTES = 72;

    /** @return list<mixed> 名前 */
    public static function name(): array
    {
        return ['bail', 'required', 'string', 'max:'.self::MAX['name'], new SingleLine];
    }

    /**
     * メールアドレス。ASCII の表示できる文字だけ（ログインの照合と同じ。FortifyServiceProvider::configureAuthentication）。
     * 小文字にそろえる処理は Form Request の prepareForValidation で行う
     *
     * @return list<mixed>
     */
    public static function email(): array
    {
        return ['bail', 'required', 'string', 'max:'.self::MAX['email'], 'regex:/\A[\x21-\x7E]+\z/', 'email:rfc,strict'];
    }

    /**
     * パスワード。前後の空白も含めてそのまま使う（TrimStrings は password を除外する）。
     * 文字の種類の規則は Laravel のパスワードの既定（AppServiceProvider の Password::defaults。本番は 12 文字以上・大小英字・数字・記号・
     * 漏洩していないこと、それ以外は 8 文字以上）。制御文字（改行・タブなど）は入力の誤りとして拒否する
     *
     * @return list<mixed>
     */
    public static function password(): array
    {
        return ['bail', 'required', 'string', new SingleLine, self::maxBytes(), Password::default()];
    }

    /** @return list<mixed> API トークンの名前 */
    public static function tokenName(): array
    {
        return ['bail', 'required', 'string', 'max:'.self::MAX['token_name'], new SingleLine];
    }

    private static function maxBytes(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::PASSWORD_MAX_BYTES) {
                $fail(':attribute は '.self::PASSWORD_MAX_BYTES.' バイト以内で入力してください（半角英数字・記号は 1 文字 1 バイト、全角は 1 文字 3 バイト）');
            }
        };
    }
}
