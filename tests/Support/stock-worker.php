<?php

/**
 * 在庫の同時実行テスト（tests/Feature/Stock/StockConcurrencyTest.php）が別プロセスで起動する、在庫の記録・棚卸調整の 1 本。
 * テストのプロセスとは別の MySQL の接続で、実際のサービス（App\Stock\StockTransaction・StockAdjustment）を動かす。
 *
 * 使い方: php tests/Support/stock-worker.php <payload.json>
 * payload:
 * - op: record（入出庫の記録）／adjust（棚卸調整）
 * - userId: 記録するユーザー
 * - entries: record の入出庫（[{productId, type, quantity}]。この順番で渡す）
 * - productId・actual・reason: adjust の対象・実数・理由
 * - readFirst: record の前に、同じトランザクションで普通の SELECT をする（伝票を読んでから記録する形。
 *   分離レベルが REPEATABLE READ だと、ここでできたスナップショットのせいで先に確定した出庫を見落とす）
 * - hold: 確定（commit）の直前で「holding」を出し、標準入力に 1 行来るまで確定しない（ロックを持ったまま待つ）
 * - deadlockAfterAuditLog: 1 回目の試行で、操作履歴（audit_logs）を INSERT した直後に、この id の users の行を FOR UPDATE で取りに行く
 *   （テストの相手の接続がその行を持ったまま商品を要求すると、在庫と操作履歴を書いた後でデッドロックになる）
 *
 * 標準出力（1 行ずつ）: 最初に「connection <MySQL の接続 ID>」、hold なら「holding」、最後に結果の JSON
 * （outcome: ok／insufficient／exception、attempts: トランザクションを始めた回数（デッドロックで再試行すると増える）、
 *   shortages・changes・adjust・message）
 */

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\User;
use App\Stock\InsufficientStockException;
use App\Stock\StockAdjustment;
use App\Stock\StockChange;
use App\Stock\StockEntry;
use App\Stock\StockShortage;
use App\Stock\StockTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// テスト用 DB（testing）以外では動かさない（設定がキャッシュされていると環境変数が効かない）
if (! $app->environment('testing') || $app->configurationIsCached() || DB::connection()->getDatabaseName() !== 'testing') {
    fwrite(STDERR, "テスト用 DB ではないため中止しました\n");
    exit(2);
}

/** @var array{op: string, userId: int, entries?: list<array{productId: int, type: string, quantity: int}>, productId?: int, actual?: int, reason?: string, readFirst?: bool, hold?: bool, deadlockAfterAuditLog?: int} $payload */
$payload = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);

$line = function (string $text): void {
    fwrite(STDOUT, $text."\n");
    fflush(STDOUT);
};

$connectionId = DB::selectOne('SELECT CONNECTION_ID() AS id');
$line('connection '.(is_object($connectionId) ? $connectionId->id : 0));

$attempts = 0;
Event::listen(TransactionBeginning::class, function () use (&$attempts) {
    $attempts++;
});
if ($payload['hold'] ?? false) {
    // 最も外側のトランザクションの確定の直前（ロックを持ったまま）で、テストからの合図を待つ
    Event::listen(TransactionCommitting::class, function () use ($line) {
        $line('holding');
        fgets(STDIN);
    });
}

if (isset($payload['deadlockAfterAuditLog'])) {
    $triggered = false;
    DB::listen(function (QueryExecuted $query) use (&$triggered, $payload) {
        if ($triggered || ! str_starts_with($query->sql, 'insert into `audit_logs`')) {
            return;
        }
        $triggered = true;
        DB::select('select id from users where id = ? for update', [$payload['deadlockAfterAuditLog']]);
    });
}

$changes = fn (array $list) => array_map(fn (StockChange $c) => ['productId' => $c->product->id, 'before' => $c->before, 'after' => $c->after], $list);

try {
    $user = User::query()->findOrFail($payload['userId']);
    if ($payload['op'] === 'adjust') {
        $adjusted = app(StockAdjustment::class)->adjust(
            $user,
            Product::query()->findOrFail($payload['productId'] ?? 0),
            $payload['actual'] ?? 0,
            $payload['reason'] ?? '',
        );
        $result = ['outcome' => 'ok', 'adjust' => [...$adjusted, 'fellBelowReorderPoint' => $changes($adjusted['fellBelowReorderPoint'])]];
    } else {
        $entries = array_map(
            fn (array $e) => new StockEntry($e['productId'], StockMovementType::from($e['type']), $e['quantity']),
            $payload['entries'] ?? [],
        );
        $recorded = StockTransaction::run(function (StockTransaction $stock) use ($user, $entries, $payload) {
            if ($payload['readFirst'] ?? false) {
                DB::table('stock_movements')->count();
            }

            return $stock->record($user->id, $entries);
        });
        $result = ['outcome' => 'ok', 'changes' => $changes($recorded->changes), 'fellBelowReorderPoint' => $changes($recorded->fellBelowReorderPoint)];
    }
} catch (InsufficientStockException $e) {
    $result = [
        'outcome' => 'insufficient',
        'message' => $e->getMessage(),
        'shortages' => array_map(fn (StockShortage $s) => [
            'productId' => $s->product->id, 'available' => $s->available, 'required' => $s->required, 'shortage' => $s->shortage,
        ], $e->shortages),
    ];
} catch (Throwable $e) {
    $result = ['outcome' => 'exception', 'message' => $e::class.': '.$e->getMessage()];
}

$line(json_encode([...$result, 'attempts' => $attempts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
