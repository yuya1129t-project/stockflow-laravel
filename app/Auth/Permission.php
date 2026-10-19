<?php

namespace App\Auth;

use App\Enums\Role;

/**
 * ロール × 操作の権限表（docs/spec.md §2）。権限の定義はこのファイルだけに置く。
 *
 * - 値は Gate のアビリティ名（AppServiceProvider で Gate::define する）。
 *   ルートは `->can(Permission::MasterEdit)`（middleware `can:master.edit`）、コントローラーは
 *   `Gate::authorize(Permission::MasterEdit)` で検査する
 * - label() は spec §2 の表の「機能」列と同じ文言。テスト（tests/Unit/Auth/PermissionTest.php）で spec の表と突き合わせる
 * - 画面の表示切り替え（メニューやボタンを隠す）にも使うが、それは補助。権限は必ずサーバー側で検査する
 */
enum Permission: string
{
    case View = 'view';
    case MasterEdit = 'master.edit';
    case MasterDeactivate = 'master.deactivate';
    case OrderEdit = 'order.edit';
    case StockAdjust = 'stock.adjust';
    case CsvExport = 'csv.export';
    case CsvImport = 'csv.import';
    case UserManage = 'user.manage';
    case AuditView = 'audit.view';

    /** spec §2 の表の「機能」列 */
    public function label(): string
    {
        return match ($this) {
            self::View => 'ダッシュボード・各一覧・詳細の閲覧',
            self::MasterEdit => '商品・取引先の登録・編集',
            self::MasterDeactivate => '商品・取引先の無効化（論理削除）',
            self::OrderEdit => '受注・発注の作成・編集・ステータス変更',
            self::StockAdjust => '在庫の棚卸調整',
            self::CsvExport => 'CSV 出力',
            self::CsvImport => 'CSV 取込（商品）',
            self::UserManage => 'ユーザー管理',
            self::AuditView => '操作履歴の閲覧',
        };
    }

    /**
     * この操作を許可するロール（spec §2 の表の ○）
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        return match ($this) {
            self::View, self::CsvExport => [Role::Admin, Role::Staff, Role::Viewer],
            self::MasterEdit, self::OrderEdit, self::StockAdjust, self::CsvImport => [Role::Admin, Role::Staff],
            self::MasterDeactivate, self::UserManage, self::AuditView => [Role::Admin],
        };
    }

    /** role がこの操作を実行できるか */
    public function allows(Role $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    /**
     * role に許可された操作の一覧（画面に渡して表示の切り替えに使う）
     *
     * @return list<self>
     */
    public static function grantedTo(Role $role): array
    {
        return array_values(array_filter(self::cases(), fn (self $permission) => $permission->allows($role)));
    }
}
