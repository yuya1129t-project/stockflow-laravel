<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use Carbon\CarbonImmutable;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 入出庫履歴（docs/spec.md §3）。現在庫 = quantity（符号付き）の合計。
 * 履歴は更新・削除しない（updated_at を持たない）。ref_type＋ref_id で発生元の伝票を指す（棚卸調整は null）
 *
 * @property int $id
 * @property int $product_id
 * @property StockMovementType $type
 * @property int $quantity
 * @property string|null $reason
 * @property StockRefType|null $ref_type
 * @property int|null $ref_id
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['product_id', 'type', 'quantity', 'reason', 'ref_type', 'ref_id', 'user_id'])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'ref_type' => StockRefType::class,
            'ref_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
