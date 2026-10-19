<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\User;

/**
 * ユーザー管理・API トークンの権限（docs/spec.md §2。admin のみ）。判定は権限表（App\Auth\Permission）を使う。
 * ルートの can:（Gate のアビリティ）に加えて、コントローラー・Form Request でこの Policy を検査する（二重の確認）。
 * 自分自身・デモ用アカウント・最後の admin の扱いは App\Users\UserService で検査する（理由のメッセージを返すため）
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function view(User $user, User $target): bool
    {
        return $this->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user);
    }

    public function changeRole(User $user, User $target): bool
    {
        return $this->allows($user);
    }

    /** 無効化・再有効化 */
    public function setActive(User $user, User $target): bool
    {
        return $this->allows($user);
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $this->allows($user);
    }

    /** API トークンの発行・失効 */
    public function manageTokens(User $user, User $target): bool
    {
        return $this->allows($user);
    }

    private function allows(User $user): bool
    {
        return $user->is_active && Permission::UserManage->allows($user->role);
    }
}
