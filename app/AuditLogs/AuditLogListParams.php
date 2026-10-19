<?php

namespace App\AuditLogs;

use DateTimeImmutable;

/**
 * 操作履歴の一覧の絞り込み条件（URL のクエリ）。Next.js 版 src/lib/audit-logs/search-params.ts と同じ解釈。
 * 不正な値は既定値（絞り込みなし）に置き換える（エラーにしない）
 */
final readonly class AuditLogListParams
{
    public const int PER_PAGE = 50;

    /** 操作・対象の種類として受け付ける値（英小文字とアンダースコア） */
    private const string CODE_PATTERN = '/^[a-z_]{1,50}\z/';

    /**
     * @param  int|null  $userId  操作したユーザー（null ならすべて）
     * @param  string  $action  操作（audit_logs.action。空ならすべて）
     * @param  string  $targetType  対象の種類（audit_logs.target_type。空ならすべて）
     * @param  string  $from  期間（YYYY-MM-DD。Asia/Tokyo の日付。両端を含む。空なら制限なし）
     * @param  int  $page  1 始まり
     */
    public function __construct(
        public ?int $userId = null,
        public string $action = '',
        public string $targetType = '',
        public string $from = '',
        public string $to = '',
        public int $page = 1,
    ) {}

    /** @param  array<array-key, mixed>  $query */
    public static function fromQuery(array $query): self
    {
        $user = $query['user'] ?? null;
        $page = $query['page'] ?? null;

        return new self(
            userId: is_string($user) && preg_match('/^[1-9][0-9]{0,18}\z/', $user) === 1 && filter_var($user, FILTER_VALIDATE_INT) !== false ? (int) $user : null,
            action: self::code($query['action'] ?? null),
            targetType: self::code($query['targetType'] ?? null),
            from: self::date($query['from'] ?? null),
            to: self::date($query['to'] ?? null),
            page: is_string($page) && preg_match('/^[1-9][0-9]{0,8}\z/', $page) === 1 ? (int) $page : 1,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->userId, $this->action, $this->targetType, $this->from, $this->to, $page);
    }

    /**
     * 画面に渡す絞り込み条件
     *
     * @return array{user: string, action: string, targetType: string, from: string, to: string, page: int}
     */
    public function toArray(): array
    {
        return [
            'user' => $this->userId === null ? '' : (string) $this->userId,
            'action' => $this->action,
            'targetType' => $this->targetType,
            'from' => $this->from,
            'to' => $this->to,
            'page' => $this->page,
        ];
    }

    private static function code(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim($value);

        return preg_match(self::CODE_PATTERN, $value) === 1 ? $value : '';
    }

    /** 実在する日付（YYYY-MM-DD）だけを残す。形を先に厳密に検査する */
    private static function date(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
