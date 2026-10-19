<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\V1\ApiJsonBody;
use App\Api\V1\ApiQuery;
use App\Api\V1\ApiResponse;
use App\Api\V1\ApiSerializer;
use App\Api\V1\SalesOrderApi;
use App\Api\V1\SalesOrderCreateBody;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\SalesOrder;
use App\Models\User;
use App\SalesOrders\SalesOrderListParams;
use App\SalesOrders\SalesOrderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST API の受注（一覧・詳細・登録。docs/openapi.json）。認証は Bearer トークン。
 * 権限は、参照は閲覧、登録は受注の作成（ルートの can: と、ここの Policy で二重に検査する）
 */
class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderQuery $orders) {}

    /** 受注一覧。受注日の新しい順（同じ日は受注番号の降順） */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $query = ApiQuery::fromRequest($request);
        $status = $query->oneOf('status', array_map(fn (SalesOrderStatus $s) => $s->value, SalesOrderStatus::cases()));
        $from = $query->date('from');
        $to = $query->date('to');
        if ($from !== '' && $to !== '' && $from > $to) {
            $query->errors[] = ['parameter' => 'to', 'detail' => 'to は from 以降の日付で指定してください'];
        }
        ['page' => $page, 'perPage' => $perPage] = $query->paging();
        $query->throwIfInvalid();

        $params = new SalesOrderListParams(
            status: SalesOrderStatus::tryFrom($status),
            from: $from,
            to: $to,
            // 「受注番号」の並び: 受注日 → 番号（桁数 → 文字列）。SalesOrderQuery::sorted
            sort: 'number',
            order: 'desc',
            page: $page,
        );
        $result = $this->orders->paginate($params, perPage: $perPage, clampPage: false);

        return ApiResponse::json([
            'data' => array_map(ApiSerializer::salesOrderSummary(...), $result['items']),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $result['total']],
        ]);
    }

    /** 受注詳細（明細を含む。受注番号の大文字・小文字は区別しない） */
    public function show(string $number): JsonResponse
    {
        Gate::authorize('viewAny', SalesOrder::class);

        $number = strtoupper(ApiQuery::pathParameter('number', $number));
        $order = SalesOrder::query()->where('number', $number)->first();
        if ($order === null || strtoupper($order->number) !== $number) {
            return ApiResponse::problem(404, "受注「{$number}」が見つかりません");
        }

        return ApiResponse::json(ApiSerializer::salesOrder($order, $this->orders->detail($order)));
    }

    /**
     * 受注の登録（draft）。認証・認可の後に本文を読む（認証されていないリクエストの本文は読まない）。
     * 画面と同じ検証・サービスで作成する（SalesOrderApi）
     */
    public function store(Request $request, SalesOrderApi $api): JsonResponse
    {
        Gate::authorize('create', SalesOrder::class);
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        assert($user instanceof User && $token instanceof ApiToken);

        $parsed = SalesOrderCreateBody::parse(ApiJsonBody::read($request));
        if ($parsed['errors'] !== null) {
            return ApiResponse::validation($parsed['errors']);
        }
        $created = $api->create($user, $token->id, $parsed['input']);
        if ($created instanceof JsonResponse) {
            return $created;
        }

        return ApiResponse::json(
            ApiSerializer::salesOrder($created->refresh(), $this->orders->detail($created)),
            201,
            ['Location' => '/api/v1/sales-orders/'.rawurlencode($created->number)],
        );
    }
}
