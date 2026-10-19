<?php

namespace App\Products;

use App\Support\Csv\CsvFormula;
use App\Support\Csv\CsvParseException;
use App\Support\Csv\CsvParser;
use App\Support\Csv\CsvRecord;
use Illuminate\Support\Facades\Validator;

/**
 * 商品の CSV 取込ファイルの検証（docs/spec.md §7）。DB には触れない（書き込みは ProductService::import）。
 *
 * - 文字コードは UTF-8（BOM の有無は問わない）。1 行目は見出し行（列の順番は問わない）
 * - 商品コード・商品名・カテゴリ・単位・売価・仕入単価・発注点の列が必須。それ以外の列（出力 CSV の現在庫・状態など）は
 *   読み飛ばす（有効／無効は CSV では変えない。無効化は admin だけの操作のため）
 * - データ行は 1,000 行まで。ファイルは 512KB まで。空行（すべてのセルが空）は読み飛ばす
 * - データ行の列数は見出し行と同じであること（列ずれのまま取り込まない）
 * - 各行は画面の登録・編集と同じ規則で検証する（ProductValidation）。ファイルの中の商品コードの重複もエラー
 * - 1 行でもエラーがあれば全体を取り込まない（エラーは行番号付きで返す）
 *
 * @phpstan-type ImportError array{line: int|null, field: string|null, message: string}
 * @phpstan-type ImportRow array{line: int, data: array{code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int}}
 * @phpstan-type ParseResult array{ok: true, rows: list<ImportRow>}|array{ok: false, errors: list<ImportError>, totalErrors: int}
 */
final class ProductImportParser
{
    /** 取込できるデータ行の上限（spec §7） */
    public const int MAX_ROWS = 1_000;

    /** 取込ファイルの上限（バイト）。1,000 行 × 1 行あたり 200〜400 バイト程度を想定 */
    public const int MAX_BYTES = 512 * 1024;

    /** 画面に返すエラーの最大件数（これを超えた分は件数だけ返す） */
    public const int MAX_REPORTED_ERRORS = 100;

    /** @return ParseResult */
    public static function parse(string $bytes): array
    {
        if ($bytes === '') {
            return self::fileError('ファイルが空です');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return self::fileError('ファイルが大きすぎます（上限 '.(self::MAX_BYTES / 1024).'KB）');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return self::fileError('文字コードが UTF-8 ではありません。UTF-8 で保存し直してください（Excel では「CSV UTF-8（コンマ区切り）」）');
        }

        try {
            $records = CsvParser::parse($bytes);
        } catch (CsvParseException $e) {
            return self::failed([['line' => $e->csvLine, 'field' => null, 'message' => $e->getMessage()]]);
        }

        // 空行（末尾の空行や Excel が出力する ",,,," の行）は読み飛ばす
        $records = array_values(array_filter($records, fn (CsvRecord $r) => ! $r->isBlank()));
        $header = array_shift($records);
        if ($header === null) {
            return self::fileError('ファイルが空です');
        }

        // 見出し → 列番号
        $labelToField = array_flip(ProductValidation::LABELS);
        $columnOf = [];
        $headerErrors = [];
        foreach ($header->cells as $index => $cell) {
            $name = mb_trim($cell);
            $field = $labelToField[$name] ?? null;
            if ($field === null) {
                continue;
            }
            if (array_key_exists($field, $columnOf)) {
                $headerErrors[] = ['line' => $header->line, 'field' => $name, 'message' => "見出し「{$name}」が重複しています"];

                continue;
            }
            $columnOf[$field] = $index;
        }
        foreach (ProductValidation::LABELS as $field => $label) {
            if (! array_key_exists($field, $columnOf)) {
                $headerErrors[] = ['line' => $header->line, 'field' => $label, 'message' => "見出し行に「{$label}」の列がありません"];
            }
        }
        if ($headerErrors !== []) {
            return self::failed($headerErrors);
        }

        if ($records === []) {
            return self::fileError('取り込むデータ行がありません（見出し行だけです）');
        }
        if (count($records) > self::MAX_ROWS) {
            return self::fileError('データ行が上限（'.number_format(self::MAX_ROWS).' 行）を超えています（'.number_format(count($records)).' 行）。ファイルを分けて取り込んでください');
        }

        $errors = [];
        $rows = [];
        $lineOfCode = [];
        $columnCount = count($header->cells);
        foreach ($records as $record) {
            // 列数が見出しと違う行は、値が別の列にずれている（囲んでいない 1,200 など）ので 1 件のエラーにする
            if (count($record->cells) !== $columnCount) {
                $errors[] = [
                    'line' => $record->line,
                    'field' => null,
                    'message' => "列の数が見出し行と違います（見出し {$columnCount} 列・この行 ".count($record->cells).' 列）。カンマを含む値は "" で囲んでください',
                ];

                continue;
            }

            $raw = [];
            foreach ($columnOf as $field => $index) {
                $raw[$field] = CsvFormula::unsanitize($record->cells[$index]);
            }
            $validator = Validator::make(
                ProductValidation::normalize($raw),
                ProductValidation::rules(),
                ProductValidation::messages(),
                ProductValidation::attributes(),
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['line' => $record->line, 'field' => ProductValidation::LABELS[$field] ?? null, 'message' => $message];
                    }
                }

                continue;
            }

            $data = ProductValidation::toAttributes($validator->validated());
            $firstLine = $lineOfCode[$data['code']] ?? null;
            if ($firstLine !== null) {
                $errors[] = [
                    'line' => $record->line,
                    'field' => ProductValidation::LABELS['code'],
                    'message' => "商品コード「{$data['code']}」が {$firstLine} 行目と重複しています",
                ];

                continue;
            }
            $lineOfCode[$data['code']] = $record->line;
            $rows[] = ['line' => $record->line, 'data' => $data];
        }

        return $errors !== [] ? self::failed($errors) : ['ok' => true, 'rows' => $rows];
    }

    /** @return array{ok: false, errors: list<ImportError>, totalErrors: int} */
    private static function fileError(string $message): array
    {
        return self::failed([['line' => null, 'field' => null, 'message' => $message]]);
    }

    /**
     * @param  list<ImportError>  $errors
     * @return array{ok: false, errors: list<ImportError>, totalErrors: int}
     */
    private static function failed(array $errors): array
    {
        return [
            'ok' => false,
            'errors' => array_slice($errors, 0, self::MAX_REPORTED_ERRORS),
            'totalErrors' => count($errors),
        ];
    }
}
