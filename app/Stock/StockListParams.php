<?php

namespace App\Stock;

use App\Products\ProductListParams;

/**
 * 現在庫一覧（/stock）の検索条件（URL のクエリ）。商品一覧の条件（キーワード・カテゴリ・状態・並べ替え・ページ）に
 * 「発注点割れのみ」の絞り込み（level）を加えたもの。不正な値は既定値にする（Next.js 版 src/lib/stock/search-params.ts と同じ）
 */
final readonly class StockListParams
{
    /** 在庫の絞り込み: すべて（既定）・発注点割れのみ */
    public const array LEVELS = ['all', 'low'];

    /** @param  'all'|'low'  $level */
    public function __construct(
        public ProductListParams $products,
        public string $level = 'all',
    ) {}

    /** @param  array<array-key, mixed>  $query */
    public static function fromQuery(array $query): self
    {
        return new self(ProductListParams::fromQuery($query), ($query['level'] ?? null) === 'low' ? 'low' : 'all');
    }

    public function lowStockOnly(): bool
    {
        return $this->level === 'low';
    }

    public function withPage(int $page): self
    {
        return new self($this->products->withPage($page), $this->level);
    }

    /** @return array{q: string, category: string, status: string, sort: string, order: string, page: int, level: string} */
    public function toArray(): array
    {
        return [...$this->products->toArray(), 'level' => $this->level];
    }
}
