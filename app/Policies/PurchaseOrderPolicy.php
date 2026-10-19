<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * 発注の操作の権限（docs/spec.md §2）。判定は権限表（App\Auth\Permission）を使う。
 * ルートの can:（Gate のアビリティ）に加えて、コントローラー・Form Request でこの Policy を検査する（二重の確認）。
 * 下書きだけ編集できる・遷移できる状態か、は権限ではなく業務の規則なので、サービス（PurchaseOrderService）で検査する
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::View);
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->allows($user, Permission::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::OrderEdit);
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $this->allows($user, Permission::OrderEdit);
    }

    /** ステータス変更（発注済・入荷済・取消） */
    public function changeStatus(User $user, PurchaseOrder $order): bool
    {
        return $this->allows($user, Permission::OrderEdit);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Permission::CsvExport);
    }

    private function allows(User $user, Permission $permission): bool
    {
        return $user->is_active && $permission->allows($user->role);
    }
}
