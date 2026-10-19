<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の発注（明細なし・金額 0 の下書き）。明細は PurchaseOrderLineFactory で足す
 *
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $orderDate = now()->toDateString();

        return [
            'number' => 'PO-'.str_replace('-', '', $orderDate).'-'.fake()->unique()->numerify('9##'),
            'supplier_id' => Partner::factory()->supplier(),
            'order_date' => $orderDate,
            'expected_date' => null,
            'status' => PurchaseOrderStatus::Draft,
            'note' => null,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ];
    }
}
