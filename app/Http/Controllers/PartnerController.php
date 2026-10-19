<?php

namespace App\Http\Controllers;

use App\Enums\PartnerKind;
use App\Http\Requests\PartnerRequest;
use App\Models\Partner;
use App\Models\User;
use App\Services\Partners\AddressLookup;
use App\Services\Partners\PartnerService;
use App\Support\Partners\PartnerListFilters;
use App\Support\Partners\PostalCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 取引先マスタ（docs/spec.md §5-4）。権限はルートの ->can(Permission::...) と、ここ（Policy）で二重に検査する
 */
class PartnerController extends Controller
{
    /** 住所検索の回数制限（ユーザーごと。1 分に 30 回まで） */
    public const int ADDRESS_LOOKUP_LIMIT = 30;

    public const int ADDRESS_LOOKUP_WINDOW_SECONDS = 60;

    public const string ADDRESS_LOOKUP_RATE_LIMIT_MESSAGE = '住所の検索回数が多すぎます。しばらくしてからもう一度お試しいただくか、住所を手入力してください';

    public function __construct(private readonly PartnerService $partners) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Partner::class);

        $filters = PartnerListFilters::fromRequest($request);
        $paginator = $filters->query()->paginate(PartnerListFilters::PER_PAGE)->withQueryString();
        // 範囲外のページは最終ページに丸める
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $filters->query()->paginate(PartnerListFilters::PER_PAGE, page: $paginator->lastPage())->withQueryString();
        }

        return Inertia::render('partners/Index', [
            'partners' => [
                'data' => array_map(self::toProps(...), $paginator->items()),
                'meta' => [
                    'page' => $paginator->currentPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'lastPage' => $paginator->lastPage(),
                ],
            ],
            'filters' => $filters->toArray(),
            'kindOptions' => self::kindOptions(),
            'can' => self::abilities($request),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Partner::class);

        return Inertia::render('partners/Create', [
            'kindOptions' => self::kindOptions(),
        ]);
    }

    public function store(PartnerRequest $request): RedirectResponse
    {
        $partner = $this->partners->create($this->actor($request), $request->partnerData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "取引先 {$partner->code} を登録しました"]);

        return to_route('partners.show', $partner);
    }

    public function show(Request $request, Partner $partner): Response
    {
        Gate::authorize('view', $partner);

        $partner->loadCount(['salesOrders', 'purchaseOrders']);

        return Inertia::render('partners/Show', [
            'partner' => [
                ...self::toProps($partner),
                'salesOrdersCount' => (int) $partner->getAttribute('sales_orders_count'),
                'purchaseOrdersCount' => (int) $partner->getAttribute('purchase_orders_count'),
            ],
            'can' => self::abilities($request),
        ]);
    }

    public function edit(Partner $partner): Response
    {
        Gate::authorize('update', $partner);

        return Inertia::render('partners/Edit', [
            'partner' => self::toProps($partner),
            'kindOptions' => self::kindOptions(),
        ]);
    }

    public function update(PartnerRequest $request, Partner $partner): RedirectResponse
    {
        $changed = $this->partners->update($this->actor($request), $partner, $request->partnerData());

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => "取引先 {$partner->code} を更新しました"]
            : ['type' => 'info', 'message' => '変更はありませんでした']);

        return to_route('partners.show', $partner);
    }

    public function deactivate(Request $request, Partner $partner): RedirectResponse
    {
        return $this->setActive($request, $partner, false);
    }

    public function reactivate(Request $request, Partner $partner): RedirectResponse
    {
        return $this->setActive($request, $partner, true);
    }

    /**
     * 郵便番号から住所を検索する（GET /partners/address-lookup?postal_code=1234567。spec §6）。
     * 読み取りだけで副作用はない。登録・編集の画面で使うので、権限は master.edit（admin・staff）。
     * 応答: 200 { postalCode, addresses } ／ 400 形式エラー ／ 404 該当なし ／ 429 回数超過 ／ 502 失敗・不正な応答 ／ 504 タイムアウト。
     * 失敗時の本文は { message: 日本語のメッセージ（「住所を手入力してください」を含む）, reason }
     */
    public function addressLookup(Request $request, AddressLookup $lookup): JsonResponse
    {
        $user = $this->actor($request);
        Gate::authorize('lookupAddress', Partner::class);
        $headers = ['Cache-Control' => 'no-store'];

        $key = "address-lookup:user:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::ADDRESS_LOOKUP_LIMIT)) {
            return response()->json(
                ['message' => self::ADDRESS_LOOKUP_RATE_LIMIT_MESSAGE, 'reason' => 'rate_limited'],
                429,
                [...$headers, 'Retry-After' => (string) max(1, RateLimiter::availableIn($key))],
            );
        }
        RateLimiter::hit($key, self::ADDRESS_LOOKUP_WINDOW_SECONDS);

        $input = $request->query('postal_code');
        $result = $lookup->lookup(is_string($input) ? mb_substr($input, 0, 20) : '');

        if ($result->failure !== null) {
            return response()->json(
                ['message' => $result->failure->message(), 'reason' => $result->failure->value],
                $result->failure->httpStatus(),
                $headers,
            );
        }

        return response()->json(['postalCode' => $result->postalCode, 'addresses' => $result->addresses], 200, $headers);
    }

    private function setActive(Request $request, Partner $partner, bool $isActive): RedirectResponse
    {
        Gate::authorize('setActive', $partner);

        $changed = $this->partners->setActive($this->actor($request), $partner, $isActive);
        $message = $isActive ? "取引先 {$partner->code} を再度有効にしました" : "取引先 {$partner->code} を無効にしました";
        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'info', 'message' => $isActive ? 'すでに有効です' : 'すでに無効です']);

        return to_route('partners.show', $partner);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string, mixed> 画面に渡す取引先（郵便番号は 123-4567 の形） */
    private static function toProps(Partner $partner): array
    {
        return [
            'id' => $partner->id,
            'code' => $partner->code,
            'name' => $partner->name,
            'kind' => $partner->kind->value,
            'kindLabel' => $partner->kind->label(),
            'postalCode' => PostalCode::format($partner->postal_code),
            'address' => $partner->address ?? '',
            'phone' => $partner->phone ?? '',
            'email' => $partner->email ?? '',
            'isActive' => $partner->is_active,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function kindOptions(): array
    {
        return array_map(fn (PartnerKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], PartnerKind::cases());
    }

    /** @return array{create: bool, update: bool, setActive: bool} 画面のボタンの表示の切り替え用（検査はサーバー側で行う） */
    private static function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'create' => $user?->can('create', Partner::class) === true,
            'update' => $user?->can('update', new Partner) === true,
            'setActive' => $user?->can('setActive', new Partner) === true,
        ];
    }
}
