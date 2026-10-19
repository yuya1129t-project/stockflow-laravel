import { formatNumber, productListQuery } from '@/lib/products';
import type {
    StockFilters,
    StockLevelFilter,
    StockMovement,
    StockMovementType,
} from '@/types/stock';

export const STOCK_LEVEL_LABELS: Record<StockLevelFilter, string> = {
    all: 'すべて',
    low: '発注点割れのみ',
};

export const STOCK_MOVEMENT_TYPE_LABELS: Record<StockMovementType, string> = {
    in: '入庫',
    out: '出庫',
    adjust: '棚卸調整',
};

const REF_LABELS: Record<NonNullable<StockMovement['ref']>['type'], string> = {
    sales_order: '受注',
    purchase_order: '発注',
};

/** 検索条件を URL のクエリにする（商品一覧と同じ項目＋発注点割れのみ。既定値の項目は省く） */
export function stockListQuery(
    filters: Partial<StockFilters>,
): Record<string, string> {
    const { level, ...rest } = filters;
    const query = productListQuery(rest);
    if (level === 'low') query.level = 'low';
    return query;
}

/** 符号付きの数（+3・-2・0） */
export function formatSignedNumber(value: number): string {
    return value > 0 ? `+${formatNumber(value)}` : formatNumber(value);
}

/** 参照元の伝票の表示（伝票の画面はまだないのでテキスト） */
export function formatStockRef(ref: StockMovement['ref']): string {
    if (ref === null) return '—';
    return `${REF_LABELS[ref.type]} ${ref.number ?? `#${ref.id}`}`;
}
