<?php

namespace Database\Factories;

use App\Enums\PartnerKind;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の取引先（架空。名前に「サンプル」、メールは example.com、電話・郵便番号は 000 始まり）
 *
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $serial = fake()->unique()->numerify('9###');

        return [
            'code' => "C-{$serial}",
            'name' => "サンプル取引先 {$serial}",
            'kind' => PartnerKind::Customer,
            'postal_code' => "000{$serial}",
            'address' => "デモ県サンプル市中央1丁目{$serial}",
            'phone' => "000-0000-{$serial}",
            'email' => "partner{$serial}@example.com",
            'is_active' => true,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn () => ['kind' => PartnerKind::Customer]);
    }

    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => PartnerKind::Supplier,
            'code' => 'S-'.substr((string) $attributes['code'], 2),
        ]);
    }
}
