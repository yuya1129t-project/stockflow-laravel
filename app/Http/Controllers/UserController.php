<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\Users\ResetPasswordRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UserRoleRequest;
use App\Models\ApiToken;
use App\Models\User;
use App\Users\DemoAccounts;
use App\Users\UserService;
use App\Users\UserValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ユーザー管理（docs/spec.md §5-8。admin のみ）。権限はルートの ->can(Permission::UserManage) と、ここ（Policy）で二重に検査する。
 * API トークンの発行・失効は UserApiTokenController
 */
class UserController extends Controller
{
    public const int PER_PAGE = 50;

    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $paginator = User::query()->orderBy('id')->paginate(self::PER_PAGE)->withQueryString();
        // 範囲外のページは最終ページに丸める
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = User::query()->orderBy('id')->paginate(self::PER_PAGE, page: $paginator->lastPage())->withQueryString();
        }

        return Inertia::render('users/Index', [
            'users' => array_map(fn (User $u) => self::toProps($u, $request), $paginator->items()),
            'pagination' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'totalPages' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/Create', [
            'roleOptions' => self::roleOptions(),
            'limits' => UserValidation::MAX,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($this->actor($request), $request->userData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "ユーザー「{$user->name}」を登録しました"]);

        return to_route('users.show', $user);
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        // 有効なトークンを新しい順に、そのあと失効したものを失効の新しい順に。トークン本体（ハッシュ）は渡さない
        $tokens = $user->tokens()
            ->orderByRaw('revoked_at IS NOT NULL')
            ->orderByDesc('revoked_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'createdAt' => $t->created_at?->format('Y-m-d H:i') ?? '',
                'lastUsedAt' => $t->last_used_at?->format('Y-m-d H:i'),
                'revokedAt' => $t->revoked_at?->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();

        return Inertia::render('users/Show', [
            'user' => self::toProps($user, $request),
            'tokens' => $tokens,
            'roleOptions' => self::roleOptions(),
            'maxActiveTokens' => UserService::MAX_ACTIVE_TOKENS_PER_USER,
            'limits' => UserValidation::MAX,
        ]);
    }

    public function updateRole(UserRoleRequest $request, User $user): RedirectResponse
    {
        $role = $request->role();
        $changed = $this->users->changeRole($this->actor($request), $user, $role);

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => "「{$user->name}」のロールを{$role->label()}に変更しました"]
            : ['type' => 'info', 'message' => '変更はありませんでした']);

        return to_route('users.show', $user);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        return $this->setActive($request, $user, false);
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        return $this->setActive($request, $user, true);
    }

    /**
     * パスワード再設定。自分自身の再設定ではセッションをすべて削除しているので、ログアウトさせてログイン画面へ移す
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): RedirectResponse
    {
        $actor = $this->actor($request);
        $result = $this->users->resetPassword($actor, $user, $request->password(), $request->revokeTokens());

        if ($user->id === $actor->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', 'パスワードを再設定しました。新しいパスワードでログインしてください');
        }

        $detail = "ログイン中のセッション {$result['sessions']} 件を終了".($request->revokeTokens() ? "、API トークン {$result['tokens']} 件を失効" : '');
        Inertia::flash('toast', ['type' => 'success', 'message' => "「{$user->name}」のパスワードを再設定しました（{$detail}）"]);

        return to_route('users.show', $user);
    }

    private function setActive(Request $request, User $user, bool $active): RedirectResponse
    {
        Gate::authorize('setActive', $user);

        $result = $this->users->setActive($this->actor($request), $user, $active);
        $message = $active
            ? "「{$user->name}」を再度有効にしました"
            : "「{$user->name}」を無効にしました（ログイン中のセッション {$result['sessions']} 件を終了、API トークン {$result['tokens']} 件を失効）";
        Inertia::flash('toast', $result['changed']
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'info', 'message' => $active ? 'すでに有効です' : 'すでに無効です']);

        return to_route('users.show', $user);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string, mixed> 画面に渡すユーザー（パスワード・remember_token は渡さない） */
    private static function toProps(User $user, Request $request): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'roleLabel' => $user->role->label(),
            'isActive' => $user->is_active,
            'createdAt' => $user->created_at?->format('Y-m-d H:i') ?? '',
            // デモ用アカウント（無効化・ロール変更・パスワード再設定はできない）
            'isDemo' => DemoAccounts::isDemo($user),
            'isSelf' => $request->user()?->getAuthIdentifier() === $user->id,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function roleOptions(): array
    {
        return array_map(fn (Role $role) => ['value' => $role->value, 'label' => $role->label()], Role::cases());
    }
}
