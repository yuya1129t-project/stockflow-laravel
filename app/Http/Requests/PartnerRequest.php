<?php

namespace App\Http\Requests;

use App\Enums\PartnerKind;
use App\Models\Partner;
use App\Rules\SingleLine;
use App\Support\Partners\PostalCode;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 取引先の登録・編集の入力（docs/spec.md §3「Partner」・§8）。
 *
 * - 前後の空白の除去と、空文字 → null は Laravel の既定のミドルウェア（TrimStrings・ConvertEmptyStringsToNull）が行う。
 *   郵便番号・住所・電話番号・メールアドレスは任意（空なら null で保存する）
 * - 取引先コードは大文字に揃える（c-0001 と C-0001 を別の取引先にしない）。一意
 * - 郵便番号はハイフン有無・全角を受け付け、ハイフンなしの 7 桁で保存する
 * - 電話番号は全角を半角にし、0 から始まる 10〜11 桁の数字（ハイフンは任意）
 * - 権限は Policy（PartnerPolicy）で、検証より先に検査する（ルートの can: と二重）
 */
class PartnerRequest extends FormRequest
{
    public const array MAX = [
        'code' => 20,
        'name' => 100,
        'address' => 200,
        'phone' => 20,
        'email' => 254,
    ];

    public function authorize(): bool
    {
        $partner = $this->route('partner');

        return $partner instanceof Partner
            ? $this->user()?->can('update', $partner) === true
            : $this->user()?->can('create', Partner::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $code = $this->input('code');
        if (is_string($code)) {
            $normalized['code'] = strtoupper($code);
        }

        $postalCode = $this->input('postal_code');
        if (is_string($postalCode)) {
            // 形式が違うときは元の値のまま（検証のエラーにする）
            $normalized['postal_code'] = PostalCode::normalize($postalCode) ?? $postalCode;
        }

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $normalized['phone'] = PostalCode::normalizeDigitsAndHyphens($phone);
        }

        $this->merge($normalized);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $partner = $this->route('partner');

        return [
            'code' => [
                'bail', 'required', 'string', 'max:'.self::MAX['code'], 'regex:/^[A-Z0-9][A-Z0-9_-]*\z/',
                Rule::unique('partners', 'code')->ignore($partner instanceof Partner ? $partner->id : null),
            ],
            'name' => ['bail', 'required', 'string', 'max:'.self::MAX['name'], new SingleLine],
            'kind' => ['bail', 'required', Rule::enum(PartnerKind::class)],
            'postal_code' => ['bail', 'nullable', 'string', 'regex:/^\d{7}\z/'],
            'address' => ['bail', 'nullable', 'string', 'max:'.self::MAX['address'], new SingleLine],
            'phone' => ['bail', 'nullable', 'string', 'max:'.self::MAX['phone'], $this->phoneRule()],
            'email' => ['bail', 'nullable', 'string', 'max:'.self::MAX['email'], 'email'],
        ];
    }

    /** 電話番号: 0 から始まり、数字は 10〜11 桁。ハイフンは 2 つまで（例 03-1234-5678） */
    private function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $digits = is_string($value) ? str_replace('-', '', $value) : '';
            if (
                ! is_string($value)
                || preg_match('/^0\d*(-\d+){0,2}\z/', $value) !== 1
                || strlen($digits) < 10
                || strlen($digits) > 11
            ) {
                $fail('電話番号は 0 から始まる 10〜11 桁の数字で入力してください（ハイフンは任意。例 03-1234-5678）');
            }
        };
    }

    /**
     * 検証済みの値（送られなかった任意の項目は null）
     *
     * @return array{code: string, name: string, kind: string, postal_code: ?string, address: ?string, phone: ?string, email: ?string}
     */
    public function partnerData(): array
    {
        /** @var array{code: string, name: string, kind: string, postal_code?: ?string, address?: ?string, phone?: ?string, email?: ?string} $validated */
        $validated = $this->validated();

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'postal_code' => $validated['postal_code'] ?? null,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => '取引先コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）',
            'code.unique' => '取引先コード「:input」はすでに登録されています',
            'kind.required' => '種別を選択してください',
            'kind.enum' => '種別を選択してください',
            'postal_code.regex' => '郵便番号は7桁の数字で入力してください（例 123-4567）',
            'email.email' => 'メールアドレスの形式が正しくありません（例 info@example.com）',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => '取引先コード',
            'name' => '取引先名',
            'kind' => '種別',
            'postal_code' => '郵便番号',
            'address' => '住所',
            'phone' => '電話番号',
            'email' => 'メールアドレス',
        ];
    }
}
