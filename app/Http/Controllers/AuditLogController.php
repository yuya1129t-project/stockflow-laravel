<?php

namespace App\Http\Controllers;

use App\AuditLogs\AuditLogListParams;
use App\AuditLogs\AuditLogQuery;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 操作履歴（docs/spec.md §5-9。admin のみ）。一覧・絞り込み（ユーザー・操作・対象の種類・期間）・ページ送り・対象へのリンク。
 * 権限はルートの ->can(Permission::AuditView) と、ここ（Policy）で二重に検査する
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request, AuditLogQuery $query): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $params = AuditLogListParams::fromQuery($request->query());
        $paginator = $query->paginate($params);
        $params = $params->withPage($paginator->currentPage());

        return Inertia::render('audit-logs/Index', [
            'logs' => array_map(AuditLogQuery::toItem(...), $paginator->items()),
            'pagination' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'totalPages' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
            ],
            'filters' => $params->toArray(),
            'options' => $query->filterOptions(),
        ]);
    }
}
