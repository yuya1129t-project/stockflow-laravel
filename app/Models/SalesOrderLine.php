<?php

namespace App\Models;

use Database\Factories\SalesOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 受注明細。単価は受注時点の値を保存する（商品マスタの変更の影響を受けない）。
 * 同じ受注に同じ商品は 1 行だけ（一意制約）
 *
 * @property int $id
 * @property int $sales_order_id
 * @property int $product_id
 * @property int $quantity
 * @property int $unit_price
 */
#[Fillable(['sales_order_id', 'product_id', 'quantity', 'unit_price'])]
#[WithoutTimestamps]
class SalesOrderLine extends Model
{
    /** @use HasFactory<SalesOrderLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
