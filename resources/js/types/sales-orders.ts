/** 受注のステータス（App\Enums\SalesOrderStatus） */
export type SalesOrderStatus = 'draft' | 'confirmed' | 'shipped' | 'cancelled';

export type SalesOrderSortKey =
    | 'orderDate'
    | 'number'
    | 'customer'
    | 'dueDate'
    | 'total';

/** 一覧の検索条件（URL のクエリ。App\SalesOrders\SalesOrderListParams） */
export type SalesOrderFilters = {
    q: string;
    /** 顧客の ID（空ならすべて） */
    customer: string;
    /** ステータス（空ならすべて） */
    status: SalesOrderStatus | '';
    /** 受注日の範囲（YYYY-MM-DD。空なら制限なし） */
    from: string;
    to: string;
    sort: SalesOrderSortKey;
    order: 'asc' | 'desc';
    page: number;
};

/** 一覧の 1 件（App\SalesOrders\SalesOrderQuery::paginate） */
export type SalesOrderListItem = {
    id: number;
    number: string;
    order_date: string;
    due_date: string | null;
    status: SalesOrderStatus;
    status_label: string;
    customer: { id: number; code: string; name: string };
    line_count: number;
    subtotal: number;
    tax: number;
    total: number;
    note: string | null;
};

export type SalesOrderLine = {
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

/** 詳細（App\SalesOrders\SalesOrderQuery::detail） */
export type SalesOrderDetail = {
    id: number;
    number: string;
    order_date: string;
    due_date: string | null;
    status: SalesOrderStatus;
    status_label: string;
    note: string | null;
    subtotal: number;
    tax: number;
    total: number;
    /** 画面を開いた時点の updated_at（編集・ステータス変更の競合検出に送る） */
    version: string;
    created_at: string | null;
    updated_at: string | null;
    customer: { id: number; code: string; name: string; is_active: boolean };
    lines: SalesOrderLine[];
};

/** 顧客の選択肢 */
export type CustomerOption = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

/** 出荷で在庫が足りなかった商品（1 回だけの flash。App\Http\Controllers\SalesOrderController::shortageRows） */
export type SalesOrderShortage = {
    product_id: number;
    code: string;
    name: string;
    unit: string;
    /** 必要数（出荷で減らす数） */
    required: number;
    /** 現在庫 */
    available: number;
    /** 不足数 */
    shortage: number;
};
