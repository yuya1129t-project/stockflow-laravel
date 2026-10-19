<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportProductsRequest;
use App\Models\Product;
use App\Models\User;
use App\Products\ProductImportParser;
use App\Products\ProductService;
use App\Rules\SingleLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 商品の CSV 取込（docs/spec.md §7）。1 行でもエラーがあれば何も取り込まない。
 * 結果（件数、または行番号付きのエラーの一覧）は 1 回だけの flash（importResult）で取込画面に返す
 */
class ProductImportController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('import', Product::class);

        return Inertia::render('products/Import', [
            'limits' => [
                'maxRows' => ProductImportParser::MAX_ROWS,
                'maxBytes' => ProductImportParser::MAX_BYTES,
            ],
        ]);
    }

    public function store(ImportProductsRequest $request, ProductService $service): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $file = $request->csvFile();
        $fileName = self::displayFileName($file->getClientOriginalName());

        $parsed = ProductImportParser::parse((string) $file->get());
        if (! $parsed['ok']) {
            Inertia::flash('importResult', [
                'status' => 'error',
                'fileName' => $fileName,
                'message' => 'エラーがあるため取り込みませんでした（1 件も登録・更新していません）。修正してからもう一度取り込んでください',
                'errors' => $parsed['errors'],
                'totalErrors' => $parsed['totalErrors'],
            ]);

            return to_route('products.import');
        }

        $summary = $service->import($user, $parsed['rows'], $fileName);
        Inertia::flash('importResult', [
            'status' => 'success',
            'fileName' => $fileName,
            'summary' => $summary,
        ]);

        return to_route('products.import');
    }

    /** ファイル名を操作履歴・画面に出せる形にする（制御文字・表示方向の制御文字を除き、長さを制限） */
    private static function displayFileName(string $name): string
    {
        $name = mb_check_encoding($name, 'UTF-8') ? mb_substr(SingleLine::strip($name), 0, 100) : '';

        return $name !== '' ? $name : '（名前なし）';
    }
}
