<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の商品（架空）
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $purchasePrice = fake()->numberBetween(10, 200) * 10;

        return [
            'code' => fake()->unique()->numerify('P-9###'),
            'name' => 'サンプル商品 '.fake()->unique()->numerify('####'),
            'category' => fake()->randomElement(['文具', '事務用品', '梱包資材', '日用品', '飲料・食品']),
            'unit' => fake()->randomElement(['個', '箱', '本', 'パック']),
            'sales_price' => intdiv($purchasePrice * 14, 100) * 10,
            'purchase_price' => $purchasePrice,
            'reorder_point' => fake()->numberBetween(2, 8) * 5,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
