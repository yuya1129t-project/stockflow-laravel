<?php

namespace App\AuditLogs;

use App\Models\AuditLog;
use App\Models\User;
use App\Users\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 操作履歴の一覧（絞り込み・ページ送り）と表示名・対象へのリンク。権限の検査は呼び出し側（コントローラー）で行う
 *
 * @phpstan-type AuditLogItem array{id: int, createdAt: string, action: string, actionLabel: string, targetType: string|null, targetTypeLabel: string|null, targetId: string|null, targetUrl: string|null, summary: string, user: array{id: int, name: string}|null}
 */
final class AuditLogQuery
{
    /** 操作の表示名（ここにない操作は記録された値をそのまま表示する） */
    public const array ACTION_LABELS = [
        'login' => 'ログイン',
        'create' => '登録',
        'update' => '更新',
        'deactivate' => '無効化',
        'reactivate' => '再有効化',
        'import' => 'CSV 取込',
        'status_change' => 'ステータス変更',
        'stock_adjust' => '棚卸調整',
        'role_change' => 'ロール変更',
        'password_reset' => 'パスワード再設定',
        'token_create' => 'API トークン発行',
        'token_revoke' => 'API トークン失効',
        // 発注点割れの通知（#11）の送信失敗
        'notify_failed' => '通知の送信失敗',
    ];

    /** 対象の種類の表示名 */
    public const array TARGET_TYPE_LABELS = [
        'product' => '商品',
        'partner' => '取引先',
        'sales_order' => '受注',
        'purchase_order' => '発注',
        UserService::TARGET_TYPE => 'ユーザー',
        UserService::TOKEN_TARGET_TYPE => 'API トークン',
    ];

    /** 対象の詳細画面のルート名（ここにない種類はリンクにしない） */
    private const array TARGET_ROUTES = [
        'product' => 'products.show',
        'partner' => 'partners.show',
        'sales_order' => 'sales-orders.show',
        'purchase_order' => 'purchase-orders.show',
        UserService::TARGET_TYPE => 'users.show',
    ];

    /**
     * 新しい順の一覧。範囲外のページは最後のページに寄せる
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(AuditLogListParams $params): LengthAwarePaginator
    {
        $paginator = $this->query($params)->paginate(AuditLogListParams::PER_PAGE, page: $params->page);
        if ($paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $this->query($params)->paginate(AuditLogListParams::PER_PAGE, page: $paginator->lastPage());
        }

        return $paginator;
    }

    /** @return AuditLogItem */
    public static function toItem(AuditLog $log): array
    {
        $user = $log->user;

        return [
            'id' => $log->id,
            'createdAt' => $log->created_at?->format('Y-m-d H:i:s') ?? '',
            'action' => $log->action,
            'actionLabel' => self::ACTION_LABELS[$log->action] ?? $log->action,
            'targetType' => $log->target_type,
            'targetTypeLabel' => $log->target_type === null ? null : (self::TARGET_TYPE_LABELS[$log->target_type] ?? $log->target_type),
            'targetId' => $log->target_id,
            'targetUrl' => self::targetUrl($log->target_type, $log->target_id),
            'summary' => $log->summary,
            'user' => $user instanceof User ? ['id' => $user->id, 'name' => $user->name] : null,
        ];
    }

    /** 対象の詳細画面の URL（パスだけ）。対象の種類が詳細画面を持たない・ID が数字でなければ null */
    public static function targetUrl(?string $targetType, ?string $targetId): ?string
    {
        if ($targetType === null || $targetId === null || ! isset(self::TARGET_ROUTES[$targetType])) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,18}\z/', $targetId) !== 1) {
            return null;
        }

        return route(self::TARGET_ROUTES[$targetType], $targetId, false);
    }

    /**
     * 絞り込みの選択肢（記録されている操作・対象の種類と、ユーザーの一覧）
     *
     * @return array{users: list<array{id: int, name: string, email: string}>, actions: list<array{value: string, label: string}>, targetTypes: list<array{value: string, label: string}>}
     */
    public function filterOptions(): array
    {
        /** @var list<string> $actions */
        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action')->all();
        /** @var list<string> $targetTypes */
        $targetTypes = AuditLog::query()->whereNotNull('target_type')->distinct()->orderBy('target_type')->pluck('target_type')->all();

        return [
            'users' => array_values(User::query()->orderBy('id')->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all()),
            'actions' => array_map(fn (string $a) => ['value' => $a, 'label' => self::ACTION_LABELS[$a] ?? $a], $actions),
            'targetTypes' => array_map(fn (string $t) => ['value' => $t, 'label' => self::TARGET_TYPE_LABELS[$t] ?? $t], $targetTypes),
        ];
    }

    /** @return Builder<AuditLog> */
    private function query(AuditLogListParams $params): Builder
    {
        $query = AuditLog::query()->with('user:id,name');
        if ($params->userId !== null) {
            $query->where('user_id', $params->userId);
        }
        if ($params->action !== '') {
            $query->where('action', $params->action);
        }
        if ($params->targetType !== '') {
            $query->where('target_type', $params->targetType);
        }
        // created_at はアプリのタイムゾーン（Asia/Tokyo）の壁時計で保存している。期間は from の 0 時以上、to の翌日 0 時未満
        if ($params->from !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($params->from)->startOfDay());
        }
        if ($params->to !== '') {
            $query->where('created_at', '<', CarbonImmutable::parse($params->to)->addDay()->startOfDay());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
