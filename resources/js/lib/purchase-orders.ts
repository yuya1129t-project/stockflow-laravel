import type {
    PurchaseOrderFilters,
    PurchaseOrderSortKey,
    PurchaseOrderStatus,
} from '@/types/purchase-orders';

export const PURCHASE_ORDER_STATUS_LABELS: Record<PurchaseOrderStatus, string> =
    {
        draft: '下書き',
        ordered: '発注済',
        received: '入荷済',
        cancelled: '取消',
    };

/** 遷移の操作名（ボタン・確認ダイアログ） */
export const PURCHASE_ORDER_TRANSITION_LABELS: Record<
    Exclude<PurchaseOrderStatus, 'draft'>,
    { action: string; question: string; description: string }
> = {
    ordered: {
        action: '発注済にする',
        question: '発注済にしますか？',
        description:
            '発注済にすると明細は編集できなくなります。入荷したら「入荷済にする」で在庫に加えます。',
    },
    received: {
        action: '入荷済にする',
        question: '入荷済にしますか？',
        description:
            '全明細の数量を在庫に入庫として記録します。入荷済は最終状態で、元に戻せません。',
    },
    cancelled: {
        action: '取り消す',
        question: '取り消しますか？',
        description: '取消は最終状態で、元に戻せません。在庫は変わりません。',
    },
};

export const DEFAULT_PURCHASE_ORDER_FILTERS: PurchaseOrderFilters = {
    q: '',
    supplier: '',
    status: '',
    from: '',
    to: '',
    sort: 'orderDate',
    order: 'desc',
    page: 1,
};

/** 検索条件を URL のクエリにする（既定値の項目は省く） */
export function purchaseOrderListQuery(
    filters: Partial<PurchaseOrderFilters>,
): Record<string, string> {
    const merged = { ...DEFAULT_PURCHASE_ORDER_FILTERS, ...filters };
    const query: Record<string, string> = {};
    if (merged.q) query.q = merged.q;
    if (merged.supplier) query.supplier = merged.supplier;
    if (merged.status) query.status = merged.status;
    if (merged.from) query.from = merged.from;
    if (merged.to) query.to = merged.to;
    if (
        merged.sort !== DEFAULT_PURCHASE_ORDER_FILTERS.sort ||
        merged.order !== DEFAULT_PURCHASE_ORDER_FILTERS.order
    ) {
        query.sort = merged.sort;
        query.order = merged.order;
    }
    if (merged.page > 1) query.page = String(merged.page);
    return query;
}

/** 並べ替えの列の見出しを押したときの条件（同じ列なら昇順 ↔ 降順。日付・金額は降順から） */
export function togglePurchaseOrderSort(
    filters: PurchaseOrderFilters,
    sort: PurchaseOrderSortKey,
): Partial<PurchaseOrderFilters> {
    const active = filters.sort === sort;
    const initial = sort === 'number' || sort === 'supplier' ? 'asc' : 'desc';
    return {
        ...filters,
        sort,
        order: active ? (filters.order === 'asc' ? 'desc' : 'asc') : initial,
        page: 1,
    };
}

/** 日付（YYYY-MM-DD）の表示（例 2026/10/19）。null は「—」 */
export function displayDate(date: string | null): string {
    return date ? date.replaceAll('-', '/') : '—';
}
