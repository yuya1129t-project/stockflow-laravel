<?php

namespace App\Api\V1;

use App\Orders\OrderValidation;
use App\Products\ProductValidation;
use stdClass;

/**
 * 受注の登録（POST /api/v1/sales-orders）の本文の検査。docs/openapi.json の SalesOrderCreate・SalesOrderCreateLine と同じ規則で、
 * 型・必須・余分な項目・文字数・件数・整数の範囲・日付の形だけを見る（Next.js 版 src/lib/api/v1/schemas.ts の salesOrderCreateSchema）。
 * 業務の検証（顧客の種別・有効、商品の有効・重複、納期と受注日、備考の制御文字、合計の上限）は画面と同じ受注の検証と
 * サービスで行う（SalesOrderApi）。
 *
 * 上限の値と openapi.json の値が一致することは tests/Unit/Api/SalesOrderCreateBodyTest.php で確かめる。
 * エラー文は Next.js 版と同じ（整数の型違いは「整数で」）
 *
 * @phpstan-import-type FieldError from ApiResponse
 *
 * @phpstan-type CreateLine array{productCode: string, quantity: int}
 * @phpstan-type CreateInput array{customerCode: string, dueDate: string|null, note: string|null, lines: list<CreateLine>}
 */
final class SalesOrderCreateBody
{
    /** 顧客コード・商品コードの文字数の上限（取引先・商品のコードの上限と同じ） */
    public const int MAX_CODE = ProductValidation::MAX_CODE;

    private const array FIELDS = ['customerCode', 'dueDate', 'note', 'lines'];

    private const array LINE_FIELDS = ['productCode', 'quantity'];

    /** @var list<FieldError> */
    private array $errors = [];

    /**
     * 本文を検査する。問題がなければ値、あれば項目ごとのエラー
     *
     * @return array{input: CreateInput, errors: null}|array{input: null, errors: non-empty-list<FieldError>}
     */
    public static function parse(mixed $body): array
    {
        $parser = new self;
        $input = $parser->root($body);

        return $input === null || $parser->errors !== []
            ? ['input' => null, 'errors' => $parser->errors !== [] ? $parser->errors : [['pointer' => '#', 'detail' => 'オブジェクトで指定してください']]]
            : ['input' => $input, 'errors' => null];
    }

    /** @return CreateInput|null */
    private function root(mixed $body): ?array
    {
        if (! $body instanceof stdClass) {
            $this->error([], 'オブジェクトで指定してください');

            return null;
        }
        $fields = get_object_vars($body);

        $customerCode = $this->requiredString($fields, 'customerCode', ['customerCode']);
        $dueDate = $this->optionalDate($fields, 'dueDate');
        $note = $this->optionalNote($fields, 'note');
        $lines = $this->lines($fields);
        $this->extraFields($fields, self::FIELDS, []);

        if ($customerCode === null || $lines === null) {
            return null;
        }

        return ['customerCode' => $customerCode, 'dueDate' => $dueDate, 'note' => $note, 'lines' => $lines];
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return list<CreateLine>|null
     */
    private function lines(array $fields): ?array
    {
        if (! array_key_exists('lines', $fields)) {
            $this->error(['lines'], '必須の項目です');

            return null;
        }
        $lines = $fields['lines'];
        if (! is_array($lines) || ! array_is_list($lines)) {
            $this->error(['lines'], '配列で指定してください');

            return null;
        }
        $valid = true;
        if (count($lines) < 1) {
            $this->error(['lines'], '1 件以上で指定してください');
            $valid = false;
        } elseif (count($lines) > OrderValidation::MAX_LINES) {
            $this->error(['lines'], OrderValidation::MAX_LINES.' 件以内で指定してください');
            $valid = false;
        }

        $result = [];
        foreach ($lines as $index => $line) {
            if (! $line instanceof stdClass) {
                $this->error(['lines', $index], 'オブジェクトで指定してください');
                $valid = false;

                continue;
            }
            $lineFields = get_object_vars($line);
            $productCode = $this->requiredString($lineFields, 'productCode', ['lines', $index, 'productCode']);
            $quantity = $this->requiredInteger($lineFields, 'quantity', ['lines', $index, 'quantity'], 1, OrderValidation::MAX_QUANTITY);
            $this->extraFields($lineFields, self::LINE_FIELDS, ['lines', $index]);
            if ($productCode === null || $quantity === null) {
                $valid = false;

                continue;
            }
            $result[] = ['productCode' => $productCode, 'quantity' => $quantity];
        }

        return $valid ? $result : null;
    }

    /**
     * 文字列（1〜MAX_CODE 文字。必須）
     *
     * @param  array<array-key, mixed>  $fields
     * @param  list<string|int>  $path
     */
    private function requiredString(array $fields, string $name, array $path): ?string
    {
        if (! array_key_exists($name, $fields)) {
            $this->error($path, '必須の項目です');

            return null;
        }
        $value = $fields[$name];
        if (! is_string($value)) {
            $this->error($path, '文字列で指定してください');

            return null;
        }
        if (mb_strlen($value) < 1) {
            $this->error($path, '1 文字以上で指定してください');

            return null;
        }
        if (mb_strlen($value) > self::MAX_CODE) {
            $this->error($path, self::MAX_CODE.' 文字以内で指定してください');

            return null;
        }

        return $value;
    }

    /**
     * 整数（JSON の数値で、小数部のないもの。min〜max。必須）
     *
     * @param  array<array-key, mixed>  $fields
     * @param  list<string|int>  $path
     */
    private function requiredInteger(array $fields, string $name, array $path, int $min, int $max): ?int
    {
        if (! array_key_exists($name, $fields)) {
            $this->error($path, '必須の項目です');

            return null;
        }
        $value = $fields[$name];
        // JSON の 1.0 も整数（JSON Schema の integer）。PHP の整数を超える巨大な整数は float で届くので、整数のまま範囲外として拒否する
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            if (abs($value) >= 1e15) {
                $this->error($path, $value > 0 ? number_format($max).' 以下で指定してください' : "{$min} 以上で指定してください");

                return null;
            }
            $value = (int) $value;
        }
        if (! is_int($value)) {
            $this->error($path, '整数で指定してください');

            return null;
        }
        if ($value < $min) {
            $this->error($path, "{$min} 以上で指定してください");

            return null;
        }
        if ($value > $max) {
            $this->error($path, number_format($max).' 以下で指定してください');

            return null;
        }

        return $value;
    }

    /**
     * 日付（YYYY-MM-DD の実在する日付で 2000〜2099 年。省略・null は未設定）
     *
     * @param  array<array-key, mixed>  $fields
     */
    private function optionalDate(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $this->error([$name], '文字列で指定してください');

            return null;
        }
        if (! ApiQuery::isDate($value)) {
            $this->error([$name], ApiQuery::dateMessage());

            return null;
        }

        return $value;
    }

    /**
     * 備考（OrderValidation::MAX_NOTE 文字以内。省略・null は未設定。制御文字は画面と同じ検証で見る）
     *
     * @param  array<array-key, mixed>  $fields
     */
    private function optionalNote(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $this->error([$name], '文字列で指定してください');

            return null;
        }
        if (mb_strlen($value) > OrderValidation::MAX_NOTE) {
            $this->error([$name], OrderValidation::MAX_NOTE.' 文字以内で指定してください');

            return null;
        }

        return $value;
    }

    /**
     * 定義にない項目（1 項目ずつエラー）
     *
     * @param  array<array-key, mixed>  $fields
     * @param  list<string>  $allowed
     * @param  list<string|int>  $path
     */
    private function extraFields(array $fields, array $allowed, array $path): void
    {
        foreach (array_keys($fields) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->error([...$path, $key], 'この項目は指定できません');
            }
        }
    }

    /** @param  list<string|int>  $path */
    private function error(array $path, string $detail): void
    {
        $this->errors[] = ['pointer' => ApiJsonBody::pointer($path), 'detail' => $detail];
    }
}
