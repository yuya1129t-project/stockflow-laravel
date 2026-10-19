<?php

namespace App\Models;

use App\Enums\PartnerKind;
use App\Enums\PartnerRole;
use Carbon\CarbonImmutable;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 取引先（docs/spec.md §3）。郵便番号はハイフンなし 7 桁で保存する
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PartnerKind $kind
 * @property string|null $postal_code
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name', 'kind', 'postal_code', 'address', 'phone', 'email', 'is_active'])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PartnerKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * その役割（顧客・仕入先）を持つ取引先に絞る（顧客は customer か both、仕入先は supplier か both。spec §3）。
     * 受注・発注（#7・#8）の取引先の選択肢は `Partner::query()->withRole(PartnerRole::Customer)->where('is_active', true)`
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithRole(Builder $query, PartnerRole $role): void
    {
        $query->whereIn('kind', array_map(fn (PartnerKind $kind) => $kind->value, $role->kinds()));
    }

    /** @return HasMany<SalesOrder, $this> */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }
}
