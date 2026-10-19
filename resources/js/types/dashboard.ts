/** ダッシュボードの集計（App\Dashboard\DashboardQuery::get） */

/** 月別の受注額（confirmed＋shipped の受注の税込 total。受注日基準・JST の暦月） */
export type MonthlySales = {
    year: number;
    month: number;
    /** YYYY-MM */
    key: string;
    /** 月初（YYYY-MM-DD） */
    start: string;
    /** 翌月 1 日（YYYY-MM-DD。含まない） */
    end: string;
    total: number;
};

/** 前月比（表示用の文字列もサーバーで作る: App\Dashboard\DashboardPeriod::monthOverMonth） */
export type MonthOverMonth = {
    /** 今月 − 前月 */
    diff: number;
    /** 増減率（%）。前月が 0 のときは null */
    rate: number | null;
    /** 矢印の向き（diff の符号） */
    direction: 'up' | 'down' | 'flat';
    /** 符号付きの差額（+1,540・-250・0） */
    diff_label: string;
    /** 増減率（+140.0%・-25.0%・±0%）。前月 0 円なら「前月の受注なし」 */
    rate_label: string;
};

export type DashboardOrderItem = {
    id: number;
    number: string;
    partner_name: string;
    /** 納期（受注）・入荷予定日（発注）。YYYY-MM-DD。未設定は null */
    date: string | null;
    /** 今日（JST）より前の日付なら true */
    overdue: boolean;
};

export type DashboardLowStockItem = {
    id: number;
    code: string;
    name: string;
    unit: string;
    reorder_point: number;
    stock: number;
    /** 発注点 − 現在庫（1 以上） */
    shortage: number;
};

export type DashboardProps = {
    /** 集計の基準日（JST の今日。YYYY-MM-DD） */
    today: string;
    sales: {
        current: MonthlySales;
        previous: MonthlySales;
        change: MonthOverMonth;
        /** 直近の月別（古い順。最後が今月） */
        trend: MonthlySales[];
    };
    unshipped: {
        count: number;
        overdue_count: number;
        /** 納期を過ぎた受注（納期の古い順） */
        overdue: DashboardOrderItem[];
        /** 一覧に出しきれなかった納期切れの件数（「他 N 件」） */
        overdue_more: number;
    };
    low_stock: {
        count: number;
        /** 不足の大きい順 */
        items: DashboardLowStockItem[];
        /** 一覧に出しきれなかった件数（「他 N 件」） */
        more: number;
    };
    awaiting_receipt: {
        count: number;
        overdue_count: number;
        /** 入荷予定日の近い順（予定日なしは最後） */
        items: DashboardOrderItem[];
        /** 一覧に出しきれなかった件数（「他 N 件」） */
        more: number;
    };
};
