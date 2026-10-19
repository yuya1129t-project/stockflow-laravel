<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ロール変更。権限は Policy（UserPolicy::changeRole）で、検証より先に検査する */
class UserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && $this->user()?->can('changeRole', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['bail', 'required', Rule::enum(Role::class)],
        ];
    }

    public function role(): Role
    {
        /** @var array{role: string} $validated */
        $validated = $this->validated();

        return Role::from($validated['role']);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.required' => 'ロールを選択してください',
            'role.enum' => 'ロールを選択してください',
        ];
    }
}
