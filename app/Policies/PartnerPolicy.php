<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\Partner;
use App\Models\User;

/**
 * 取引先の操作の権限（docs/spec.md §2）。判定は権限表（App\Auth\Permission）の Gate に任せる。
 * ルートの ->can(Permission::...) と合わせて、コントローラーでも Gate::authorize(...) で二重に検査する
 */
class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::View);
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->can(Permission::View);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MasterEdit);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->can(Permission::MasterEdit);
    }

    /** 無効化・再有効化（admin のみ） */
    public function setActive(User $user, Partner $partner): bool
    {
        return $user->can(Permission::MasterDeactivate);
    }

    /** 郵便番号からの住所検索（登録・編集の画面で使う） */
    public function lookupAddress(User $user): bool
    {
        return $user->can(Permission::MasterEdit);
    }
}
