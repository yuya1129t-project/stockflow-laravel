<?php

namespace App\Products;

use App\Support\Csv\CsvWriter;

/**
 * 商品の CSV 出力の中身（docs/spec.md §7）。見出しは取込で使う 7 列＋読み取り専用の「現在庫」「状態」。
 * BOM は付けない（ファイル全体の先頭にコントローラーで付ける）
 *
 * @phpstan-import-type ProductItem from ProductQuery
 */
final class ProductCsvExporter
{
    public const string STOCK_HEADER = '現在庫';

    public const string STATUS_HEADER = '状態';

    /** @return list<string> */
    public static function headers(): array
    {
        return [...array_values(ProductValidation::LABELS), self::STOCK_HEADER, self::STATUS_HEADER];
    }

    /** @param  list<ProductItem>  $products */
    public static function build(array $products): string
    {
        $rows = [self::headers()];
        foreach ($products as $p) {
            $rows[] = [
                $p['code'],
                $p['name'],
                $p['category'],
                $p['unit'],
                $p['sales_price'],
                $p['purchase_price'],
                $p['reorder_point'],
                $p['stock'],
                $p['is_active'] ? '有効' : '無効',
            ];
        }

        return CsvWriter::write($rows);
    }
}
