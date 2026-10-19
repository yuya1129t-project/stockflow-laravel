<?php

use App\AuditLogs\AuditLogListParams;
use App\AuditLogs\AuditLogQuery;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * 操作履歴の一覧（docs/spec.md §5-9。admin のみ）: 絞り込み（ユーザー・操作・対象の種類・期間）・ページ送り・対象へのリンク
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => '管理者']);
    $this->staff = User::factory()->staff()->create(['name' => '担当者']);
});

function auditLog(?User $user, string $action, ?string $targetType, ?string $targetId, string $createdAt, string $summary = '操作'): AuditLog
{
    $log = AuditLog::factory()->create(['user_id' => $user?->id, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId, 'summary' => $summary]);
    $log->forceFill(['created_at' => CarbonImmutable::parse($createdAt)])->save();

    return $log;
}

test('admin は一覧を新しい順に見られる（ユーザー名・操作と対象の種類の表示名・対象へのリンク）', function () {
    auditLog($this->staff, 'create', 'product', '12', '2026-10-01 09:00:00', '商品を登録しました');
    auditLog($this->admin, 'token_create', 'api_token', '3', '2026-10-02 09:00:00');
    auditLog(null, 'notify_failed', 'sales_order', '7', '2026-10-03 09:00:00');

    $this->actingAs($this->admin)->get(route('audit-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit-logs/Index')
            ->has('logs', 3)
            ->where('logs.0.createdAt', '2026-10-03 09:00:00')
            ->where('logs.0.user', null)
            ->where('logs.0.actionLabel', '通知の送信失敗')
            ->where('logs.0.targetUrl', '/sales-orders/7')
            ->where('logs.1.actionLabel', 'API トークン発行')
            ->where('logs.1.targetTypeLabel', 'API トークン')
            ->where('logs.1.targetUrl', null)
            ->where('logs.2.user', ['id' => $this->staff->id, 'name' => '担当者'])
            ->where('logs.2.targetTypeLabel', '商品')
            ->where('logs.2.targetUrl', '/products/12')
            ->where('logs.2.summary', '商品を登録しました')
            ->where('pagination.total', 3)
            ->has('options.users', 2)
            ->where('options.actions', [
                ['value' => 'create', 'label' => '登録'],
                ['value' => 'notify_failed', 'label' => '通知の送信失敗'],
                ['value' => 'token_create', 'label' => 'API トークン発行'],
            ])
            ->where('options.targetTypes.0', ['value' => 'api_token', 'label' => 'API トークン']));
});

test('staff・viewer は 403', function (string $role) {
    $user = User::factory()->role(Role::from($role))->create();

    $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();
})->with(['staff', 'viewer']);

test('絞り込み: ユーザー・操作・対象の種類・期間（Asia/Tokyo の日付。両端を含む）', function (array $query, array $expectedSummaries) {
    auditLog($this->staff, 'create', 'product', '1', '2026-10-01 00:00:00', 'A');
    auditLog($this->staff, 'update', 'product', '1', '2026-10-01 23:59:59', 'B');
    auditLog($this->admin, 'create', 'partner', '2', '2026-10-02 00:00:00', 'C');
    auditLog($this->admin, 'login', 'user', (string) $this->admin->id, '2026-10-03 12:00:00', 'D');

    $query = array_map(fn ($v) => str_replace(['{staff}', '{admin}'], [(string) $this->staff->id, (string) $this->admin->id], $v), $query);
    $this->actingAs($this->admin)->get(route('audit-logs.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('logs', fn ($logs) => collect($logs)->pluck('summary')->all() === $expectedSummaries));
})->with([
    '条件なし' => [[], ['D', 'C', 'B', 'A']],
    'ユーザー' => [['user' => '{staff}'], ['B', 'A']],
    '操作' => [['action' => 'create'], ['C', 'A']],
    '対象の種類' => [['targetType' => 'product'], ['B', 'A']],
    '期間（1 日）' => [['from' => '2026-10-01', 'to' => '2026-10-01'], ['B', 'A']],
    '期間（から）' => [['from' => '2026-10-02'], ['D', 'C']],
    '期間（まで）' => [['to' => '2026-10-02'], ['C', 'B', 'A']],
    '組み合わせ' => [['user' => '{admin}', 'action' => 'create', 'from' => '2026-10-02', 'to' => '2026-10-02'], ['C']],
    '不正な値は無視（絞り込みなし）' => [['user' => 'abc', 'action' => 'DROP TABLE', 'targetType' => 'x;y', 'from' => '2026-02-30', 'to' => 'tomorrow'], ['D', 'C', 'B', 'A']],
    '範囲外のユーザー ID は無視' => [['user' => '99999999999999999999999'], ['D', 'C', 'B', 'A']],
]);

test('ページ送り: 50 件ずつ。範囲外のページは最後のページに寄せる', function () {
    for ($i = 1; $i <= AuditLogListParams::PER_PAGE + 1; $i++) {
        auditLog($this->admin, 'update', 'product', (string) $i, '2026-10-01 00:00:00', "S{$i}");
    }

    $this->actingAs($this->admin)->get(route('audit-logs.index'))
        ->assertInertia(fn (Assert $page) => $page->has('logs', 50)->where('pagination.totalPages', 2)->where('logs.0.summary', 'S51'));
    $this->get(route('audit-logs.index', ['page' => '99']))
        ->assertInertia(fn (Assert $page) => $page->has('logs', 1)->where('pagination.page', 2)->where('filters.page', 2)->where('logs.0.summary', 'S1'));
});

test('対象へのリンク: 詳細画面のある種類で、ID が数字のときだけ', function (?string $type, ?string $id, ?string $url) {
    expect(AuditLogQuery::targetUrl($type, $id))->toBe($url);
})->with([
    '商品' => ['product', '5', '/products/5'],
    '取引先' => ['partner', '5', '/partners/5'],
    '受注' => ['sales_order', '5', '/sales-orders/5'],
    '発注' => ['purchase_order', '5', '/purchase-orders/5'],
    'ユーザー' => ['user', '5', '/users/5'],
    'API トークン（詳細画面なし）' => ['api_token', '5', null],
    '不明な種類' => ['stock', '5', null],
    '数字でない ID' => ['product', '../x', null],
    'ID なし' => ['product', null, null],
    '種類なし' => [null, '5', null],
]);
