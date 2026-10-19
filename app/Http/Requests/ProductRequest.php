<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Products\ProductValidation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * 商品の登録・編集の入力（docs/spec.md §3・§8）。規則とエラー文は CSV 取込と共通（ProductValidation）。
 * 商品コードは大文字に揃えてから一意を確かめる
 */
class ProductRequest extends FormRequest
{
    /** 登録は create、編集は update の Policy（ルートの can: に加えて二重に確認する） */
    public function authorize(): bool
    {
        $product = $this->editingProduct();

        return $product !== null
            ? $this->user()?->can('update', $product) === true
            : $this->user()?->can('create', Product::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(ProductValidation::normalize($this->all()));
    }

    /** @return array<string, list<string|ValidationRule|Unique>> */
    public function rules(): array
    {
        $rules = ProductValidation::rules();
        $rules['code'][] = Rule::unique('products', 'code')->ignore($this->editingProduct()?->id);

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProductValidation::messages();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ProductValidation::attributes();
    }

    /**
     * 検証済みの値（数値の項目は int）
     *
     * @return array{code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int}
     */
    public function productAttributes(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ProductValidation::toAttributes($validated);
    }

    private function editingProduct(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
