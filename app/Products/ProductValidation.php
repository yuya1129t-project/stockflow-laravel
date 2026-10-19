<?php

namespace App\Products;

use App\Rules\NonNegativeInteger;
use App\Rules\SingleLine;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 商品の入力の正規化と検証規則（docs/spec.md §3「Product」・§8）。画面の登録・編集（Form Request）と CSV 取込で同じものを使う。
 *
 * 入力はすべて文字列（フォームの値・CSV のセル）で受け取り、正規化 → 検証 → 数値への変換（toAttributes）の順に扱う。
 * 項目名・上限・エラー文は Next.js 版（src/lib/products/schema.ts）と同じ
 */
final class ProductValidation
{
    /** 項目名（画面のラベル・CSV の見出し・エラー文で共通） */
    public const array LABELS = [
        'code' => '商品コード',
        'name' => '商品名',
        'category' => 'カテゴリ',
        'unit' => '単位',
        'sales_price' => '売価',
        'purchase_price' => '仕入単価',
        'reorder_point' => '発注点',
    ];

    /** 数値の項目 */
    public const array INTEGER_FIELDS = ['sales_price', 'purchase_price', 'reorder_point'];

    /** 文字数の上限 */
    public const int MAX_CODE = 20;

    public const int MAX_NAME = 100;

    public const int MAX_CATEGORY = 50;

    public const int MAX_UNIT = 10;

    /** 価格の上限（1 億円未満） */
    public const int MAX_PRICE = 99_999_999;

    public const int MAX_REORDER_POINT = 9_999_999;

    /**
     * 検証の前の正規化（商品の 7 項目だけを返す）。
     * 前後の空白を除き、商品コードは大文字に揃え（p-0001 と P-0001 を別の商品にしない。CSV 取込の照合も大文字で行う）、
     * 数値の項目は全角数字・桁区切りのカンマを半角の数字だけにする。整数で渡された値は文字列にする（JSON の要求）
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $normalized = [];
        foreach (array_keys(self::LABELS) as $field) {
            $value = $input[$field] ?? null;
            if (is_int($value)) {
                $value = (string) $value;
            }
            // UTF-8 として不正な文字列はそのまま渡して検証で拒否する（SingleLine・NonNegativeInteger）
            if (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                $value = mb_trim($value);
                if ($field === 'code') {
                    $value = strtoupper($value);
                } elseif (in_array($field, self::INTEGER_FIELDS, true)) {
                    $value = self::normalizeDigits($value);
                }
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /** 全角数字・全角カンマを半角にし、3 桁ごとの桁区切り（1,200 など）のカンマを取り除く */
    public static function normalizeDigits(string $value): string
    {
        $halfWidth = strtr($value, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9', '，' => ',',
        ]);

        return preg_match('/^[0-9]{1,3}(,[0-9]{3})+$/', $halfWidth) === 1 ? str_replace(',', '', $halfWidth) : $halfWidth;
    }

    /**
     * 検証規則（商品コードの一意は含まない。登録・編集は Form Request で足し、CSV 取込は既存なら更新する）
     *
     * @return array<string, list<string|ValidationRule>>
     */
    public static function rules(): array
    {
        return [
            'code' => [
                'bail', 'required', 'string', 'max:'.self::MAX_CODE, new SingleLine,
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
            ],
            'name' => ['bail', 'required', 'string', 'max:'.self::MAX_NAME, new SingleLine],
            'category' => ['bail', 'required', 'string', 'max:'.self::MAX_CATEGORY, new SingleLine],
            'unit' => ['bail', 'required', 'string', 'max:'.self::MAX_UNIT, new SingleLine],
            'sales_price' => ['bail', 'required', new NonNegativeInteger(self::MAX_PRICE)],
            'purchase_price' => ['bail', 'required', new NonNegativeInteger(self::MAX_PRICE)],
            'reorder_point' => ['bail', 'required', new NonNegativeInteger(self::MAX_REORDER_POINT)],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => ':attributeを入力してください',
            'string' => ':attributeは文字列で入力してください',
            'max' => ':attributeは:max文字以内で入力してください',
            'code.regex' => '商品コードは半角英数字・ハイフン（-）・アンダースコア（_）で入力してください（先頭は英数字）',
            'code.unique' => '商品コード「:input」はすでに登録されています',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return self::LABELS;
    }

    /**
     * 検証済みの値を DB に保存する形にする（数値の項目を int に）
     *
     * @param  array<string, mixed>  $validated
     * @return array{code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int}
     */
    public static function toAttributes(array $validated): array
    {
        return [
            'code' => self::string($validated, 'code'),
            'name' => self::string($validated, 'name'),
            'category' => self::string($validated, 'category'),
            'unit' => self::string($validated, 'unit'),
            'sales_price' => (int) self::string($validated, 'sales_price'),
            'purchase_price' => (int) self::string($validated, 'purchase_price'),
            'reorder_point' => (int) self::string($validated, 'reorder_point'),
        ];
    }

    /** @param  array<string, mixed>  $values */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? '';

        return is_string($value) ? $value : '';
    }
}
