<?php

namespace App\Stock;

use App\Enums\StockRefType;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * 在庫の読み取り（現在庫・入出庫履歴）。ロックはしない（記録の前の判定には StockTransaction::lockProducts を使う）
 *
 * @phpstan-type StockMovementItem array{id: int, created_at: string|null, type: string, quantity: int, reason: string|null, ref: array{type: string, id: int, number: string|null}|null, user_name: string|null}
 */
final class StockQuery
{
    /** 入出庫履歴の 1 ページの件数 */
    public const int MOVEMENTS_PER_PAGE = 20;

    /**
     * 商品ごとの現在庫（入出庫の合計）。1 本の SQL で集計する（N+1 にしない）。入出庫のない商品・存在しない商品は 0
     *
     * @param  list<int>  $productIds
     * @return array<int, int> 商品 ID → 現在庫
     */
    public function stocks(array $productIds): array
    {
        $ids = array_values(array_unique($productIds));
        if ($ids === []) {
            return [];
        }
        $stocks = array_fill_keys($ids, 0);
        $rows = DB::table('stock_movements')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('CAST(SUM(quantity) AS SIGNED) AS stock')
            ->get();
        foreach ($rows as $row) {
            $stocks[(int) $row->product_id] = (int) $row->stock;
        }

        return $stocks;
    }

    /** 1 商品の現在庫 */
    public function stock(int $productId): int
    {
        return $this->stocks([$productId])[$productId];
    }

    /**
     * 商品の入出庫履歴（新しい順・ページ送り）。範囲外のページは最終ページに丸める。
     * 記録したユーザーと参照元の伝票番号はまとめて引く（N+1 にしない）
     *
     * @return array{items: list<StockMovementItem>, total: int, page: int, totalPages: int, perPage: int}
     */
    public function movements(Product $product, int $page): array
    {
        $total = StockMovement::query()->where('product_id', $product->id)->count();
        $perPage = self::MOVEMENTS_PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $rows = StockMovement::query()
            ->where('product_id', $product->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $idsOf = fn (StockRefType $type) => array_values(array_unique(array_filter(
            $rows->map(fn (StockMovement $m) => $m->ref_type === $type ? $m->ref_id : null)->all(),
        )));
        $salesIds = $idsOf(StockRefType::SalesOrder);
        $purchaseIds = $idsOf(StockRefType::PurchaseOrder);
        $numbers = [
            StockRefType::SalesOrder->value => $salesIds === [] ? [] : SalesOrder::query()->whereKey($salesIds)->pluck('number', 'id')->all(),
            StockRefType::PurchaseOrder->value => $purchaseIds === [] ? [] : PurchaseOrder::query()->whereKey($purchaseIds)->pluck('number', 'id')->all(),
        ];

        $items = [];
        foreach ($rows as $m) {
            $items[] = [
                'id' => $m->id,
                'created_at' => $m->created_at?->format('Y-m-d H:i'),
                'type' => $m->type->value,
                'quantity' => $m->quantity,
                'reason' => $m->reason,
                'ref' => $m->ref_type !== null && $m->ref_id !== null
                    ? ['type' => $m->ref_type->value, 'id' => $m->ref_id, 'number' => $numbers[$m->ref_type->value][$m->ref_id] ?? null]
                    : null,
                'user_name' => $m->user?->name,
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages, 'perPage' => $perPage];
    }
}
