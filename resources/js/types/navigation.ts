import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** この画面を開く権限（App\Auth\Permission の値）。権限のないロールにはメニューを出さない */
    permission?: string;
    /** false の画面は未実装（リンクにせず「準備中」と表示する） */
    ready?: boolean;
};
