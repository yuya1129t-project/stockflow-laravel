/** 商品（一覧・詳細。App\Products\ProductQuery が渡す項目） */
export type Product = {
    id: number;
    code: string;
    name: string;
    category: string;
    unit: string;
    sales_price: number;
    purchase_price: number;
    reorder_point: number;
    is_active: boolean;
    /** 現在庫（入出庫の合計） */
    stock: number;
    /** 発注点割れ（有効な商品で 現在庫 < 発注点） */
    is_low_stock: boolean;
};

export type ProductSortKey =
    | 'code'
    | 'name'
    | 'category'
    | 'salesPrice'
    | 'reorderPoint'
    | 'stock';

export type ProductStatusFilter = 'active' | 'all' | 'inactive';

/** 一覧の検索条件（URL のクエリ。App\Products\ProductListParams） */
export type ProductFilters = {
    q: string;
    category: string;
    status: ProductStatusFilter;
    sort: ProductSortKey;
    order: 'asc' | 'desc';
    page: number;
};

export type Pagination = {
    total: number;
    page: number;
    totalPages: number;
    perPage: number;
};

/** 入力欄の文字数の上限（画面の maxlength。検証はサーバー側） */
export type ProductLimits = {
    code: number;
    name: number;
    category: number;
    unit: number;
};

export type ProductImportError = {
    /** ファイルの行番号（見出し行が 1）。ファイル全体のエラーは null */
    line: number | null;
    field: string | null;
    message: string;
};

/** CSV 取込の結果（1 回だけの flash） */
export type ProductImportResult =
    | {
          status: 'success';
          fileName: string;
          summary: { created: number; updated: number; unchanged: number };
      }
    | {
          status: 'error';
          fileName: string;
          message: string;
          errors: ProductImportError[];
          totalErrors: number;
      };
