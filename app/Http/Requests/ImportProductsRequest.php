<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Products\ProductImportParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * 商品の CSV 取込のファイル（docs/spec.md §7）。中身の検証は ProductImportParser で行う。
 * 大きすぎるファイルは画面でも送信前に止める（resources/js/pages/products/Import.vue）が、ここでも必ず検査する
 */
class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('import', Product::class) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.intdiv(ProductImportParser::MAX_BYTES, 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $limit = intdiv(ProductImportParser::MAX_BYTES, 1024);

        return [
            'file.required' => 'CSV ファイルを選択してください',
            'file.file' => 'CSV ファイルを選択してください',
            'file.uploaded' => "ファイルをアップロードできませんでした（上限 {$limit}KB）",
            'file.max' => "ファイルが大きすぎます（上限 {$limit}KB）。ファイルを分けて取り込んでください",
        ];
    }

    public function csvFile(): UploadedFile
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        return $file;
    }
}
