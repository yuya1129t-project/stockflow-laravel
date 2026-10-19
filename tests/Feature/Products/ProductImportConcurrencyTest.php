<?php

use App\Products\ProductService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 同時の CSV 取込（MySQL の 2 接続）。取込を別プロセス（tests/Support/product-import-worker.php）で 2 本同時に動かし、
 * 取込の最初の問い合わせの直後で待ち合わせてから、ロック・INSERT を同時に進める。
 *
 * 確かめること: 500 にならない（例外は日本語の「もう一度取り込んでください」になる）、部分的な登録・更新や操作履歴の重複が残らない、
 * 新規の行どうしでデッドロックしない（試行は 1 回で済む）、デッドロックしても再試行で取り込める。
 *
 * ワーカーはテストのトランザクション（RefreshDatabase）の外で書き込むので、準備・確認・後始末は別の接続（concurrency）で行い、
 * このテストのデータ（商品コード CC-・メール @concurrency.test）は前後で消す
 */
const CONCURRENCY_HEADER = "商品コード,商品名,カテゴリ,単位,売価,仕入単価,発注点\r\n";

function concurrencyDb(): ConnectionInterface
{
    return DB::connection('concurrency');
}

function cleanUpConcurrencyData(): void
{
    $db = concurrencyDb();
    $userIds = $db->table('users')->where('email', 'like', '%@concurrency.test')->pluck('id')->all();
    $db->table('audit_logs')->whereIn('user_id', $userIds)->delete();
    $db->table('products')->where('code', 'like', 'CC-%')->delete();
    $db->table('users')->whereIn('id', $userIds)->delete();
}

function createConcurrencyUser(string $name): int
{
    return concurrencyDb()->table('users')->insertGetId([
        'name' => $name,
        'email' => "{$name}@concurrency.test",
        'password' => 'x',
        'role' => 'staff',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * 2 本の取込を同時に動かし、結果（ワーカーの出力）を返す
 *
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 * @return array{a: array<string, mixed>, b: array<string, mixed>}
 */
function runConcurrentImports(array $a, array $b): array
{
    $dir = sys_get_temp_dir().'/stockflow-import-'.bin2hex(random_bytes(6));
    mkdir($dir);
    // テスト用 DB に向ける（.env より環境変数が優先される）
    $env = [
        ...getenv(),
        'APP_ENV' => 'testing',
        'DB_DATABASE' => 'testing',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];

    try {
        $processes = [];
        foreach (['a' => [$a, 'b'], 'b' => [$b, 'a']] as $name => [$payload, $peer]) {
            $file = "{$dir}/{$name}.json";
            file_put_contents($file, json_encode([...$payload, 'name' => $name, 'peer' => $peer, 'barrierDir' => $dir, 'fileName' => "{$name}.csv"]));
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/product-import-worker.php'), $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                $env,
            );
            expect($process)->not->toBeFalse();
            $processes[$name] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as $name => [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, "ワーカー {$name} が異常終了しました: {$err}{$out}");
            $results[$name] = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        File::deleteDirectory($dir);
    }
}

/** @return array<string, int> */
function concurrencyLogCounts(): array
{
    return concurrencyDb()->table('audit_logs')
        ->join('users', 'users.id', '=', 'audit_logs.user_id')
        ->where('users.email', 'like', '%@concurrency.test')
        ->selectRaw('action, COUNT(*) AS n')
        ->groupBy('action')
        ->orderBy('action')
        ->pluck('n', 'action')
        ->map(fn ($n) => (int) $n)
        ->all();
}

beforeEach(function () {
    config(['database.connections.concurrency' => config('database.connections.'.config('database.default'))]);
    cleanUpConcurrencyData();
    $this->userA = createConcurrencyUser('import-a');
    $this->userB = createConcurrencyUser('import-b');
});

afterEach(function () {
    cleanUpConcurrencyData();
    DB::purge('concurrency');
});

test('同じ新規の商品コードを同時に取り込むと、片方だけが登録し、もう片方は日本語のエラー（部分的な登録・履歴の重複なし）', function () {
    $results = runConcurrentImports(
        ['userId' => $this->userA, 'csv' => CONCURRENCY_HEADER."CC-0001,ペンA,文具,本,100,50,5\r\nCC-0002,ノートA,文具,冊,100,50,5\r\n"],
        ['userId' => $this->userB, 'csv' => CONCURRENCY_HEADER."CC-0001,ペンB,文具,本,200,60,5\r\nCC-0002,ノートB,文具,冊,200,60,5\r\n"],
    );

    $outcomes = array_column($results, 'outcome');
    sort($outcomes);
    expect($outcomes)->toBe(['ok', 'validation'])
        ->and(array_column($results, 'synced'))->toBe([true, true]);
    $winner = $results['a']['outcome'] === 'ok' ? 'A' : 'B';
    $loser = $results['a']['outcome'] === 'ok' ? $results['b'] : $results['a'];
    expect($loser['message'])->toBe('取込中に同じ商品コードが別の操作で登録されました。もう一度取り込んでください');

    // 勝った側の 2 行だけがそろって登録されている
    expect(concurrencyDb()->table('products')->where('code', 'like', 'CC-%')->orderBy('code')->pluck('name')->all())
        ->toBe(["ペン{$winner}", "ノート{$winner}"])
        ->and(concurrencyLogCounts())->toBe(['create' => 2, 'import' => 1]);
});

test('別々の新規の商品コードを同時に取り込むと、どちらも 1 回の試行で登録できる（デッドロックしない）', function () {
    $results = runConcurrentImports(
        ['userId' => $this->userA, 'csv' => CONCURRENCY_HEADER."CC-0008,ペン,文具,本,100,50,5\r\n"],
        ['userId' => $this->userB, 'csv' => CONCURRENCY_HEADER."CC-0009,ノート,文具,冊,200,60,5\r\n"],
    );

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and(array_column($results, 'synced'))->toBe([true, true])
        ->and(array_column($results, 'attempts'))->toBe([1, 1])
        ->and(concurrencyDb()->table('products')->where('code', 'like', 'CC-%')->orderBy('code')->pluck('code')->all())->toBe(['CC-0008', 'CC-0009'])
        ->and(concurrencyLogCounts())->toBe(['create' => 2, 'import' => 2]);
});

test('商品コードの順と id の順が逆の既存の商品を、逆の行順で同時に更新しても、両方が順に反映される', function () {
    // id は CC-0002 のほうが小さい（商品コードの順と id の順が逆）
    $db = concurrencyDb();
    foreach (['CC-0002', 'CC-0001'] as $code) {
        $db->table('products')->insert([
            'code' => $code, 'name' => '元の名前', 'category' => '文具', 'unit' => '本',
            'sales_price' => 1, 'purchase_price' => 1, 'reorder_point' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    expect($db->table('products')->where('code', 'CC-0002')->value('id'))->toBeLessThan($db->table('products')->where('code', 'CC-0001')->value('id'));

    $results = runConcurrentImports(
        ['userId' => $this->userA, 'csv' => CONCURRENCY_HEADER."CC-0001,ペン,文具,本,101,50,5\r\nCC-0002,ノート,文具,冊,102,50,5\r\n"],
        ['userId' => $this->userB, 'csv' => CONCURRENCY_HEADER."CC-0002,ノート,文具,冊,202,60,5\r\nCC-0001,ペン,文具,本,201,60,5\r\n"],
    );

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and(array_column($results, 'synced'))->toBe([true, true])
        ->and(array_column($results, 'attempts'))->toBe([1, 1])
        ->and(array_column(array_column($results, 'summary'), 'updated'))->toBe([2, 2]);
    // 後から反映した側の 2 行がそろっている（片方の取込の一部だけが残らない）
    $prices = $db->table('products')->where('code', 'like', 'CC-%')->orderBy('code')->pluck('sales_price')->map(fn ($p) => (int) $p)->all();
    expect($prices)->toBeIn([[101, 102], [201, 202]])
        ->and(concurrencyLogCounts())->toBe(['import' => 2, 'update' => 4]);
});

test('取込のトランザクションがデッドロックで負けても、再試行して取り込める（履歴の重複なし）', function () {
    expect(ProductService::IMPORT_ATTEMPTS)->toBeGreaterThan(1);

    // 1 回目の試行で、users の行を互いに逆の順にロックして確実にデッドロックさせる
    $results = runConcurrentImports(
        ['userId' => $this->userA, 'csv' => CONCURRENCY_HEADER."CC-0008,ペン,文具,本,100,50,5\r\n", 'injectDeadlock' => true, 'ownLockUserId' => $this->userA, 'peerLockUserId' => $this->userB],
        ['userId' => $this->userB, 'csv' => CONCURRENCY_HEADER."CC-0009,ノート,文具,冊,200,60,5\r\n", 'injectDeadlock' => true, 'ownLockUserId' => $this->userB, 'peerLockUserId' => $this->userA],
    );

    $attempts = array_column($results, 'attempts');
    sort($attempts);
    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and($attempts)->toBe([1, 2])
        ->and(concurrencyDb()->table('products')->where('code', 'like', 'CC-%')->count())->toBe(2)
        ->and(concurrencyLogCounts())->toBe(['create' => 2, 'import' => 2]);
});
