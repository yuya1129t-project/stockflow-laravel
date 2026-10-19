<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\IssueApiTokenRequest;
use App\Models\User;
use App\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * ユーザーごとの API トークンの発行・失効（docs/spec.md §5-8・§6-2。admin のみ）。
 *
 * 発行は JSON で平文のトークンを 1 回だけ返す（画面はこの応答を表示するだけで、どこにも保存しない）。
 * 画面遷移（リダイレクト）でフラッシュに載せると、平文がセッション（sessions テーブル）に保存されるため使わない
 */
class UserApiTokenController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /** 発行。応答 201 { id, name, token }（token は平文。この応答でしか返さない）。検証エラーは 422 */
    public function store(IssueApiTokenRequest $request, User $user): JsonResponse
    {
        $name = $request->tokenName();
        $token = $this->users->issueToken($this->actor($request), $user, $name);

        return response()->json(
            ['id' => $token->accessToken->getKey(), 'name' => $name, 'token' => $token->plainTextToken],
            201,
            ['Cache-Control' => 'no-store'],
        );
    }

    /** 失効 */
    public function revoke(Request $request, User $user, string $token): RedirectResponse
    {
        Gate::authorize('manageTokens', $user);

        // ルートで数字だけに絞っている。PHP の整数に収まらない値は「見つかりません」
        $tokenId = filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tokenId === false) {
            throw ValidationException::withMessages(['form' => UserService::TOKEN_NOT_FOUND_MESSAGE]);
        }
        $changed = $this->users->revokeToken($this->actor($request), $user, $tokenId);

        Inertia::flash('toast', $changed
            ? ['type' => 'success', 'message' => 'API トークンを失効させました']
            : ['type' => 'info', 'message' => 'すでに失効しています']);

        return to_route('users.show', $user);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
