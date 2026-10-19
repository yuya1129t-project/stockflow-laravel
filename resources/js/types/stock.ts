import type { ProductFilters } from '@/types/products';

/** 在庫の絞り込み（すべて・発注点割れのみ） */
export type StockLevelFilter = 'all' | 'low';

/** 現在庫一覧の検索条件（URL のクエリ。App\Stock\StockListParams） */
export type StockFilters = ProductFilters & {
    level: StockLevelFilter;
};

export type StockMovementType = 'in' | 'out' | 'adjust';

/** 入出庫履歴の 1 件（App\Stock\StockQuery::movements） */
export type StockMovement = {
    id: number;
    /** 記録した日時（Asia/Tokyo。YYYY-MM-DD HH:mm） */
    created_at: string | null;
    type: StockMovementType;
    /** 符号付き */
    quantity: number;
    reason: string | null;
    /** 参照元の伝票（受注・発注）。棚卸調整は null。伝票が見つからなければ number は null */
    ref: {
        type: 'sales_order' | 'purchase_order';
        id: number;
        number: string | null;
    } | null;
    /** 記録したユーザーの名前 */
    user_name: string | null;
};

/** 棚卸調整の入力の上限（画面の補助。検証はサーバー側） */
export type StockAdjustLimits = {
    actualQuantity: number;
    reason: number;
};
