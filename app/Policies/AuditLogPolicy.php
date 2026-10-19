<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\User;

/** 操作履歴の閲覧の権限（docs/spec.md §2。admin のみ）。ルートの can: に加えて、コントローラーでこの Policy を検査する */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && Permission::AuditView->allows($user->role);
    }
}
