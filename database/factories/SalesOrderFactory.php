<?php

namespace Database\Factories;

use App\Enums\SalesOrderStatus;
use App\Models\Partner;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の受注（明細なし・金額 0 の下書き）。明細は SalesOrderLineFactory で足す
 *
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $orderDate = now()->toDateString();

        return [
            'number' => 'SO-'.str_replace('-', '', $orderDate).'-'.fake()->unique()->numerify('9##'),
            'customer_id' => Partner::factory()->customer(),
            'order_date' => $orderDate,
            'due_date' => null,
            'status' => SalesOrderStatus::Draft,
            'note' => null,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ];
    }
}
