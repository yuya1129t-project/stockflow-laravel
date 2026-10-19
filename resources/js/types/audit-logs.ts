/** 操作履歴の一覧の 1 件（App\AuditLogs\AuditLogQuery::toItem） */
export type AuditLogItem = {
    id: number;
    createdAt: string;
    action: string;
    actionLabel: string;
    targetType: string | null;
    targetTypeLabel: string | null;
    targetId: string | null;
    /** 対象の詳細画面（なければ null） */
    targetUrl: string | null;
    summary: string;
    user: { id: number; name: string } | null;
};

/** 絞り込み条件（App\AuditLogs\AuditLogListParams::toArray） */
export type AuditLogFilters = {
    user: string;
    action: string;
    targetType: string;
    from: string;
    to: string;
    page: number;
};

export type AuditLogFilterOptions = {
    users: { id: number; name: string; email: string }[];
    actions: { value: string; label: string }[];
    targetTypes: { value: string; label: string }[];
};
