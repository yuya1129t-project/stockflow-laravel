<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use App\Users\UserValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ユーザーの登録（名前・メールアドレス・ロール・初期パスワード）。権限は Policy（UserPolicy::create）で、検証より先に検査する
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) === true;
    }

    protected function prepareForValidation(): void
    {
        // ログインはメールアドレスを小文字にして照合する。保存も小文字にそろえる
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => UserValidation::name(),
            'email' => [...UserValidation::email(), Rule::unique('users', 'email')],
            'role' => ['bail', 'required', Rule::enum(Role::class)],
            'password' => UserValidation::password(),
        ];
    }

    /** @return array{name: string, email: string, role: Role, password: string} */
    public function userData(): array
    {
        /** @var array{name: string, email: string, role: string, password: string} $validated */
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => Role::from($validated['role']),
            'password' => $validated['password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'メールアドレス「:input」はすでに登録されています',
            'email.regex' => 'メールアドレスは半角の英数字・記号で入力してください（例 user@example.com）',
            'email.email' => 'メールアドレスの形式が正しくありません（例 user@example.com）',
            'role.required' => 'ロールを選択してください',
            'role.enum' => 'ロールを選択してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => '名前',
            'email' => 'メールアドレス',
            'role' => 'ロール',
            'password' => '初期パスワード',
        ];
    }
}
