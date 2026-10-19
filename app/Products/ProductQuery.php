<?php

namespace App\Products;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 商品の読み取り（一覧・詳細・CSV 出力）。
 *
 * 現在庫は入出庫（stock_movements.quantity）の合計（docs/spec.md §3）。商品ごとに問い合わせず（N+1 にしない）、
 * 入出庫を商品ごとに集計した結果を 1 本の SQL で結合する。現在庫での並べ替えもこの SQL で行う
 *
 * @phpstan-type ProductItem array{id: int, code: string, name: string, category: string, unit: string, sales_price: int, purchase_price: int, reorder_point: int, is_active: bool, stock: int, is_low_stock: bool}
 */
final class ProductQuery
{
    /** CSV 出力の上限件数（超えたら出力せず 413。黙って切り捨てない） */
    public const int EXPORT_MAX_ROWS = 10_000;

    /**
     * 一覧（検索・並べ替え・ページ送り）。範囲外のページは最終ページに丸める。
     * lowStockOnly は発注点割れ（有効な商品で 現在庫 < 発注点）だけに絞る（現在庫一覧 /stock の絞り込み）。
     * perPage・clampPage は REST API 用（1 ページの件数の指定と、範囲外のページを丸めずに空にする）。画面は既定値のまま使う
     *
     * @return array{items: list<ProductItem>, total: int, page: int, totalPages: int, perPage: int}
     */
    public function paginate(ProductListParams $params, bool $lowStockOnly = false, ?int $perPage = null, bool $clampPage = true): array
    {
        // 発注点割れの絞り込みは現在庫を使うので、件数も集計を結合して数える（絞り込まないときは結合しない）
        $filtered = fn (): Builder => $lowStockOnly ? $this->onlyLowStock($this->withStock($this->filtered($params))) : $this->filtered($params);
        $total = $filtered()->count();
        $perPage ??= ProductListParams::PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = $clampPage ? min($params->page, $totalPages) : $params->page;

        $products = $this->sorted($lowStockOnly ? $filtered() : $this->withStock($filtered()), $params)
            ->forPage($page, $perPage)
            ->get();

        return [
            'items' => array_values($products->map(self::toItem(...))->all()),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ];
    }

    /**
     * CSV 出力用（絞り込み・並べ替えは一覧と同じ。ページ送りはしない）。上限を超えたら null
     *
     * @return list<ProductItem>|null
     */
    public function forExport(ProductListParams $params, int $limit = self::EXPORT_MAX_ROWS): ?array
    {
        $products = $this->sorted($this->withStock($this->filtered($params)), $params)
            ->limit($limit + 1)
            ->get();

        return $products->count() > $limit ? null : array_values($products->map(self::toItem(...))->all());
    }

    /**
     * 詳細（現在庫を含む）
     *
     * @return ProductItem
     */
    public function find(Product $product): array
    {
        $found = $this->withStock(Product::query()->whereKey($product->id))->firstOrFail();

        return self::toItem($found);
    }

    /**
     * 発注点割れの商品の件数と、不足（発注点 − 現在庫）の大きい順の上位 limit 件（ダッシュボード）。
     * 発注点割れの定義は一覧・現在庫一覧の「発注点割れのみ」と同じ（onlyLowStock）
     *
     * @return array{count: int, items: list<array{id: int, code: string, name: string, unit: string, reorder_point: int, stock: int, shortage: int}>}
     */
    public function lowStock(int $limit): array
    {
        $count = $this->onlyLowStock($this->withStock(Product::query()))->count();
        $products = $this->onlyLowStock($this->withStock(Product::query()))
            ->orderByRaw('products.reorder_point - COALESCE(s.stock, 0) DESC')
            ->orderBy('products.code')
            ->limit($limit)
            ->get();

        return [
            'count' => $count,
            'items' => array_values($products->map(function (Product $p): array {
                $item = self::toItem($p);

                return [
                    'id' => $item['id'],
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'reorder_point' => $item['reorder_point'],
                    'stock' => $item['stock'],
                    'shortage' => $item['reorder_point'] - $item['stock'],
                ];
            })->all()),
        ];
    }

    /**
     * 登録済みのカテゴリ（検索の選択肢・入力候補）
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $products = Product::query()->select('category')->distinct()->orderBy('category')->get();

        return array_values($products->map(fn (Product $p) => $p->category)->all());
    }

    /** @return Builder<Product> */
    private function filtered(ProductListParams $params): Builder
    {
        $query = Product::query();

        if ($params->q !== '') {
            $pattern = '%'.addcslashes($params->q, '\\%_').'%';
            $query->where(fn (Builder $q) => $q
                ->where('products.code', 'like', $pattern)
                ->orWhere('products.name', 'like', $pattern)
                ->orWhere('products.category', 'like', $pattern));
        }
        if ($params->category !== '') {
            $query->where('products.category', $params->category);
        }
        if ($params->status === 'active') {
            $query->where('products.is_active', true);
        } elseif ($params->status === 'inactive') {
            $query->where('products.is_active', false);
        }

        return $query;
    }

    /**
     * 入出庫を商品ごとに集計して結合し、現在庫（stock）を足す
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function withStock(Builder $query): Builder
    {
        $stock = DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw('SUM(quantity) AS stock')
            ->groupBy('product_id');

        return $query
            ->select('products.*')
            ->selectRaw('CAST(COALESCE(s.stock, 0) AS SIGNED) AS stock')
            ->leftJoinSub($stock, 's', 's.product_id', '=', 'products.id');
    }

    /**
     * 発注点割れ（有効な商品で 現在庫 < 発注点。toItem の is_low_stock と同じ定義）だけにする。withStock の後に使う
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function onlyLowStock(Builder $query): Builder
    {
        return $query
            ->where('products.is_active', true)
            ->whereRaw('COALESCE(s.stock, 0) < products.reorder_point');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function sorted(Builder $query, ProductListParams $params): Builder
    {
        $query->orderBy(ProductListParams::SORT_COLUMNS[$params->sort], $params->order);
        // 同じ値の行の順番が毎回変わらないよう、最後に商品コードで並べる
        if ($params->sort !== 'code') {
            $query->orderBy('products.code');
        }

        return $query;
    }

    /** @return ProductItem */
    private static function toItem(Product $product): array
    {
        $stock = (int) $product->getAttribute('stock');

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'category' => $product->category,
            'unit' => $product->unit,
            'sales_price' => $product->sales_price,
            'purchase_price' => $product->purchase_price,
            'reorder_point' => $product->reorder_point,
            'is_active' => $product->is_active,
            'stock' => $stock,
            // 発注点割れ: 有効な商品で 現在庫 < 発注点
            'is_low_stock' => $product->is_active && $stock < $product->reorder_point,
        ];
    }
}
