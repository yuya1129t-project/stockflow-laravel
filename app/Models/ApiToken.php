<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 外部向け REST API のトークン（Sanctum の personal_access_tokens。docs/spec.md §6-2）。
 * Sanctum::usePersonalAccessTokenModel で Sanctum に使わせる（AppServiceProvider）。
 * 失効は revoked_at を設定する（行は消さない）。token は SHA-256 のハッシュで、平文は発行時に 1 回だけ返す
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @var array<string, string> */
    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
