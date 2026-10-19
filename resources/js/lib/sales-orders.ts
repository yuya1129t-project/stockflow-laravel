import type {
    SalesOrderFilters,
    SalesOrderSortKey,
    SalesOrderStatus,
} from '@/types/sales-orders';

export const SALES_ORDER_STATUS_LABELS: Record<SalesOrderStatus, string> = {
    draft: '下書き',
    confirmed: '確定',
    shipped: '出荷済',
    cancelled: '取消',
};

/** 遷移の操作名（ボタン・確認ダイアログ） */
export const SALES_ORDER_TRANSITION_LABELS: Record<
    Exclude<SalesOrderStatus, 'draft'>,
    { action: string; question: string; description: string }
> = {
    confirmed: {
        action: '確定する',
        question: '確定しますか？',
        description:
            '確定すると明細は編集できなくなります。出荷したら「出荷済にする」で在庫から出庫します。',
    },
    shipped: {
        action: '出荷済にする',
        question: '出荷済にしますか？',
        description:
            '全明細の数量を在庫から出庫として記録します。1 品目でも在庫が足りなければ出荷しません。出荷済は最終状態で、元に戻せません。',
    },
    cancelled: {
        action: '取り消す',
        question: '取り消しますか？',
        description: '取消は最終状態で、元に戻せません。在庫は変わりません。',
    },
};

export const DEFAULT_SALES_ORDER_FILTERS: SalesOrderFilters = {
    q: '',
    customer: '',
    status: '',
    from: '',
    to: '',
    sort: 'orderDate',
    order: 'desc',
    page: 1,
};

/** 検索条件を URL のクエリにする（既定値の項目は省く） */
export function salesOrderListQuery(
    filters: Partial<SalesOrderFilters>,
): Record<string, string> {
    const merged = { ...DEFAULT_SALES_ORDER_FILTERS, ...filters };
    const query: Record<string, string> = {};
    if (merged.q) query.q = merged.q;
    if (merged.customer) query.customer = merged.customer;
    if (merged.status) query.status = merged.status;
    if (merged.from) query.from = merged.from;
    if (merged.to) query.to = merged.to;
    if (
        merged.sort !== DEFAULT_SALES_ORDER_FILTERS.sort ||
        merged.order !== DEFAULT_SALES_ORDER_FILTERS.order
    ) {
        query.sort = merged.sort;
        query.order = merged.order;
    }
    if (merged.page > 1) query.page = String(merged.page);
    return query;
}

/** 並べ替えの列の見出しを押したときの条件（同じ列なら昇順 ↔ 降順。日付・金額は降順から） */
export function toggleSalesOrderSort(
    filters: SalesOrderFilters,
    sort: SalesOrderSortKey,
): Partial<SalesOrderFilters> {
    const active = filters.sort === sort;
    const initial = sort === 'number' || sort === 'customer' ? 'asc' : 'desc';
    return {
        ...filters,
        sort,
        order: active ? (filters.order === 'asc' ? 'desc' : 'asc') : initial,
        page: 1,
    };
}

/** 日付の表示（発注と共通） */
export { displayDate } from '@/lib/purchase-orders';
