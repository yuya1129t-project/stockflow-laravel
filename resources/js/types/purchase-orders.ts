/** 発注のステータス（App\Enums\PurchaseOrderStatus） */
export type PurchaseOrderStatus =
    | 'draft'
    | 'ordered'
    | 'received'
    | 'cancelled';

export type PurchaseOrderSortKey =
    | 'orderDate'
    | 'number'
    | 'supplier'
    | 'expectedDate'
    | 'total';

/** 一覧の検索条件（URL のクエリ。App\PurchaseOrders\PurchaseOrderListParams） */
export type PurchaseOrderFilters = {
    q: string;
    /** 仕入先の ID（空ならすべて） */
    supplier: string;
    /** ステータス（空ならすべて） */
    status: PurchaseOrderStatus | '';
    /** 発注日の範囲（YYYY-MM-DD。空なら制限なし） */
    from: string;
    to: string;
    sort: PurchaseOrderSortKey;
    order: 'asc' | 'desc';
    page: number;
};

/** 一覧の 1 件（App\PurchaseOrders\PurchaseOrderQuery::paginate） */
export type PurchaseOrderListItem = {
    id: number;
    number: string;
    order_date: string;
    expected_date: string | null;
    status: PurchaseOrderStatus;
    status_label: string;
    supplier: { id: number; code: string; name: string };
    line_count: number;
    subtotal: number;
    tax: number;
    total: number;
    note: string | null;
};

export type PurchaseOrderLine = {
    id: number;
    product_id: number;
    product_code: string;
    product_name: string;
    unit: string;
    product_is_active: boolean;
    quantity: number;
    unit_price: number;
    amount: number;
};

/** 詳細（App\PurchaseOrders\PurchaseOrderQuery::detail） */
export type PurchaseOrderDetail = {
    id: number;
    number: string;
    order_date: string;
    expected_date: string | null;
    status: PurchaseOrderStatus;
    status_label: string;
    note: string | null;
    subtotal: number;
    tax: number;
    total: number;
    /** 画面を開いた時点の updated_at（編集・ステータス変更の競合検出に送る） */
    version: string;
    created_at: string | null;
    updated_at: string | null;
    supplier: { id: number; code: string; name: string; is_active: boolean };
    lines: PurchaseOrderLine[];
};

/** 仕入先の選択肢 */
export type SupplierOption = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};
