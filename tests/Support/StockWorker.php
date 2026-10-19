<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * 在庫の同時実行テストの部品。別プロセスのワーカー（tests/Support/stock-worker.php）の起動・合図と、
 * MySQL の performance_schema でのロック待ちの観測。
 *
 * ロック待ちは「2 つ目がロックを待っている」ことを DB で確かめてから 1 つ目を確定させるために使う（固定時間の待ちを証拠にしない）。
 * performance_schema を読める接続（lock_observer）は、既定で root（Sail の root のパスワードは DB_PASSWORD と同じ。CI は root）。
 * 別のユーザーにするときは DB_LOCK_OBSERVER_USERNAME・DB_LOCK_OBSERVER_PASSWORD を設定する
 */
final class StockWorker
{
    /** 観測の待ちの上限（秒）。これを過ぎたらテストの失敗にする */
    public const float WAIT_SECONDS = 10.0;

    /** @var list<Process> 起動したワーカー（後始末で止める） */
    private static array $started = [];

    private ?int $connectionId = null;

    private function __construct(
        private readonly Process $process,
        private readonly ?InputStream $input,
        private readonly string $payloadFile,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public static function start(array $payload): self
    {
        $file = tempnam(sys_get_temp_dir(), 'stockflow-stock-');
        assert(is_string($file));
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
        $input = ($payload['hold'] ?? false) ? new InputStream : null;
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/stock-worker.php'), $file], base_path(), $env, $input, 60);
        $process->start();
        self::$started[] = $process;

        return new self($process, $input, $file);
    }

    /** まだ動いているワーカーを止める（テストが途中で失敗したとき、ロックを持ったまま残さない） */
    public static function stopAll(): void
    {
        foreach (self::$started as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        self::$started = [];
    }

    /** ワーカーの MySQL の接続 ID（performance_schema の PROCESSLIST_ID） */
    public function connectionId(): int
    {
        if ($this->connectionId === null) {
            $this->waitForOutput('/^connection (\d+)$/m', $matches);
            $this->connectionId = (int) $matches[1];
        }

        return $this->connectionId;
    }

    /** 確定の直前（ロックを持ったまま）まで進んだことを待つ（hold のとき） */
    public function waitUntilHolding(): void
    {
        $this->waitForOutput('/^holding$/m');
    }

    /** hold を解いて確定させる */
    public function release(): void
    {
        assert($this->input !== null);
        $this->input->write("release\n");
        $this->input->close();
    }

    /**
     * 終わるのを待って結果（最後の行の JSON）を返す
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $this->process->wait();
        @unlink($this->payloadFile);
        $lines = array_values(array_filter(explode("\n", trim($this->process->getOutput()))));
        if (! $this->process->isSuccessful() || $lines === []) {
            throw new AssertionFailedError('ワーカーが異常終了しました: '.$this->process->getErrorOutput().$this->process->getOutput());
        }
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($result));

        return $result;
    }

    /** @param  array<int, string>|null  $matches */
    private function waitForOutput(string $pattern, ?array &$matches = null): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (preg_match($pattern, $this->process->getOutput(), $matches) !== 1) {
            if (! $this->process->isRunning() || microtime(true) > $deadline) {
                throw new AssertionFailedError("ワーカーの出力（{$pattern}）を待てませんでした: ".$this->process->getErrorOutput().$this->process->getOutput());
            }
            usleep(5_000);
        }
    }

    /** テストのトランザクション（RefreshDatabase）の外で、自動で確定する別の接続（ほかのプロセスから見えるデータの準備・確認・後始末） */
    public static function db(): Connection
    {
        $default = config('database.default');
        assert(is_string($default));
        config(['database.connections.stock_concurrency' => config("database.connections.{$default}")]);

        return DB::connection('stock_concurrency');
    }

    /** テストのプロセスから使う、もう 1 本の独立した接続（ロックを持ち続ける側・明細を INSERT する側） */
    public static function peer(string $name): Connection
    {
        $default = config('database.default');
        assert(is_string($default));
        config(["database.connections.{$name}" => config("database.connections.{$default}")]);
        $connection = DB::connection($name);
        // 想定外にロックを待ち続けたときに、テストが止まらないようにする
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 15');

        return $connection;
    }

    public static function connectionIdOf(Connection $connection): int
    {
        $row = $connection->selectOne('SELECT CONNECTION_ID() AS id');
        assert(is_object($row));

        return (int) $row->id;
    }

    /**
     * 接続 waiting が行ロックを待っている状態に入るまで待つ（performance_schema.data_lock_waits）。
     * blockedBy を渡したら、その接続が持つロックを待っていることも確かめる。上限を過ぎたらテストの失敗
     */
    public static function waitForLockWait(int $waiting, ?int $blockedBy = null): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        do {
            $waits = self::lockWaits();
            foreach ($waits as $w) {
                if ($w['waiting'] === $waiting && ($blockedBy === null || $w['blocking'] === $blockedBy)) {
                    return;
                }
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError(sprintf(
            '接続 %d が%sロック待ちに入りませんでした（観測したロック待ち: %s）',
            $waiting,
            $blockedBy === null ? '' : " 接続 {$blockedBy} の",
            json_encode($waits),
        ));
    }

    /** @return list<array{waiting: int, blocking: int}> 今のロック待ち（待っている接続 → 待たせている接続） */
    public static function lockWaits(): array
    {
        $rows = self::observer()->select(<<<'SQL'
            SELECT rt.PROCESSLIST_ID AS waiting, bt.PROCESSLIST_ID AS blocking
            FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads rt ON rt.THREAD_ID = w.REQUESTING_THREAD_ID
            JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID
            SQL);

        return array_map(fn (object $r) => ['waiting' => (int) $r->waiting, 'blocking' => (int) $r->blocking], $rows);
    }

    /** performance_schema を読める観測用の接続 */
    public static function observer(): Connection
    {
        $default = config('database.default');
        assert(is_string($default));
        $config = config("database.connections.{$default}");
        assert(is_array($config));
        config(['database.connections.lock_observer' => [
            ...$config,
            'username' => env('DB_LOCK_OBSERVER_USERNAME', 'root'),
            'password' => env('DB_LOCK_OBSERVER_PASSWORD', $config['password'] ?? ''),
        ]]);

        return DB::connection('lock_observer');
    }
}
