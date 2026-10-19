<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\V1\ApiQuery;
use App\Api\V1\ApiResponse;
use App\Api\V1\ApiSerializer;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Products\ProductListParams;
use App\Products\ProductQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST API の商品（GET /api/v1/products・GET /api/v1/products/{code}/stock。docs/openapi.json）。
 * 認証は Bearer トークン、権限は閲覧（ルートの can: と、ここの Policy で二重に検査する）
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductQuery $products) {}

    /** 商品一覧（現在庫を含む）。商品コードの昇順。無効な商品も含める（isActive で区別） */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $query = ApiQuery::fromRequest($request);
        $q = $query->string('q', ApiQuery::QUERY_MAX_LENGTH);
        ['page' => $page, 'perPage' => $perPage] = $query->paging();
        $query->throwIfInvalid();

        $result = $this->products->paginate(new ProductListParams(q: $q, status: 'all', page: $page), perPage: $perPage, clampPage: false);

        return ApiResponse::json([
            'data' => array_map(ApiSerializer::product(...), $result['items']),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $result['total']],
        ]);
    }

    /** 商品の現在庫と発注点（商品コードの大文字・小文字は区別しない） */
    public function stock(string $code): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $code = strtoupper(ApiQuery::pathParameter('code', $code));
        $product = Product::query()->where('code', $code)->first();
        if ($product === null || strtoupper($product->code) !== $code) {
            return ApiResponse::problem(404, "商品「{$code}」が見つかりません");
        }

        return ApiResponse::json(ApiSerializer::productStock($this->products->find($product)));
    }
}
