<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Products\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** 商品の無効化（論理削除）・再有効化。admin のみ（docs/spec.md §2） */
class ProductActivationController extends Controller
{
    public function __construct(private readonly ProductService $service) {}

    public function deactivate(Request $request, Product $product): RedirectResponse
    {
        return $this->change($request, $product, false);
    }

    public function reactivate(Request $request, Product $product): RedirectResponse
    {
        return $this->change($request, $product, true);
    }

    private function change(Request $request, Product $product, bool $active): RedirectResponse
    {
        Gate::authorize('changeActive', $product);
        $user = $request->user();
        assert($user instanceof User);

        $this->service->setActive($user, $product, $active);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "商品 {$product->code} を".($active ? '再度有効に' : '無効に').'しました',
        ]);

        return to_route('products.show', $product);
    }
}
