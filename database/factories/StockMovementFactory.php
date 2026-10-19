<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の入出庫（既定は参照なしの棚卸調整で在庫を足す）
 *
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'type' => StockMovementType::Adjust,
            'quantity' => fake()->numberBetween(10, 50),
            'reason' => '棚卸（テスト）',
            'ref_type' => null,
            'ref_id' => null,
            'user_id' => null,
        ];
    }
}
