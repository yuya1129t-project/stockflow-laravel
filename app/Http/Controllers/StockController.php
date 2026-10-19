<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockAdjustRequest;
use App\Models\Product;
use App\Models\User;
use App\Products\ProductQuery;
use App\ReorderNotifications\ReorderPointNotifier;
use App\ReorderNotifications\ReorderPointTrigger;
use App\Stock\StockAdjustment;
use App\Stock\StockListParams;
use App\Stock\StockQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 在庫の画面（現在庫一覧・商品ごとの入出庫履歴・棚卸調整。docs/spec.md §5-7）。
 * 閲覧は全ロール、棚卸調整は admin・staff（ルートの can: と、ここ・Form Request の Policy で二重に検査する）
 */
class StockController extends Controller
{
    public function __construct(
        private readonly ProductQuery $products,
        private readonly StockQuery $stock,
    ) {}

    /** 現在庫一覧（商品一覧と同じ検索・並べ替え・ページ送り＋発注点割れのみの絞り込み） */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $params = StockListParams::fromQuery($request->query());
        $result = $this->products->paginate($params->products, $params->lowStockOnly());

        return Inertia::render('stock/Index', [
            'products' => $result['items'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'perPage' => $result['perPage'],
            ],
            'filters' => $params->withPage($result['page'])->toArray(),
            'categories' => $this->products->categories(),
        ]);
    }

    /** 商品ごとの在庫（現在庫・入出庫履歴・棚卸調整のフォーム） */
    public function show(Request $request, Product $product): Response
    {
        Gate::authorize('view', $product);

        $page = $request->query('page');
        $history = $this->stock->movements(
            $product,
            is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/', $page) === 1 ? (int) $page : 1,
        );

        return Inertia::render('stock/Show', [
            'product' => $this->products->find($product),
            'movements' => $history['items'],
            'pagination' => [
                'total' => $history['total'],
                'page' => $history['page'],
                'totalPages' => $history['totalPages'],
                'perPage' => $history['perPage'],
            ],
            'limits' => [
                'actualQuantity' => StockAdjustRequest::MAX_ACTUAL_QUANTITY,
                'reason' => StockAdjustRequest::MAX_REASON,
            ],
        ]);
    }

    /** 棚卸調整（実数との差分を adjust で記録） */
    public function adjust(StockAdjustRequest $request, Product $product, StockAdjustment $adjustment, ReorderPointNotifier $notifier): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $result = $adjustment->adjust($user, $product, $request->actualQuantity(), $request->reason());
        // 発注点割れの通知（spec §6-3）: adjust（StockTransaction::run）が戻った後＝トランザクションの確定後に Job を投入する
        $notifier->notify($result['fellBelowReorderPoint'], ReorderPointTrigger::stockAdjust($product->id), $user->id);
        Inertia::flash('toast', $result['recorded']
            ? [
                'type' => 'success',
                'message' => sprintf(
                    '在庫を %s → %s %sに調整しました（差分 %s）',
                    number_format($result['before']),
                    number_format($result['after']),
                    $product->unit,
                    ($result['delta'] > 0 ? '+' : '').number_format($result['delta']),
                ),
            ]
            : ['type' => 'info', 'message' => '実数が現在庫と同じため、記録しませんでした']);

        return to_route('stock.show', $product);
    }
}
