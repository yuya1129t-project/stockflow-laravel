<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Products\ProductValidation;
use App\Rules\NonNegativeInteger;
use App\Rules\SingleLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 棚卸調整の入力（docs/spec.md §4・§8。項目名・上限・エラー文は Next.js 版 src/lib/stock/schema.ts と同じ）。
 * 実数は 0 以上の整数（全角数字・桁区切りのカンマも受け付ける）、理由は必須
 */
class StockAdjustRequest extends FormRequest
{
    /** 実数の上限 */
    public const int MAX_ACTUAL_QUANTITY = 9_999_999;

    /** 理由の文字数の上限 */
    public const int MAX_REASON = 200;

    /** ルートの can:stock.adjust に加えて Policy でも確認する */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product && $this->user()?->can('adjustStock', $product) === true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['actual_quantity', 'reason'] as $field) {
            $value = $this->input($field);
            if (is_int($value)) {
                $value = (string) $value;
            }
            // UTF-8 として不正な文字列はそのまま渡して検証で拒否する
            if (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                $value = mb_trim($value);
                if ($field === 'actual_quantity') {
                    $value = ProductValidation::normalizeDigits($value);
                }
            }
            $normalized[$field] = $value;
        }
        $this->replace($normalized);
    }

    /** @return array<string, list<string|ValidationRule>> */
    public function rules(): array
    {
        return [
            'actual_quantity' => ['bail', 'required', new NonNegativeInteger(self::MAX_ACTUAL_QUANTITY)],
            'reason' => ['bail', 'required', 'string', 'max:'.self::MAX_REASON, new SingleLine],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => ':attributeを入力してください',
            'string' => ':attributeは文字列で入力してください',
            'max' => ':attributeは:max文字以内で入力してください',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['actual_quantity' => '実数', 'reason' => '理由'];
    }

    public function actualQuantity(): int
    {
        return (int) $this->string('actual_quantity')->toString();
    }

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
