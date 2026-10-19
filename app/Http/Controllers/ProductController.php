<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Models\User;
use App\Products\ProductListParams;
use App\Products\ProductQuery;
use App\Products\ProductService;
use App\Products\ProductValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 商品マスタの画面（一覧・登録・詳細・編集。docs/spec.md §5-3）。
 * 権限はルートの can: と、ここ（Gate::authorize）・Form Request の Policy で二重に検査する
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductQuery $products,
        private readonly ProductService $service,
    ) {}

    /** 一覧（検索・並べ替え・ページ送り。条件は URL のクエリ） */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $params = ProductListParams::fromQuery($request->query());
        $result = $this->products->paginate($params);

        return Inertia::render('products/Index', [
            'products' => $result['items'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'perPage' => $result['perPage'],
            ],
            'filters' => $params->withPage($result['page'])->toArray(),
            'categories' => $this->products->categories(),
            'exportMaxRows' => ProductQuery::EXPORT_MAX_ROWS,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('products/Create', [
            'categories' => $this->products->categories(),
            'limits' => self::limits(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->service->create(self::actor($request), $request->productAttributes());
        Inertia::flash('toast', ['type' => 'success', 'message' => "商品 {$product->code} を登録しました"]);

        return to_route('products.show', $product);
    }

    public function show(Product $product): Response
    {
        Gate::authorize('view', $product);

        return Inertia::render('products/Show', [
            'product' => $this->products->find($product),
        ]);
    }

    public function edit(Product $product): Response
    {
        Gate::authorize('update', $product);

        return Inertia::render('products/Edit', [
            'product' => $this->products->find($product),
            'categories' => $this->products->categories(),
            'limits' => self::limits(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $changed = $this->service->update(self::actor($request), $product, $request->productAttributes());
        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => '商品を保存しました']
            : ['type' => 'info', 'message' => '変更はありませんでした']);

        return to_route('products.show', $product);
    }

    /**
     * 入力欄の上限（画面の maxlength。検証はサーバー側で行う）
     *
     * @return array<string, int>
     */
    private static function limits(): array
    {
        return [
            'code' => ProductValidation::MAX_CODE,
            'name' => ProductValidation::MAX_NAME,
            'category' => ProductValidation::MAX_CATEGORY,
            'unit' => ProductValidation::MAX_UNIT,
        ];
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
