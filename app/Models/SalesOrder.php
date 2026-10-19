<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Enums\StockRefType;
use Carbon\CarbonImmutable;
use Database\Factories\SalesOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受注（docs/spec.md §3・§4）。金額は円の整数で、合計 = 小計 + 税（CHECK 制約）
 *
 * @property int $id
 * @property string $number
 * @property int $customer_id
 * @property CarbonImmutable $order_date
 * @property CarbonImmutable|null $due_date
 * @property SalesOrderStatus $status
 * @property string|null $note
 * @property int $subtotal
 * @property int $tax
 * @property int $total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['number', 'customer_id', 'order_date', 'due_date', 'status', 'note', 'subtotal', 'tax', 'total'])]
class SalesOrder extends Model
{
    /** @use HasFactory<SalesOrderFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'due_date' => 'date',
            'status' => SalesOrderStatus::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    /** @return HasMany<SalesOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    /**
     * この受注の出荷で記録した入出庫
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'ref_id')
            ->where('ref_type', StockRefType::SalesOrder->value);
    }
}
