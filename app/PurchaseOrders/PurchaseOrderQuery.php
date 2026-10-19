<?php

namespace App\PurchaseOrders;

use App\Enums\PartnerRole;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Orders\OrderVersion;
use Illuminate\Database\Eloquent\Builder;

/**
 * 発注の読み取り（一覧・詳細・CSV 出力・作成／編集の選択肢）。ロックはしない
 *
 * @phpstan-type PurchaseOrderListItem array{id: int, number: string, order_date: string, expected_date: string|null, status: string, status_label: string, supplier: array{id: int, code: string, name: string}, line_count: int, subtotal: int, tax: int, total: int, note: string|null}
 * @phpstan-type PurchaseOrderLineItem array{id: int, product_id: int, product_code: string, product_name: string, unit: string, product_is_active: bool, quantity: int, unit_price: int, amount: int}
 * @phpstan-type PurchaseOrderDetail array{id: int, number: string, order_date: string, expected_date: string|null, status: string, status_label: string, note: string|null, subtotal: int, tax: int, total: int, version: string, created_at: string|null, updated_at: string|null, supplier: array{id: int, code: string, name: string, is_active: bool}, lines: list<PurchaseOrderLineItem>}
 * @phpstan-type SupplierOption array{id: int, code: string, name: string, is_active: bool}
 * @phpstan-type LineProductOption array{id: int, code: string, name: string, unit: string, price: int, is_active: bool}
 */
final class PurchaseOrderQuery
{
    /** CSV 出力の上限件数（超えたら出力せず 413。黙って切り捨てない。商品の出力と同じ） */
    public const int EXPORT_MAX_ROWS = 10_000;

    /**
     * 一覧（絞り込み・並べ替え・ページ送り）。範囲外のページは最終ページに丸める
     *
     * @return array{items: list<PurchaseOrderListItem>, total: int, page: int, totalPages: int, perPage: int}
     */
    public function paginate(PurchaseOrderListParams $params): array
    {
        $total = $this->filtered($params)->count();
        $perPage = PurchaseOrderListParams::PER_PAGE;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($params->page, $totalPages);

        $orders = $this->sorted($this->filtered($params), $params)->forPage($page, $perPage)->get();

        return [
            'items' => array_values($orders->map(self::toListItem(...))->all()),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ];
    }

    /**
     * CSV 出力用（絞り込み・並べ替えは一覧と同じ。ページ送りはしない）。上限を超えたら null
     *
     * @return list<PurchaseOrderListItem>|null
     */
    public function forExport(PurchaseOrderListParams $params, int $limit = self::EXPORT_MAX_ROWS): ?array
    {
        $orders = $this->sorted($this->filtered($params), $params)->limit($limit + 1)->get();

        return $orders->count() > $limit ? null : array_values($orders->map(self::toListItem(...))->all());
    }

    /**
     * 詳細（仕入先・明細の商品を含む。明細は登録の順）
     *
     * @return PurchaseOrderDetail
     */
    public function detail(PurchaseOrder $order): array
    {
        $order = PurchaseOrder::query()
            ->with(['supplier:id,code,name,is_active', 'lines' => fn ($q) => $q->orderBy('id'), 'lines.product:id,code,name,unit,is_active'])
            ->findOrFail($order->id);
        $supplier = $order->supplier;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'order_date' => $order->order_date->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'note' => $order->note,
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'total' => $order->total,
            'version' => OrderVersion::of($order->updated_at),
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'updated_at' => $order->updated_at?->format('Y-m-d H:i'),
            'supplier' => ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name, 'is_active' => $supplier->is_active],
            'lines' => array_values($order->lines->map(fn (PurchaseOrderLine $line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product->code,
                'product_name' => $line->product->name,
                'unit' => $line->product->unit,
                'product_is_active' => $line->product->is_active,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'amount' => $line->quantity * $line->unit_price,
            ])->all()),
        ];
    }

    /**
     * 仕入先の選択肢（有効な supplier・both）。includeId は今の仕入先（編集で、無効・種別変更後でも選択欄に出す）
     *
     * @return list<SupplierOption>
     */
    public function supplierOptions(?int $includeId = null): array
    {
        $suppliers = Partner::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $active) => $active->withRole(PartnerRole::Supplier)->where('is_active', true))
                ->when($includeId !== null, fn (Builder $current) => $current->orWhere('id', $includeId)))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active']);

        return array_values($suppliers->map(fn (Partner $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_active' => $p->is_active])->all());
    }

    /**
     * 一覧の仕入先の絞り込みの選択肢（無効も含めた supplier・both と、発注のある取引先。種別を変えた・無効にした仕入先の発注も探せる）
     *
     * @return list<SupplierOption>
     */
    public function supplierFilterOptions(): array
    {
        $suppliers = Partner::query()
            ->where(fn (Builder $q) => $q
                ->withRole(PartnerRole::Supplier)
                ->orWhereIn('id', PurchaseOrder::query()->select('supplier_id')))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active']);

        return array_values($suppliers->map(fn (Partner $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_active' => $p->is_active])->all());
    }

    /**
     * 明細の商品の選択肢（有効な商品。単価の初期値は仕入単価）。includeIds は今の明細の商品（編集で、無効でも残せる）
     *
     * @param  list<int>  $includeIds
     * @return list<LineProductOption>
     */
    public function productOptions(array $includeIds = []): array
    {
        $products = Product::query()
            ->where(fn (Builder $q) => $q
                ->where('is_active', true)
                ->when($includeIds !== [], fn (Builder $current) => $current->orWhereIn('id', $includeIds)))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'unit', 'purchase_price', 'is_active']);

        return array_values($products->map(fn (Product $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'unit' => $p->unit,
            'price' => $p->purchase_price,
            'is_active' => $p->is_active,
        ])->all());
    }

    /** @return Builder<PurchaseOrder> */
    private function filtered(PurchaseOrderListParams $params): Builder
    {
        $query = PurchaseOrder::query();

        if ($params->q !== '') {
            $query->where('purchase_orders.number', 'like', '%'.addcslashes($params->q, '\\%_').'%');
        }
        if ($params->supplierId !== null) {
            $query->where('purchase_orders.supplier_id', $params->supplierId);
        }
        if ($params->status !== null) {
            $query->where('purchase_orders.status', $params->status->value);
        }
        if ($params->from !== '') {
            $query->where('purchase_orders.order_date', '>=', $params->from);
        }
        if ($params->to !== '') {
            $query->where('purchase_orders.order_date', '<=', $params->to);
        }

        return $query;
    }

    /**
     * 並べ替え（許可したキーだけ）。同じ値の行の順番が毎回変わらないよう、最後に id で並べる。
     * 番号は「日付 → 桁数 → 文字列」の順に並べる（1 日に 999 件を超えて PO-…-1000 になっても PO-…-999 の後に並ぶ）。
     * 発注日の並びの同点は id（同じ日の中では作成の順＝番号の順）
     *
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    private function sorted(Builder $query, PurchaseOrderListParams $params): Builder
    {
        $direction = $params->order;
        $query->select('purchase_orders.*')
            ->with('supplier:id,code,name')
            ->withCount('lines');

        match ($params->sort) {
            'number' => $query->orderBy('purchase_orders.order_date', $direction)
                ->orderByRaw('CHAR_LENGTH(purchase_orders.number) '.$direction)
                ->orderBy('purchase_orders.number', $direction),
            'supplier' => $query->join('partners', 'partners.id', '=', 'purchase_orders.supplier_id')
                ->orderBy('partners.code', $direction),
            'expectedDate' => $query->orderBy('purchase_orders.expected_date', $direction),
            'total' => $query->orderBy('purchase_orders.total', $direction),
            default => $query->orderBy('purchase_orders.order_date', $direction),
        };

        return $query->orderBy('purchase_orders.id', $direction);
    }

    /** @return PurchaseOrderListItem */
    private static function toListItem(PurchaseOrder $order): array
    {
        $supplier = $order->supplier;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'order_date' => $order->order_date->toDateString(),
            'expected_date' => $order->expected_date?->toDateString(),
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'supplier' => ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name],
            'line_count' => (int) $order->getAttribute('lines_count'),
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'total' => $order->total,
            'note' => $order->note,
        ];
    }
}
