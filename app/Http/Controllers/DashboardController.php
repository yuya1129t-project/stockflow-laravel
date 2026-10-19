<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ダッシュボード（ログイン後のトップ。docs/spec.md §5-2）。閲覧は全ロール（ルートの can: で検査する）
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardQuery $dashboard): Response
    {
        return Inertia::render('Dashboard', $dashboard->get());
    }
}
