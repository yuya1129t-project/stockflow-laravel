<?php

namespace App\Support\Database;

use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;

/**
 * DB のデータを消す操作（デモデータのシード・migrate:fresh など）の前に、接続先が安全かを判定する。
 *
 * - ローカル（localhost／127.0.0.1／::1／Sail の mysql）以外の DB は、
 *   環境変数 STOCKFLOW_ALLOW_DESTRUCTIVE_SEED=1 がない限り拒否する（デモ環境の定期リセットでだけ設定する想定）
 * - テスト用 DB（testing）の取り違えを防ぐ: テスト（APP_ENV=testing）は testing でだけ、
 *   それ以外の環境は testing 以外でだけ実行する（この条件は環境変数でも外せない）
 *
 * 判定は接続設定（config/database.php）だけで行い、DB には接続しない
 */
final class DestructiveOperationGuard
{
    /** ローカル以外の DB への破壊操作を許可する環境変数 */
    public const string ALLOW_ENV = 'STOCKFLOW_ALLOW_DESTRUCTIVE_SEED';

    /** テスト用 DB の名前（phpunit.xml・CI と同じ） */
    public const string TEST_DATABASE = 'testing';

    /** ローカルとみなすホスト（mysql は Sail の Docker Compose のサービス名） */
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', 'mysql'];

    /**
     * @param  array<string, mixed>  $connection  接続設定（config('database.connections.<名前>')）
     * @param  string  $environment  アプリの環境（APP_ENV）
     * @param  string|null  $allowFlag  STOCKFLOW_ALLOW_DESTRUCTIVE_SEED の値
     */
    public static function check(array $connection, string $environment, ?string $allowFlag): GuardResult
    {
        // DB_URL があれば Laravel と同じ方法で host・database に展開する
        $config = (new ConfigurationUrlParser)->parseConfiguration($connection);

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        $hosts = self::hosts($config);
        $target = sprintf('%s:%s/%s', implode(',', $hosts) ?: '(socket)', self::scalar($config['port'] ?? ''), $database);

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

        $remote = array_values(array_filter($hosts, fn (string $host) => ! self::isLocalHost($host)));
        if ($remote !== [] && $allowFlag !== '1') {
            return GuardResult::refuse('ローカル以外の DB（'.$target.'）のデータは消去しません。意図した操作なら '.self::ALLOW_ENV.'=1 を設定してください', $target);
        }

        return GuardResult::allow($target);
    }

    /** アプリの既定の接続を、今の設定・環境変数で判定する */
    public static function checkDefaultConnection(): GuardResult
    {
        $name = config('database.default');
        $connection = config('database.connections.'.(is_string($name) ? $name : ''));
        // config:cache の後も実行時の環境変数を読めるよう、config ではなく Env から読む
        $flag = Env::get(self::ALLOW_ENV);

        return self::check(
            is_array($connection) ? $connection : [],
            (string) app()->environment(),
            is_scalar($flag) ? (string) $flag : null,
        );
    }

    public static function isLocalHost(string $host): bool
    {
        $normalized = strtolower(trim($host, ' []'));

        return in_array($normalized, self::LOCAL_HOSTS, true);
    }

    /**
     * 接続先のホスト。読み書き分離（read／write）の設定があればすべてを返す。
     * ホストがなくソケットだけのときは空（同じマシン上の接続）
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function hosts(array $config): array
    {
        $hosts = [];
        foreach ([$config, $config['read'] ?? [], $config['write'] ?? []] as $part) {
            if (! is_array($part) || ! array_key_exists('host', $part)) {
                continue;
            }
            foreach ((array) $part['host'] as $host) {
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                }
            }
        }
        if ($hosts === [] && empty($config['unix_socket'])) {
            // ホストもソケットもない設定は判定できないので、ローカル扱いにしない
            $hosts[] = '(未設定)';
        }

        return array_values(array_unique($hosts));
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
