<?php

namespace App\Support\Partners;

use App\Enums\PartnerKind;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * 取引先一覧の検索条件（URL のクエリ。docs/spec.md §5）。
 * 条件を URL に持つので、共有・再読み込み・ブラウザの「戻る」で同じ一覧を再現できる。不正な値は既定値にする（エラーにしない）
 */
final readonly class PartnerListFilters
{
    public const array SORTS = ['code', 'name', 'kind'];

    /** active: 有効のみ（既定）／all: 無効も表示／inactive: 無効のみ */
    public const array STATUSES = ['active', 'all', 'inactive'];

    public const int PER_PAGE = 20;

    /** 検索語の最大長（これより長い部分は切り捨てる） */
    public const int MAX_QUERY_LENGTH = 100;

    /**
     * @param  string  $q  取引先コード・取引先名の部分一致
     * @param  PartnerKind|null  $kind  customer は both を含む、supplier も both を含む、both は both だけ（PartnerKind::listFilter）
     * @param  value-of<self::STATUSES>  $status
     * @param  value-of<self::SORTS>  $sort
     * @param  'asc'|'desc'  $order
     */
    public function __construct(
        public string $q = '',
        public ?PartnerKind $kind = null,
        public string $status = 'active',
        public string $sort = 'code',
        public string $order = 'asc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $string = fn (string $key): string => is_string($value = $request->query($key)) ? trim($value) : '';
        $status = $string('status');
        $sort = $string('sort');
        $order = $string('order');

        return new self(
            q: mb_substr($string('q'), 0, self::MAX_QUERY_LENGTH),
            kind: PartnerKind::tryFrom($string('kind')),
            status: in_array($status, self::STATUSES, true) ? $status : 'active',
            sort: in_array($sort, self::SORTS, true) ? $sort : 'code',
            order: $order === 'desc' ? 'desc' : 'asc',
        );
    }

    /**
     * 絞り込みと並べ替えをかけた問い合わせ
     *
     * @return Builder<Partner>
     */
    public function query(): Builder
    {
        $query = Partner::query();

        if ($this->q !== '') {
            // LIKE の特殊文字（\ % _）は文字として検索する。大文字・小文字は区別しない（照合順序 utf8mb4_unicode_ci）
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->q).'%';
            $query->where(fn (Builder $q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like));
        }
        if ($this->kind !== null) {
            $query->whereIn('kind', array_map(fn (PartnerKind $kind) => $kind->value, $this->kind->listFilter()));
        }
        if ($this->status !== 'all') {
            $query->where('is_active', $this->status === 'active');
        }

        // 並べ替えの列は SORTS（ホワイトリスト）。同じ値の行の順番が変わらないよう最後にコードで並べる
        $query->orderBy($this->sort, $this->order);
        if ($this->sort !== 'code') {
            $query->orderBy('code');
        }

        return $query;
    }

    /**
     * 画面に渡す検索条件
     *
     * @return array{q: string, kind: string, status: string, sort: string, order: string}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'kind' => $this->kind->value ?? '',
            'status' => $this->status,
            'sort' => $this->sort,
            'order' => $this->order,
        ];
    }
}
