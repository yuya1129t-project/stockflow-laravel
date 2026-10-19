/** 受注・発注で共通の型（明細の編集部品 OrderLinesEditor など） */

/** 明細の入力（フォームの値。すべて文字列で送り、検証・計算はサーバー側で行う） */
export type OrderLineForm = {
    product_id: string;
    quantity: string;
    unit_price: string;
};

/** 明細の商品の選択肢（App\PurchaseOrders\PurchaseOrderQuery::productOptions） */
export type LineProductOption = {
    id: number;
    code: string;
    name: string;
    unit: string;
    /** 単価の初期値（発注は仕入単価、受注は売価） */
    price: number;
    is_active: boolean;
};

/** 入力の上限と税率（画面の補助。検証はサーバー側） */
export type OrderLimits = {
    lines: number;
    quantity: number;
    unitPrice: number;
    note: number;
    total: number;
    taxRatePercent: number;
};

export type OrderAmounts = {
    subtotal: number;
    tax: number;
    total: number;
};
