<?php

namespace App\Orders;

use App\Products\ProductValidation;
use App\Rules\MultiLine;
use App\Rules\NonNegativeInteger;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * 受注・発注で共通の入力検証の部品（docs/spec.md §3・§8）。伝票ごとの Form Request（App\Http\Requests\PurchaseOrderRequest など）が
 * 組み合わせて使う。エラー文は Next.js 版 src/lib/orders/schema.ts と同じ。
 *
 * 明細（lines）:
 * - 商品 ID（選択）・数量（1 以上の整数）・単価（0 以上の整数）。文字列（フォームの値）でも数値（JSON の number。REST API 用）でも
 *   受け付け、全角数字・3 桁区切りのカンマも受け付ける。小数・負・範囲外（DB の INT を超える ID を含む）は検証エラー
 * - 同じ伝票に同じ商品の明細は 1 行だけ（重複は検証エラー。DB の一意制約でも守る）
 * - 行数の上限 MAX_LINES、合計の上限 OrderAmounts::MAX_TOTAL
 * - 明細 0 行は検証では通す（下書きは 0 行で保存できる。確定・発注済にするときに 1 行以上を求める）
 * - エラーの項目は lines.N.product_id など（N は 0 始まりの行番号）。明細全体のエラーは lines
 */
final class OrderValidation
{
    /** 1 伝票の明細の行数の上限 */
    public const int MAX_LINES = 100;

    /** 明細の数量の上限 */
    public const int MAX_QUANTITY = 9_999_999;

    /** 明細の単価の上限（商品の価格と同じ） */
    public const int MAX_UNIT_PRICE = ProductValidation::MAX_PRICE;

    /** 備考の文字数の上限 */
    public const int MAX_NOTE = 500;

    /** 受け付ける日付の範囲（入力の誤り・極端な値を弾く） */
    public const string MIN_DATE = '2000-01-01';

    public const string MAX_DATE = '2099-12-31';

    public const array LINE_LABELS = [
        'product_id' => '商品',
        'quantity' => '数量',
        'unit_price' => '単価',
    ];

    /**
     * 明細の正規化（検証の前）。各行は 3 項目だけを残し、数値（int）は文字列にし、前後の空白を除いて全角数字・桁区切りを半角にする。
     * 配列でない値はそのまま返して検証で拒否する
     */
    public static function normalizeLines(mixed $lines): mixed
    {
        if (! is_array($lines)) {
            return $lines;
        }

        return array_map(function (mixed $line) {
            if (! is_array($line)) {
                return $line;
            }
            $normalized = [];
            foreach (array_keys(self::LINE_LABELS) as $field) {
                $normalized[$field] = self::normalizeInteger($line[$field] ?? null);
            }

            return $normalized;
        }, $lines);
    }

    /** ID・数量・単価の正規化（int → 文字列、全角数字・桁区切り → 半角数字。不正な UTF-8 はそのまま） */
    public static function normalizeInteger(mixed $value): mixed
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
            return ProductValidation::normalizeDigits(mb_trim($value));
        }

        return $value;
    }

    /**
     * 備考の正規化（改行を LF にそろえ、前後の空白を除く。空なら null）
     */
    public static function normalizeNote(mixed $value): mixed
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $value = mb_trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $value === '' ? null : $value;
    }

    /** 日付の正規化（前後の空白を除く。空なら null） */
    public static function normalizeDate(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, list<string|ValidationRule|Closure>> */
    public static function lineRules(): array
    {
        return [
            'lines' => ['present', 'list', 'max:'.self::MAX_LINES],
            'lines.*' => ['bail', 'required', 'array'],
            'lines.*.product_id' => ['bail', 'required', self::idRule('商品を選択してください')],
            'lines.*.quantity' => ['bail', 'required', new NonNegativeInteger(self::MAX_QUANTITY, 1)],
            'lines.*.unit_price' => ['bail', 'required', new NonNegativeInteger(self::MAX_UNIT_PRICE)],
        ];
    }

    /** @return list<string|ValidationRule> 備考（任意。改行は使える。ほかの制御文字は不可） */
    public static function noteRules(): array
    {
        return ['nullable', 'string', 'max:'.self::MAX_NOTE, new MultiLine];
    }

    /** @return list<string> 日付（任意。YYYY-MM-DD の実在する日付） */
    public static function optionalDateRules(): array
    {
        return ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:'.self::MIN_DATE, 'before_or_equal:'.self::MAX_DATE];
    }

    /**
     * 選択式の ID（1 以上・9 桁まで＝ DB の INT の範囲内の整数の文字列）。範囲外の値で DB の例外にしない
     */
    public static function idRule(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/', $value) !== 1) {
                $fail($message);
            }
        };
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => ':attributeを入力してください',
            'string' => ':attributeの形式が正しくありません',
            'max' => ':attributeは:max文字以内で入力してください',
            'date_format' => ':attributeは正しい日付で入力してください（例 2026-10-19）',
            'after_or_equal' => ':attributeは正しい日付で入力してください（例 2026-10-19）',
            'before_or_equal' => ':attributeは正しい日付で入力してください（例 2026-10-19）',
            'lines.present' => '明細の形式が正しくありません',
            'lines.list' => '明細の形式が正しくありません',
            'lines.max' => '明細は'.self::MAX_LINES.'行までです',
            'lines.*.required' => '明細の形式が正しくありません',
            'lines.*.array' => '明細の形式が正しくありません',
            'lines.*.product_id.required' => '商品を選択してください',
        ];
    }

    /** @return array<string, string> */
    public static function lineAttributes(): array
    {
        return [
            'lines' => '明細',
            'lines.*.product_id' => self::LINE_LABELS['product_id'],
            'lines.*.quantity' => self::LINE_LABELS['quantity'],
            'lines.*.unit_price' => self::LINE_LABELS['unit_price'],
        ];
    }

    /**
     * 行をまたぐ検査（Form Request の after で呼ぶ）: 同じ商品の重複と合計の上限。各行の検証に通った値だけで判定する
     */
    public static function checkLines(Validator $validator): void
    {
        $lines = $validator->getData()['lines'] ?? null;
        if (! is_array($lines) || $validator->errors()->has('lines')) {
            return;
        }

        $firstRow = [];
        foreach ($lines as $index => $line) {
            $productId = is_array($line) ? ($line['product_id'] ?? null) : null;
            if (! is_string($productId) || $validator->errors()->has("lines.{$index}.product_id")) {
                continue;
            }
            if (array_key_exists($productId, $firstRow)) {
                $validator->errors()->add(
                    "lines.{$index}.product_id",
                    sprintf('同じ商品の明細が %d 行目にあります（数量は 1 行にまとめてください）', $firstRow[$productId] + 1),
                );

                continue;
            }
            $firstRow[$productId] = $index;
        }

        // 行ごとのエラーがあれば合計は求めない（数量・単価が整数になっていない）
        if (collect($validator->errors()->keys())->contains(fn (string $key) => str_starts_with($key, 'lines.'))) {
            return;
        }
        $amounts = OrderAmounts::calculate(self::toLines($lines));
        if ($amounts === null) {
            $validator->errors()->add('lines', '合計金額が上限（'.number_format(OrderAmounts::MAX_TOTAL).' 円）を超えています');
        }
    }

    /**
     * 検証済みの明細を int の配列にする
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<array{product_id: int, quantity: int, unit_price: int}>
     */
    public static function toLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $result[] = [
                'product_id' => self::toInt($line['product_id'] ?? null),
                'quantity' => self::toInt($line['quantity'] ?? null),
                'unit_price' => self::toInt($line['unit_price'] ?? null),
            ];
        }

        return $result;
    }

    private static function toInt(mixed $value): int
    {
        return is_string($value) || is_int($value) ? (int) $value : 0;
    }
}
