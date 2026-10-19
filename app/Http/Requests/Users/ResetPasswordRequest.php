<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Users\UserValidation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * パスワード再設定（admin が新しいパスワードを設定する）。revoke_tokens（既定 true）なら API トークンもすべて失効させる。
 * 権限は Policy（UserPolicy::resetPassword）で、検証より先に検査する
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && $this->user()?->can('resetPassword', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => UserValidation::password(),
            'revoke_tokens' => ['sometimes', 'boolean'],
        ];
    }

    public function password(): string
    {
        /** @var array{password: string} $validated */
        $validated = $this->validated();

        return $validated['password'];
    }

    public function revokeTokens(): bool
    {
        return $this->boolean('revoke_tokens', true);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'revoke_tokens.boolean' => 'API トークンの失効の指定が正しくありません',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'password' => '新しいパスワード',
        ];
    }
}
