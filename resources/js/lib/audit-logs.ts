import type { AuditLogFilters } from '@/types/audit-logs';

/** 絞り込み条件を URL のクエリにする（空の項目は省く。App\AuditLogs\AuditLogListParams::fromQuery が解釈する） */
export function auditLogListQuery(
    filters: AuditLogFilters,
): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.user) query.user = filters.user;
    if (filters.action) query.action = filters.action;
    if (filters.targetType) query.targetType = filters.targetType;
    if (filters.from) query.from = filters.from;
    if (filters.to) query.to = filters.to;
    if (filters.page > 1) query.page = String(filters.page);
    return query;
}
