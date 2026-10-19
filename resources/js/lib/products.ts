import type {
    ProductFilters,
    ProductSortKey,
    ProductStatusFilter,
} from '@/types/products';

/** 項目名（App\Products\ProductValidation::LABELS と同じ） */
export const PRODUCT_FIELD_LABELS = {
    code: '商品コード',
    name: '商品名',
    category: 'カテゴリ',
    unit: '単位',
    sales_price: '売価',
    purchase_price: '仕入単価',
    reorder_point: '発注点',
} as const;

export const PRODUCT_STATUS_LABELS: Record<ProductStatusFilter, string> = {
    active: '有効のみ',
    all: '無効も表示',
    inactive: '無効のみ',
};

export const DEFAULT_PRODUCT_FILTERS: ProductFilters = {
    q: '',
    category: '',
    status: 'active',
    sort: 'code',
    order: 'asc',
    page: 1,
};

/** 検索条件を URL のクエリにする（既定値の項目は省く） */
export function productListQuery(
    filters: Partial<ProductFilters>,
): Record<string, string> {
    const merged = { ...DEFAULT_PRODUCT_FILTERS, ...filters };
    const query: Record<string, string> = {};
    if (merged.q) query.q = merged.q;
    if (merged.category) query.category = merged.category;
    if (merged.status !== DEFAULT_PRODUCT_FILTERS.status) {
        query.status = merged.status;
    }
    if (
        merged.sort !== DEFAULT_PRODUCT_FILTERS.sort ||
        merged.order !== DEFAULT_PRODUCT_FILTERS.order
    ) {
        query.sort = merged.sort;
        query.order = merged.order;
    }
    if (merged.page > 1) query.page = String(merged.page);
    return query;
}

/** 並べ替えの列の見出しを押したときの条件（同じ列なら昇順 ↔ 降順） */
export function toggleSort(
    filters: ProductFilters,
    sort: ProductSortKey,
): Partial<ProductFilters> {
    const active = filters.sort === sort;
    return {
        ...filters,
        sort,
        order: active && filters.order === 'asc' ? 'desc' : 'asc',
        page: 1,
    };
}

const numberFormat = new Intl.NumberFormat('ja-JP');

export function formatNumber(value: number): string {
    return numberFormat.format(value);
}
