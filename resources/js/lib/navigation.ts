import {
    Building2,
    ClipboardList,
    History,
    LayoutGrid,
    Package,
    Truck,
    Users,
    Warehouse,
} from '@lucide/vue';
import { dashboard } from '@/routes';
import { index as partnersIndex } from '@/routes/partners';
import { index as productsIndex } from '@/routes/products';
import type { NavItem } from '@/types';

/**
 * サイドバーの項目（docs/spec.md §5 の画面）。
 * ready: false の画面は未実装（リンクにせず「準備中」と表示する）。permission はその画面を開く権限で、
 * 権限のないロールにはメニュー自体を出さない（表示の補助。検査はサーバー側のルート・コントローラーで行う）
 */
export const mainNavItems: NavItem[] = [
    {
        title: 'ダッシュボード',
        href: dashboard(),
        icon: LayoutGrid,
        permission: 'view',
        ready: true,
    },
    {
        title: '商品',
        href: productsIndex(),
        icon: Package,
        permission: 'view',
        ready: true,
    },
    {
        title: '取引先',
        href: partnersIndex(),
        icon: Building2,
        permission: 'view',
        ready: true,
    },
    {
        title: '受注',
        href: '/sales-orders',
        icon: ClipboardList,
        permission: 'view',
        ready: false,
    },
    {
        title: '発注',
        href: '/purchase-orders',
        icon: Truck,
        permission: 'view',
        ready: false,
    },
    {
        title: '在庫',
        href: '/stock',
        icon: Warehouse,
        permission: 'view',
        ready: false,
    },
    {
        title: 'ユーザー管理',
        href: '/users',
        icon: Users,
        permission: 'user.manage',
        ready: false,
    },
    {
        title: '操作履歴',
        href: '/audit-logs',
        icon: History,
        permission: 'audit.view',
        ready: false,
    },
];

/** permissions（ログイン中のユーザーに許可された操作）で見せてよい項目だけにする */
export function visibleNavItems(
    items: NavItem[],
    permissions: string[],
): NavItem[] {
    return items.filter(
        (item) =>
            item.permission === undefined ||
            permissions.includes(item.permission),
    );
}
