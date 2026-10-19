<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Http\Requests\SalesOrderRequest;
use App\Http\Requests\SalesOrderStatusRequest;
use App\Models\SalesOrder;
use App\Models\User;
use App\Orders\OrderAmounts;
use App\Orders\OrderValidation;
use App\ReorderNotifications\ReorderPointNotifier;
use App\ReorderNotifications\ReorderPointTrigger;
use App\SalesOrders\SalesOrderListParams;
use App\SalesOrders\SalesOrderQuery;
use App\SalesOrders\SalesOrderService;
use App\Stock\InsufficientStockException;
use App\Stock\StockShortage;
use App\Support\Tax;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 受注の画面（一覧・作成・詳細・編集・ステータス変更。docs/spec.md §4・§5-5）。発注（PurchaseOrderController）と同じ作り。
 * 閲覧は全ロール、作成・編集・ステータス変更は admin・staff（ルートの can: と、ここ・Form Request の Policy で二重に検査する）
 */
class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderQuery $orders,
        private readonly SalesOrderService $service,
    ) {}

    /** 一覧（番号・顧客・ステータス・期間で絞り込み、並べ替え、ページ送り。条件は URL のクエリ） */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $params = SalesOrderListParams::fromQuery($request->query());
        $result = $this->orders->paginate($params);

        return Inertia::render('sales-orders/Index', [
            'orders' => $result['items'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'perPage' => $result['perPage'],
            ],
            'filters' => $params->withPage($result['page'])->toArray(),
            'customers' => $this->orders->customerFilterOptions(),
            'statuses' => self::statuses(),
            'exportMaxRows' => SalesOrderQuery::EXPORT_MAX_ROWS,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SalesOrder::class);

        return Inertia::render('sales-orders/Create', [
            'customers' => $this->orders->customerOptions(),
            'products' => $this->orders->productOptions(),
            'limits' => self::limits(),
        ]);
    }

    public function store(SalesOrderRequest $request): RedirectResponse
    {
        $order = $this->service->create(self::actor($request), $request->salesOrderInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => "受注 {$order->number} を作成しました"]);

        return to_route('sales-orders.show', $order);
    }

    public function show(SalesOrder $salesOrder): Response
    {
        Gate::authorize('view', $salesOrder);

        return Inertia::render('sales-orders/Show', [
            'order' => $this->orders->detail($salesOrder),
            'transitions' => array_map(fn (SalesOrderStatus $s) => $s->value, $salesOrder->status->transitions()),
        ]);
    }

    /** 編集（下書きのときだけ。それ以外は詳細へ戻す） */
    public function edit(SalesOrder $salesOrder): Response|RedirectResponse
    {
        Gate::authorize('update', $salesOrder);

        if (! $salesOrder->status->isEditable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "この受注は「{$salesOrder->status->label()}」のため編集できません（編集できるのは下書きだけです）"]);

            return to_route('sales-orders.show', $salesOrder);
        }
        $detail = $this->orders->detail($salesOrder);

        return Inertia::render('sales-orders/Edit', [
            'order' => $detail,
            'customers' => $this->orders->customerOptions($salesOrder->customer_id),
            'products' => $this->orders->productOptions(array_column($detail['lines'], 'product_id')),
            'limits' => self::limits(),
        ]);
    }

    public function update(SalesOrderRequest $request, SalesOrder $salesOrder): RedirectResponse
    {
        $changed = $this->service->update(self::actor($request), $salesOrder->id, $request->salesOrderInput(), $request->version());
        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => "受注 {$salesOrder->number} を保存しました"]
            : ['type' => 'info', 'message' => '変更はありませんでした']);

        return to_route('sales-orders.show', $salesOrder);
    }

    /**
     * ステータス変更（確定・出荷済・取消。出荷は全明細を在庫から出庫する）。
     * 在庫が足りなければ出荷せず、不足する商品と数量を 1 回だけの flash（shortages）で詳細画面に返す（spec §4）
     */
    public function changeStatus(SalesOrderStatusRequest $request, SalesOrder $salesOrder, ReorderPointNotifier $notifier): RedirectResponse
    {
        $actor = self::actor($request);
        try {
            $result = $this->service->changeStatus($actor, $salesOrder->id, $request->target(), $request->version());
        } catch (InsufficientStockException $e) {
            Inertia::flash('shortages', self::shortageRows($e));

            return to_route('sales-orders.show', $salesOrder)->withErrors(['status' => SalesOrderService::shortageMessage($e)]);
        }
        // 発注点割れの通知（spec §6-3）: 出荷（StockTransaction::run）が戻った後＝トランザクションの確定後に、全明細の分を 1 通で投入する
        if ($result['stock'] !== null) {
            $notifier->notify($result['stock']->fellBelowReorderPoint, ReorderPointTrigger::salesOrderShipped($salesOrder->id, $result['number']), $actor->id);
        }
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result['to'] === SalesOrderStatus::Shipped
                ? "受注 {$result['number']} を出荷済にし、在庫から出庫しました"
                : "受注 {$result['number']} を「{$result['to']->label()}」にしました",
        ]);

        return to_route('sales-orders.show', $salesOrder);
    }

    /**
     * 不足する商品の表（商品コードの順）
     *
     * @return list<array{product_id: int, code: string, name: string, unit: string, required: int, available: int, shortage: int}>
     */
    private static function shortageRows(InsufficientStockException $e): array
    {
        $rows = array_map(fn (StockShortage $s) => [
            'product_id' => $s->product->id,
            'code' => $s->product->code,
            'name' => $s->product->name,
            'unit' => $s->product->unit,
            'required' => $s->required,
            'available' => $s->available,
            'shortage' => $s->shortage,
        ], $e->shortages);
        usort($rows, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /**
     * ステータスの選択肢（値 → 表示名）
     *
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        $statuses = [];
        foreach (SalesOrderStatus::cases() as $status) {
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
