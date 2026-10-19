<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\PurchaseOrderRequest;
use App\Http\Requests\PurchaseOrderStatusRequest;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Orders\OrderAmounts;
use App\Orders\OrderValidation;
use App\PurchaseOrders\PurchaseOrderListParams;
use App\PurchaseOrders\PurchaseOrderQuery;
use App\PurchaseOrders\PurchaseOrderService;
use App\Support\Tax;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 発注の画面（一覧・作成・詳細・編集・ステータス変更。docs/spec.md §4・§5-6）。
 * 閲覧は全ロール、作成・編集・ステータス変更は admin・staff（ルートの can: と、ここ・Form Request の Policy で二重に検査する）
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQuery $orders,
        private readonly PurchaseOrderService $service,
    ) {}

    /** 一覧（番号・仕入先・ステータス・期間で絞り込み、並べ替え、ページ送り。条件は URL のクエリ） */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $params = PurchaseOrderListParams::fromQuery($request->query());
        $result = $this->orders->paginate($params);

        return Inertia::render('purchase-orders/Index', [
            'orders' => $result['items'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'perPage' => $result['perPage'],
            ],
            'filters' => $params->withPage($result['page'])->toArray(),
            'suppliers' => $this->orders->supplierFilterOptions(),
            'statuses' => self::statuses(),
            'exportMaxRows' => PurchaseOrderQuery::EXPORT_MAX_ROWS,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PurchaseOrder::class);

        return Inertia::render('purchase-orders/Create', [
            'suppliers' => $this->orders->supplierOptions(),
            'products' => $this->orders->productOptions(),
            'limits' => self::limits(),
        ]);
    }

    public function store(PurchaseOrderRequest $request): RedirectResponse
    {
        $order = $this->service->create(self::actor($request), $request->purchaseOrderInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => "発注 {$order->number} を作成しました"]);

        return to_route('purchase-orders.show', $order);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        return Inertia::render('purchase-orders/Show', [
            'order' => $this->orders->detail($purchaseOrder),
            'transitions' => array_map(fn (PurchaseOrderStatus $s) => $s->value, $purchaseOrder->status->transitions()),
        ]);
    }

    /** 編集（下書きのときだけ。それ以外は詳細へ戻す） */
    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        Gate::authorize('update', $purchaseOrder);

        if (! $purchaseOrder->status->isEditable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "この発注は「{$purchaseOrder->status->label()}」のため編集できません（編集できるのは下書きだけです）"]);

            return to_route('purchase-orders.show', $purchaseOrder);
        }
        $detail = $this->orders->detail($purchaseOrder);

        return Inertia::render('purchase-orders/Edit', [
            'order' => $detail,
            'suppliers' => $this->orders->supplierOptions($purchaseOrder->supplier_id),
            'products' => $this->orders->productOptions(array_column($detail['lines'], 'product_id')),
            'limits' => self::limits(),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $changed = $this->service->update(self::actor($request), $purchaseOrder->id, $request->purchaseOrderInput(), $request->version());
        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => "発注 {$purchaseOrder->number} を保存しました"]
            : ['type' => 'info', 'message' => '変更はありませんでした']);

        return to_route('purchase-orders.show', $purchaseOrder);
    }

    /** ステータス変更（発注済・入荷済・取消。入荷は全明細を在庫に入庫する） */
    public function changeStatus(PurchaseOrderStatusRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $result = $this->service->changeStatus(self::actor($request), $purchaseOrder->id, $request->target(), $request->version());
        // 発注点割れの通知（#11）は、ここ（トランザクションの確定後）で $result['stock'] を使って送る
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['to'] === PurchaseOrderStatus::Received
                ? "発注 {$result['number']} を入荷済にし、在庫に入庫しました"
                : "発注 {$result['number']} を「{$result['to']->label()}」にしました",
        ]);

        return to_route('purchase-orders.show', $purchaseOrder);
    }

    /**
     * ステータスの選択肢（値 → 表示名）
     *
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        $statuses = [];
        foreach (PurchaseOrderStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $statuses;
    }

    /**
     * 入力の上限と税率（画面の補助。検証・金額の計算はサーバー側で行う）
     *
     * @return array<string, int>
     */
    private static function limits(): array
    {
        return [
            'lines' => OrderValidation::MAX_LINES,
            'quantity' => OrderValidation::MAX_QUANTITY,
            'unitPrice' => OrderValidation::MAX_UNIT_PRICE,
            'note' => OrderValidation::MAX_NOTE,
            'total' => OrderAmounts::MAX_TOTAL,
            'taxRatePercent' => Tax::RATE_PERCENT,
        ];
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
