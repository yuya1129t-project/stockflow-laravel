<?php

namespace App\Products;

/**
 * 商品一覧の検索条件（URL のクエリ）。一覧画面と CSV 出力で同じ解釈をする（Next.js 版 src/lib/products/search-params.ts と同じ）。
 *
 * 検索条件を URL に持つので、共有・再読み込み・ブラウザの「戻る」で同じ一覧を再現できる。
 * 不正な値は既定値に置き換える（エラーにしない）
 */
final readonly class ProductListParams
{
    /** 並べ替えのキー（URL の値）→ 列（許可した列だけ。URL の値をそのまま SQL に入れない） */
    public const array SORT_COLUMNS = [
        'code' => 'products.code',
        'name' => 'products.name',
        'category' => 'products.category',
        'salesPrice' => 'products.sales_price',
        'reorderPoint' => 'products.reorder_point',
        'stock' => 'stock',
    ];

    /** 状態の絞り込み: 有効のみ（既定）・無効も表示・無効のみ */
    public const array STATUSES = ['active', 'all', 'inactive'];

    public const int PER_PAGE = 20;

    /** 検索語の最大長（これより長い部分は切り捨てる） */
    private const int MAX_QUERY_LENGTH = 100;

    /**
     * @param  string  $q  商品コード・商品名・カテゴリの部分一致
     * @param  string  $category  カテゴリ（完全一致。空ならすべて）
     * @param  'active'|'all'|'inactive'  $status
     * @param  key-of<self::SORT_COLUMNS>  $sort
     * @param  'asc'|'desc'  $order
     * @param  int  $page  1 始まり
     */
    public function __construct(
        public string $q = '',
        public string $category = '',
        public string $status = 'active',
        public string $sort = 'code',
        public string $order = 'asc',
        public int $page = 1,
    ) {}

    /** @param  array<array-key, mixed>  $query */
    public static function fromQuery(array $query): self
    {
        $text = fn (string $key): string => is_string($query[$key] ?? null) && mb_check_encoding($query[$key], 'UTF-8')
            ? mb_substr(mb_trim($query[$key]), 0, self::MAX_QUERY_LENGTH)
            : '';
        $status = $query['status'] ?? null;
        $sort = $query['sort'] ?? null;
        $order = $query['order'] ?? null;
        $page = $query['page'] ?? null;

        return new self(
            q: $text('q'),
            category: $text('category'),
            status: in_array($status, self::STATUSES, true) ? $status : 'active',
            sort: is_string($sort) && array_key_exists($sort, self::SORT_COLUMNS) ? $sort : 'code',
            order: $order === 'desc' ? 'desc' : 'asc',
            page: is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/', $page) === 1 ? (int) $page : 1,
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->q, $this->category, $this->status, $this->sort, $this->order, $page);
    }

    /**
     * 画面に渡す検索条件
     *
     * @return array{q: string, category: string, status: string, sort: string, order: string, page: int}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'category' => $this->category,
            'status' => $this->status,
            'sort' => $this->sort,
            'order' => $this->order,
            'page' => $this->page,
        ];
    }
}
