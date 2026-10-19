<?php

namespace App\Policies;

use App\Auth\Permission;
use App\Models\Product;
use App\Models\User;

/**
 * 商品の操作の権限（docs/spec.md §2）。判定は権限表（App\Auth\Permission）を使う。
 * ルートの can:（Gate のアビリティ）に加えて、コントローラー・Form Request でこの Policy を検査する（二重の確認）
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::View);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MasterEdit);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::MasterEdit);
    }

    /** 無効化・再有効化（admin のみ） */
    public function changeActive(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::MasterDeactivate);
    }

    /** 在庫の棚卸調整（admin・staff） */
    public function adjustStock(User $user, Product $product): bool
    {
        return $this->allows($user, Permission::StockAdjust);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, Permission::CsvExport);
    }

    public function import(User $user): bool
    {
        return $this->allows($user, Permission::CsvImport);
    }

    private function allows(User $user, Permission $permission): bool
    {
        return $user->is_active && $permission->allows($user->role);
    }
}
