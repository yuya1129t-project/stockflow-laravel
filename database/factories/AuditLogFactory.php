<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の操作履歴
 *
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => 'test',
            'target_type' => null,
            'target_id' => null,
            'summary' => 'テスト用の操作履歴',
        ];
    }
}
