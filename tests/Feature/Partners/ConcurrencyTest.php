<?php

use App\Enums\PartnerKind;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\User;
use App\Services\Partners\AddressLookupRateLimiter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * 同時に走る処理との競合（MySQL の別々の接続・別々のプロセスで確かめる）。
 * テストは RefreshDatabase の外側のトランザクションの中で動くので、ほかの接続から見える必要があるデータは、
 * 自動で確定する別の接続（independentConnection）で書き、テストの後でその接続で消す
 */
function independentConnection(): Connection
{
    config(['database.connections.concurrency' => config('database.connections.'.config('database.default'))]);

    return DB::connection('concurrency');
}

/** 別プロセスのワーカーに渡す環境変数（テストと同じテスト用 DB に、キャッシュは database のストアで） */
function workerEnv(): array
{
    $c = config('database.connections.'.config('database.default'));

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => '',
        'DB_HOST' => (string) $c['host'],
        'DB_PORT' => (string) $c['port'],
        'DB_DATABASE' => (string) $c['database'],
        'DB_USERNAME' => (string) $c['username'],
        'DB_PASSWORD' => (string) $c['password'],
        'CACHE_STORE' => 'database',
    ];
}

function worker(string $script, array $args): Process
{
    return new Process([PHP_BINARY, base_path("tests/Support/{$script}"), ...array_map('strval', $args)], null, workerEnv(), null, 60);
}

test('回数制限: 上限の直前から同時に要求しても、許可されるのは残りの枠（1 回）だけ', function () {
    $userId = random_int(900_000_000, 999_999_999);
    $db = independentConnection();
    $cleanup = fn () => $db->table('cache')->where('key', 'like', "%address-lookup:user:{$userId}%")->delete();
    $cleanup();
    $this->beforeApplicationDestroyed($cleanup);

    // 上限の 1 つ手前まで使う
    $preset = worker('address-lookup-rate-limit-worker.php', [$userId, 0, AddressLookupRateLimiter::LIMIT - 1]);
    $preset->mustRun();
    expect(substr_count($preset->getOutput(), 'allowed'))->toBe(AddressLookupRateLimiter::LIMIT - 1);

    // 6 つのプロセスが同じ時刻に 1 回ずつ要求する
    $startAt = microtime(true) + 2.0;
    $workers = array_map(fn () => worker('address-lookup-rate-limit-worker.php', [$userId, $startAt, 1]), range(1, 6));
    array_walk($workers, fn (Process $p) => $p->start());
    array_walk($workers, fn (Process $p) => $p->wait());

    $outputs = array_map(fn (Process $p) => trim($p->getOutput()), $workers);
    $counts = array_count_values($outputs);
    ksort($counts);
    expect($counts)->toBe(['allowed' => 1, 'denied' => 5]);
});

test('種別変更: 別の接続が受注を追加中（未確定）なら、確定を待ってから件数を数え、顧客の役割を外す変更を拒否する', function () {
    $db = independentConnection();
    $partnerId = $db->table('partners')->insertGetId([
        'code' => 'C-9901', 'name' => '競合テスト商事', 'kind' => 'customer', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->beforeApplicationDestroyed(function () use ($db, $partnerId) {
        // RefreshDatabase のロールバックの後に動く（テストの接続のロックが外れてから消す）
        $db->table('sales_orders')->where('customer_id', $partnerId)->delete();
        $db->table('partners')->where('id', $partnerId)->delete();
    });
    $staff = User::factory()->staff()->create();

    // 別の接続で受注を挿入し、1.5 秒たってから確定する
    $inserting = worker('insert-sales-order-worker.php', [$partnerId, 1500]);
    $inserting->start();
    $inserting->waitUntil(fn (string $type, string $output) => str_contains($output, 'inserted'));

    $started = microtime(true);
    $response = $this->actingAs($staff)->put(route('partners.update', $partnerId), [
        'code' => 'C-9901', 'name' => '競合テスト商事', 'kind' => 'supplier',
    ]);
    $elapsed = microtime(true) - $started;
    $inserting->wait();

    expect($inserting->isSuccessful())->toBeTrue();
    $response->assertSessionHasErrors(['kind' => '受注（1 件）で使われているため、顧客の役割を外せません（「顧客」か「顧客・仕入先」にしてください）']);
    // 受注の確定を待ってから判定した
    expect($elapsed)->toBeGreaterThan(0.8);
    expect(Partner::query()->findOrFail($partnerId)->kind)->toBe(PartnerKind::Customer);
    expect(AuditLog::query()->where('target_type', 'partner')->count())->toBe(0);
});
