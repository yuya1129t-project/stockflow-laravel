<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;
use RuntimeException;

/**
 * DB のデータを消す操作（デモデータのシード・migrate:fresh など）の前に、接続先が安全かを判定する。
 *
 * - ローカルの開発環境（APP_ENV=local）とテスト（APP_ENV=testing）以外では、ホストを問わず
 *   環境変数 STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1 がない限り拒否する（同じマシンに MySQL を置く本番構成も含む）
 * - ローカル（localhost／127.0.0.1／::1／Sail の mysql／ソケット）以外の DB も、同じ環境変数がない限り拒否する
 *   （デモ環境の定期リセットでだけ設定する想定）
 * - テスト用 DB（testing）の取り違えを防ぐ: テスト（APP_ENV=testing）は testing でだけ、
 *   それ以外の環境は testing 以外でだけ実行する（この条件は環境変数でも外せない）
 * - 読み書き分離（read／write）の設定は、Laravel と同じ規則で実際に書き込む先（write の全候補）を判定する
 *
 * 判定は接続設定（config/database.php）だけで行い、DB には接続しない
 */
final class DestructiveOperationGuard
{
    /** 許可の環境変数がなくても実行できる環境 */
    private const array SAFE_ENVIRONMENTS = ['local', 'testing'];

    /** ローカル以外の DB・開発環境以外での破壊操作を許可する環境変数 */
    public const string ALLOW_ENV = 'STOCKFLOW_ALLOW_DESTRUCTIVE_SEED';

    /** テスト用 DB の名前（phpunit.xml・CI と同じ） */
    public const string TEST_DATABASE = 'testing';

    /** 実行の直前に接続先を確かめるコマンド（データを消す・作り直す） */
    public const array DESTRUCTIVE_COMMANDS = [
        'db:seed',
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
    ];

    /** ローカルとみなすホスト（mysql は Sail の Docker Compose のサービス名） */
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', 'mysql'];

    /**
     * @param  array<string, mixed>  $connection  接続設定（config('database.connections.<名前>')）
     * @param  string  $environment  アプリの環境（APP_ENV）
     * @param  string|null  $allowFlag  STOCKFLOW_ALLOW_DESTRUCTIVE_SEED の値
     */
    public static function check(array $connection, string $environment, ?string $allowFlag): GuardResult
    {
        $targets = self::writeTargets($connection);
        if (is_string($targets)) {
            return GuardResult::refuse($targets, '(不明)');
        }

        $labels = [];
        foreach ($targets as $target) {
            $result = self::checkTarget($target, $environment, $allowFlag);
            if (! $result->ok) {
                return $result;
            }
            $labels[] = $result->target;
        }

        return GuardResult::allow(implode(' / ', $labels));
    }

    /**
     * テストの実行（RefreshDatabase などが DB を作り直す前）に使う判定。
     * アプリの環境の判定に頼らず、環境が testing で、実際に使う接続の DB 名が testing であることを両方求める。
     * 設定がキャッシュされていると phpunit.xml の環境変数が効かないので、それだけで中止する
     *
     * @param  array<string, mixed>  $connection  既定の接続の設定
     * @param  string  $actualDatabase  実際に使う接続の DB 名（DB::connection()->getDatabaseName()）
     */
    public static function checkTestRun(array $connection, string $environment, bool $configurationCached, string $actualDatabase): GuardResult
    {
        if ($configurationCached) {
            return GuardResult::refuse('設定がキャッシュされています（phpunit.xml の環境変数が効きません）。sail artisan config:clear してからテストを実行してください', $actualDatabase);
        }
        if ($environment !== 'testing') {
            return GuardResult::refuse("テストの環境が testing ではありません（APP_ENV={$environment}）", $actualDatabase);
        }
        if ($actualDatabase !== self::TEST_DATABASE) {
            return GuardResult::refuse('テストはテスト用 DB（'.self::TEST_DATABASE."）でだけ実行します（接続先の DB: {$actualDatabase}）", $actualDatabase);
        }

        return self::check($connection, 'testing', null);
    }

    /** 名前で指定した接続を、今の設定・環境変数で判定する */
    public static function checkConnection(string $name): GuardResult
    {
        $connection = config("database.connections.{$name}");
        if (! is_array($connection)) {
            return GuardResult::refuse("DB の接続 {$name} が設定されていません", $name);
        }

        // config:cache の後も実行時の環境変数を読めるよう、config ではなく Env から読む
        $flag = Env::get(self::ALLOW_ENV);

        return self::check($connection, (string) app()->environment(), is_scalar($flag) ? (string) $flag : null);
    }

    /** アプリの既定の接続を、今の設定・環境変数で判定する */
    public static function checkDefaultConnection(): GuardResult
    {
        $name = config('database.default');

        return self::checkConnection(is_string($name) ? $name : '');
    }

    /**
     * データを消すコマンドの実行直前に、実際に使う接続（--database の指定があればその接続）を判定し、
     * 危ない接続先なら例外で止める（AppServiceProvider で CommandStarting に登録する）
     */
    public static function ensureCommandIsSafe(CommandStarting $event): void
    {
        if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        $database = $event->input->hasOption('database') ? $event->input->getOption('database') : null;
        $default = config('database.default');
        $name = is_string($database) && $database !== '' ? $database : (is_string($default) ? $default : '');

        $result = self::checkConnection($name);
        if (! $result->ok) {
            throw new RuntimeException("{$event->command} を中止しました（接続 {$name}）: {$result->reason}");
        }
    }

    public static function isLocalHost(string $host): bool
    {
        $normalized = strtolower(trim($host, ' []'));

        return in_array($normalized, self::LOCAL_HOSTS, true);
    }

    /**
     * 実際に書き込む先の接続設定。Laravel の ConnectionFactory と同じく、read があるときだけ
     * write（候補の配列なら全候補）をトップレベルに上書きしたものを使う。解釈できない形式なら理由を返す
     *
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>|string
     */
    private static function writeTargets(array $connection): array|string
    {
        // DB_URL があれば Laravel と同じ方法で host・database に展開する
        $config = (new ConfigurationUrlParser)->parseConfiguration($connection);

        if (! isset($config['read'])) {
            return [Arr::except($config, ['read', 'write'])];
        }

        $write = $config['write'] ?? null;
        if (! is_array($write)) {
            return '読み書き分離の設定（write）を解釈できません';
        }
        $candidates = isset($write[0]) ? $write : [$write];

        $targets = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                return '読み書き分離の設定（write の候補）を解釈できません';
            }
            $targets[] = Arr::except(array_merge($config, $candidate), ['read', 'write']);
        }

        return $targets;
    }

    /** @param  array<string, mixed>  $config  書き込む先 1 つ分の接続設定 */
    private static function checkTarget(array $config, string $environment, ?string $allowFlag): GuardResult
    {
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        $hosts = self::hosts($config);
        if (is_string($hosts)) {
            return GuardResult::refuse($hosts, "(不明)/{$database}");
        }
        $port = is_scalar($config['port'] ?? null) ? (string) $config['port'] : '';
        $target = sprintf('%s:%s/%s', $hosts === [] ? '(socket)' : implode(',', $hosts), $port, $database);

        if ($driver !== 'mysql') {
            return GuardResult::refuse("MySQL 以外の接続（{$driver}）では実行しません", $target);
        }
        if ($database === '') {
            return GuardResult::refuse('接続先の DB 名が設定されていません', $target);
        }
        if ($environment === 'testing' && $database !== self::TEST_DATABASE) {
            return GuardResult::refuse('テスト（APP_ENV=testing）はテスト用 DB（'.self::TEST_DATABASE."）でだけ実行します（接続先: {$target}）", $target);
        }
        if ($environment !== 'testing' && $database === self::TEST_DATABASE) {
            return GuardResult::refuse('テスト用 DB（'.self::TEST_DATABASE."）はテスト専用です（APP_ENV={$environment}）", $target);
        }
        if (! in_array($environment, self::SAFE_ENVIRONMENTS, true) && $allowFlag !== '1') {
            return GuardResult::refuse("ローカルの開発環境以外（APP_ENV={$environment}）では、DB（{$target}）のデータは消去しません。意図した操作なら ".self::ALLOW_ENV.'=1 を設定してください', $target);
        }
        $remote = array_filter($hosts, fn (string $host) => ! self::isLocalHost($host));
        if ($remote !== [] && $allowFlag !== '1') {
            return GuardResult::refuse("ローカル以外の DB（{$target}）のデータは消去しません。意図した操作なら ".self::ALLOW_ENV.'=1 を設定してください', $target);
        }

        return GuardResult::allow($target);
    }

    /**
     * 接続先のホスト（配列なら全候補）。ソケットで接続する設定ならホストは使われないので空を返す。
     * 解釈できない形式なら理由を返す
     *
     * @param  array<string, mixed>  $config
     * @return list<string>|string
     */
    private static function hosts(array $config): array|string
    {
        // MySqlConnector は unix_socket があればホストを使わずソケットで接続する（同じマシン上の接続）
        if (is_string($config['unix_socket'] ?? null) && $config['unix_socket'] !== '') {
            return [];
        }

        $hosts = Arr::wrap($config['host'] ?? null);
        if ($hosts === []) {
            return '接続先のホストが設定されていません';
        }
        $result = [];
        foreach ($hosts as $host) {
            if (! is_string($host) || trim($host) === '') {
                return '接続先のホストを解釈できません';
            }
            $result[] = $host;
        }

        return array_values(array_unique($result));
    }
}
