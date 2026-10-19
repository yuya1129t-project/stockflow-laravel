<?php

namespace App\SalesOrders;

use App\Enums\SalesOrderStatus;
use DateTimeImmutable;

/**
 * 受注一覧の検索条件（URL のクエリ）。一覧画面と CSV 出力で同じ解釈をする（Next.js 版 src/lib/sales-orders/search-params.ts と同じ。
 * 発注 App\PurchaseOrders\PurchaseOrderListParams と同じ作り）。
 * 不正な値は既定値に置き換える（エラーにしない）
 */
final readonly class SalesOrderListParams
{
    /** 並べ替えのキー（URL の値） */
    public const array SORT_KEYS = ['orderDate', 'number', 'customer', 'dueDate', 'total'];

    public const int PER_PAGE = 20;

    /** 検索語の最大長（これより長い部分は切り捨てる） */
    private const int MAX_QUERY_LENGTH = 100;

    /**
     * @param  string  $q  受注番号の部分一致
     * @param  int|null  $customerId  顧客（null ならすべて）
     * @param  SalesOrderStatus|null  $status  ステータス（null ならすべて）
     * @param  string  $from  受注日の範囲（YYYY-MM-DD。空なら制限なし。両端を含む）
     * @param  value-of<self::SORT_KEYS>  $sort
     * @param  'asc'|'desc'  $order
     * @param  int  $page  1 始まり
     */
    public function __construct(
        public string $q = '',
        public ?int $customerId = null,
        public ?SalesOrderStatus $status = null,
        public string $from = '',
        public string $to = '',
        public string $sort = 'orderDate',
        public string $order = 'desc',
        public int $page = 1,
    ) {}

    /** @param  array<array-key, mixed>  $query */
    public static function fromQuery(array $query): self
    {
        $q = $query['q'] ?? null;
        $customer = $query['customer'] ?? null;
        $status = $query['status'] ?? null;
        $sort = $query['sort'] ?? null;
        $order = $query['order'] ?? null;
        $page = $query['page'] ?? null;

        return new self(
            q: is_string($q) && mb_check_encoding($q, 'UTF-8') ? mb_substr(mb_trim($q), 0, self::MAX_QUERY_LENGTH) : '',
            customerId: is_string($customer) && preg_match('/^[1-9][0-9]{0,8}$/', $customer) === 1 ? (int) $customer : null,
            status: is_string($status) ? SalesOrderStatus::tryFrom($status) : null,
            from: self::date($query['from'] ?? null),
            to: self::date($query['to'] ?? null),
            sort: is_string($sort) && in_array($sort, self::SORT_KEYS, true) ? $sort : 'orderDate',
            order: $order === 'asc' ? 'asc' : 'desc',
            page: is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/', $page) === 1 ? (int) $page : 1,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->q, $this->customerId, $this->status, $this->from, $this->to, $this->sort, $this->order, $page);
    }

    /**
     * 画面に渡す検索条件
     *
     * @return array{q: string, customer: string, status: string, from: string, to: string, sort: string, order: string, page: int}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'customer' => $this->customerId === null ? '' : (string) $this->customerId,
            'status' => $this->status->value ?? '',
            'from' => $this->from,
            'to' => $this->to,
            'sort' => $this->sort,
            'order' => $this->order,
            'page' => $this->page,
        ];
    }

    /** 実在する日付（YYYY-MM-DD）だけを残す。形を先に厳密に検査する（NULL バイトなどを含む値で解析の例外にしない） */
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
