<?php

namespace Database\Seeders;

use App\Support\Database\AppTables;
use App\Support\Database\DestructiveOperationGuard;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoData;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * デモ用シードの投入（`sail artisan db:seed`、`sail artisan migrate --seed`）。docs/spec.md §9
 *
 * このアプリのテーブル（App\Support\Database\AppTables）を**空にしてから**投入し直す（何度実行しても同じ状態になる）。
 * 消去と投入は 1 つのトランザクションで行い、途中で失敗したら元のデータに戻す。
 * ローカル以外の DB には STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1 がない限り実行しない（DestructiveOperationGuard）。
 * データの中身は Database\Seeders\Demo\DemoData（Next.js 版と同じ生成手順）
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $guard = DestructiveOperationGuard::checkDefaultConnection();
        if (! $guard->ok) {
            throw new RuntimeException("シードを中止しました: {$guard->reason}");
        }
        $this->command->info("接続先: {$guard->target}（アプリのテーブルを空にしてから投入します）");

        $data = DemoData::build(CarbonImmutable::now());
        $now = $this->timestamp(CarbonImmutable::now());

        DB::transaction(function () use ($data, $now) {
            // 参照する側から消す（TRUNCATE はトランザクションに入らないため DELETE を使う）
            foreach (AppTables::ALL as $table) {
                DB::table($table)->delete();
            }

            // ユーザーの ID は投入した結果で決まるので、入出庫の記録者は key から引く
            $userIds = [];
            $password = Hash::make(DemoData::PASSWORD);
            foreach ($data['users'] as $user) {
                $userIds[$user['key']] = DB::table('users')->insertGetId([
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'is_active' => true,
                    'email_verified_at' => $now,
                    'password' => $password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('partners')->insert(array_map(fn (array $p) => [
                ...$p,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['partners']));

            DB::table('products')->insert(array_map(fn (array $p) => [
                ...$p,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['products']));

            foreach (['purchase_orders' => 'purchase_order_id', 'sales_orders' => 'sales_order_id'] as $table => $foreignKey) {
                $orders = $data[$table];
                $lines = [];
                foreach ($orders as $order) {
                    foreach ($order['lines'] as $line) {
                        $lines[] = [$foreignKey => $order['id'], ...$line];
                    }
                }
                DB::table($table)->insert(array_map(function (array $order) {
                    unset($order['lines']);

                    return [
                        ...$order,
                        'created_at' => $this->timestamp($order['created_at']),
                        'updated_at' => $this->timestamp($order['updated_at']),
                    ];
                }, $orders));
                DB::table(substr($table, 0, -1).'_lines')->insert($lines);
            }

            foreach (array_chunk($data['stock_movements'], 200) as $chunk) {
                DB::table('stock_movements')->insert(array_map(fn (array $m) => [
                    'product_id' => $m['product_id'],
                    'type' => $m['type'],
                    'quantity' => $m['quantity'],
                    'reason' => $m['reason'],
                    'ref_type' => $m['ref_type'],
                    'ref_id' => $m['ref_id'],
                    'user_id' => $userIds[$m['user']],
                    'created_at' => $this->timestamp($m['created_at']),
                ], $chunk));
            }

            DB::table('document_sequences')->insert($data['document_sequences']);
        });

        $this->command->info(sprintf(
            'シードを投入しました: ユーザー %d 取引先 %d 商品 %d 受注 %d 発注 %d 入出庫 %d',
            count($data['users']),
            count($data['partners']),
            count($data['products']),
            count($data['sales_orders']),
            count($data['purchase_orders']),
            count($data['stock_movements']),
        ));
    }

    /** 日時の列に入れる値（アプリのタイムゾーンの壁時計。Eloquent が書き込む形式と同じ） */
    private function timestamp(CarbonImmutable $at): string
    {
        $timezone = config('app.timezone');

        return $at->setTimezone(is_string($timezone) ? $timezone : 'UTC')->format('Y-m-d H:i:s');
    }
}
