<?php

namespace App\Orders;

use App\Stock\StockTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * 伝票の作成・編集・ステータス変更（在庫を記録しないもの）のトランザクション。受注・発注で共通。
 * 在庫を記録する操作（入荷・出荷）は StockTransaction::run を使う。
 *
 * - ロック待ちの上限は、在庫の記録と同じ StockTransaction::LOCK_WAIT_TIMEOUT 秒にする（セッション単位。終わったら戻す。
 *   MySQL の既定は 50 秒）。取引先・商品・伝票の行のロックは、在庫の記録や CSV 取込が同じ行を持っている間は待つ
 * - デッドロック（1213）のときだけ、最も外側のトランザクションならコールバックごとやり直す（ATTEMPTS 回まで）。
 *   ロック待ちのタイムアウト（1205）はやり直さない（待ち続けるより利用者に返す。Laravel の transaction の attempts は
 *   1205 もやり直すので使わない）
 * - やり直しではコールバックが 2 回以上動くので、コールバックの外のモデルを中で書き換えて save しない（中で取り直す）
 * - 1205・やり直しきれなかった 1213 は PDOException のまま投げる。呼び出し側で isBusy を見て「混み合っています」にする
 */
final class OrderTransaction
{
    public const int ATTEMPTS = 3;

    /** ロック待ちのタイムアウト・やり直しきれなかったデッドロックのときのメッセージ（notDone は行わなかった操作） */
    public static function busyMessage(string $notDone): string
    {
        return "混み合っているため、{$notDone}。しばらくしてからもう一度実行してください";
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public static function run(Closure $callback, int $attempts = self::ATTEMPTS, int $lockWaitTimeout = StockTransaction::LOCK_WAIT_TIMEOUT): mixed
    {
        $connection = DB::connection();
        $previous = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS timeout');
        $previousTimeout = is_object($previous) && isset($previous->timeout) && is_numeric($previous->timeout) ? (int) $previous->timeout : 50;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = ?', [$lockWaitTimeout]);
        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    return $connection->transaction($callback);
                } catch (PDOException $e) {
                    // 外側にトランザクションがある（テストなど）ときは、ここではやり直せない（外側ごと取り消されている）
                    if ($attempt >= $attempts || ! StockTransaction::isDeadlock($e) || $connection->transactionLevel() > 0) {
                        throw $e;
                    }
                }
            }
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = ?', [$previousTimeout]);
        }
    }

    /** ロック待ちのタイムアウト（1205）か、デッドロック（1213）か */
    public static function isBusy(PDOException $e): bool
    {
        return StockTransaction::isLockWaitTimeout($e) || StockTransaction::isDeadlock($e);
    }
}
