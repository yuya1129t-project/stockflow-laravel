<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Users\UserValidation;
use Illuminate\Foundation\Http\FormRequest;

/** API トークンの発行（名前を付ける）。権限は Policy（UserPolicy::manageTokens）で、検証より先に検査する */
class IssueApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && $this->user()?->can('manageTokens', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => UserValidation::tokenName(),
        ];
    }

    public function tokenName(): string
    {
        /** @var array{name: string} $validated */
        $validated = $this->validated();

        return $validated['name'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'トークンの名前',
        ];
    }
}
