<?php

namespace App\Stock;

use App\Models\Product;
use App\Models\StockMovement;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * 在庫を記録するトランザクション（入出庫の記録の共通部品）。棚卸調整（#6）・発注の入荷（#7）・受注の出荷（#8）・
 * 発注点割れの通知（#11）から使う。
 *
 * 使い方（伝票の更新なども同じコールバックの中で書く。不足の例外で全体が取り消される）:
 *
 *     $result = StockTransaction::run(function (StockTransaction $stock) use ($order, $user) {
 *         $products = $stock->lockProducts($order->productIds());   // 対象の全商品を一度に（有効などの検査はこの結果で）
 *         // … 伝票の更新（UPDATE … WHERE status = 'confirmed' の件数で二重処理を防ぐ など）
 *         return $stock->record($user->id, $entries);                // 不足なら InsufficientStockException
 *     });
 *     // 確定後: $result->fellBelowReorderPoint を通知する（#11）
 *
 * 同時実行の考え方（MySQL 8.4／InnoDB）:
 * - 現在庫は入出庫の合計で、在庫数の列は持たない（spec §3）。「合計を確認 → 出庫を INSERT」をそのまま行うと、同時に出庫した
 *   2 つのトランザクションが両方とも確認を通り、在庫が負になる
 * - そこで記録の前に、対象商品の products の行を id の順に SELECT … FOR UPDATE（lockForUpdate）でロックしてから合計を読む。
 *   同じ商品を扱うトランザクションはここで順番待ちになり、後のトランザクションは先の記録を含めた合計で判定する
 * - 分離レベルは READ COMMITTED（config/database.php で接続ごとに設定し、ここでも確かめる）。REPEATABLE READ では、ロックより前に
 *   同じトランザクションで普通の SELECT をしていると、ロックの後の合計がそのときのスナップショットのままになり、先に確定した
 *   出庫を見落とす（InnoDB で確認済み）
 * - MySQL には PostgreSQL の FOR NO KEY UPDATE がないので、FOR UPDATE は、明細（sales_order_lines など）の INSERT の外部キー検査が
 *   products の行に取る共有ロックとも競合する。明細を入力の順番で INSERT するトランザクションと交差するとデッドロックしうる
 *   （InnoDB で再現。負けるのは在庫の側）。対策は 2 つ:
 *     1. 再試行: run は既定で ATTEMPTS 回まで、デッドロック・ロック待ちのタイムアウトのときにコールバックごとやり直す
 *        （最も外側のトランザクションのときだけ。コールバックの中では DB 以外の副作用（通知・外部 API）を起こさない）
 *     2. 明細を書く側（#7・#8 の伝票の作成・編集）は、明細を INSERT する前に、明細の全商品の行を id の順に
 *        SELECT … FOR SHARE でまとめてロックする（外部キー検査の共有ロックを入力の順番で取らない）。こうすればデッドロックしない
 *
 * 呼び出しの契約（守らないとデッドロック・負在庫になりうる）:
 * - 記録は run のコールバックの中で、渡された StockTransaction を使って行う。StockTransaction は run の中でしか作れず
 *   （コンストラクターは private）、コールバックの外（終わった後）で使うと例外にする。run の入れ子も例外にする
 * - 対象の全商品を一度に渡す（lockProducts か record の最初の呼び出しで）。同じトランザクションで別の商品のロックを
 *   あとから追加しない（例外にする。同じ商品・その一部を再びロックするのは問題ない）
 * - 同じトランザクションで、lockProducts／record より前に、対象の商品の行に共有ロックを取らない（FOR SHARE・明細の INSERT の
 *   外部キー検査）。共有ロックを持った 2 本が排他ロックへの引き上げを互いに待ってデッドロックする。明細を書くなら
 *   「lockProducts（全商品）→ 伝票・明細の書き込み → record」の順にする（排他ロックを持っていれば外部キー検査は待たない）
 * - 商品の行をロックするほかの処理（商品の編集・CSV 取込）も id の順で、対象を一度にロックしている
 */
final class StockTransaction
{
    /** デッドロック・ロック待ちのタイムアウトのときの試行回数の既定値 */
    public const int ATTEMPTS = 3;

    /** 必要な分離レベル（@@transaction_isolation の値） */
    private const string REQUIRED_ISOLATION = 'READ-COMMITTED';

    /** 実行中の run の数（入れ子を拒否する） */
    private static int $running = 0;

    /** コールバックの実行中だけ true */
    private bool $open = true;

    /** @var list<int>|null 最初にロックした商品（この後のロックはこの範囲に限る） */
    private ?array $lockedIds = null;

    private function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $level,
    ) {}

    /**
     * 在庫を記録するトランザクションを開始し、コールバックに StockTransaction を渡す。
     * コールバックが例外を投げたら（InsufficientStockException を含む）全体を取り消し、その例外をそのまま投げる。
     * デッドロック・ロック待ちのタイムアウトは attempts 回までやり直す（最も外側のトランザクションのとき）
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public static function run(Closure $callback, int $attempts = self::ATTEMPTS): mixed
    {
        if (self::$running > 0) {
            throw new LogicException('StockTransaction::run を入れ子にしないでください（対象の全商品を 1 つのトランザクションで一度にロックします）');
        }
        $connection = DB::connection();

        return $connection->transaction(function () use ($callback, $connection) {
            $isolation = $connection->selectOne('SELECT @@transaction_isolation AS level');
            if (! is_object($isolation) || ($isolation->level ?? null) !== self::REQUIRED_ISOLATION) {
                throw new LogicException('在庫を記録するトランザクションの分離レベルが READ COMMITTED ではありません（config/database.php の isolation_level）');
            }
            $stock = new self($connection, $connection->transactionLevel());
            self::$running++;
            try {
                return $callback($stock);
            } finally {
                $stock->open = false;
                self::$running--;
            }
        }, $attempts);
    }

    /**
     * 対象商品の行を id の順に FOR UPDATE でロックし、ロックを取った後の現在庫を読む。ロックはトランザクションの終わりまで続く。
     * 対象の全商品を一度に渡すこと。存在しない商品は結果に含まれない
     *
     * @param  list<int>  $productIds
     * @return array<int, StockProduct> 商品 ID → 状態（商品 ID の順）
     */
    public function lockProducts(array $productIds): array
    {
        $this->assertOpen();
        $ids = array_values(array_unique($productIds));
        sort($ids);
        if ($ids === []) {
            return [];
        }
        if ($this->lockedIds === null) {
            $this->lockedIds = $ids;
        } elseif (($added = array_diff($ids, $this->lockedIds)) !== []) {
            throw new LogicException('同じトランザクションで別の商品のロックをあとから追加しないでください（対象の全商品を最初に一度に渡します）: '.implode(', ', $added));
        }

        $products = Product::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'name', 'unit', 'reorder_point', 'is_active']);
        // ロックを取った後の文で読むので（READ COMMITTED）、先に記録して確定したトランザクションの入出庫も合計に含まれる
        $stocks = (new StockQuery)->stocks(array_values($products->map(fn (Product $p) => $p->id)->all()));

        $result = [];
        foreach ($products as $p) {
            $result[$p->id] = new StockProduct($p->id, $p->code, $p->name, $p->unit, $p->reorder_point, $p->is_active, $stocks[$p->id] ?? 0);
        }

        return $result;
    }

    /**
     * 入出庫をまとめて記録する（受注の出荷のように複数の明細を一括で）。
     *
     * 1. 対象商品の行をロックして現在庫を読む（lockProducts）
     * 2. 記録後に在庫が負になる商品が 1 つでもあれば InsufficientStockException（何も記録しない。トランザクションごと取り消される）
     * 3. 入出庫を INSERT する
     *
     * 存在しない商品を指定したのはプログラムの誤りなので InvalidArgumentException（数量の符号・参照元は StockEntry を作るときに検査済み）
     *
     * @param  int|null  $userId  記録したユーザー
     * @param  list<StockEntry>  $entries
     *
     * @throws InsufficientStockException
     */
    public function record(?int $userId, array $entries): StockRecordResult
    {
        $this->assertOpen();
        if ($entries === []) {
            return new StockRecordResult([], []);
        }

        $productIds = array_values(array_unique(array_map(fn (StockEntry $e) => $e->productId, $entries)));
        $products = $this->lockProducts($productIds);
        $missing = array_values(array_diff($productIds, array_keys($products)));
        if ($missing !== []) {
            throw new InvalidArgumentException('商品が見つかりません: '.implode(', ', $missing));
        }

        $plan = StockPlan::make($products, $entries);
        if ($plan->shortages !== []) {
            throw new InsufficientStockException($plan->shortages);
        }

        $now = now();
        StockMovement::query()->insert(array_map(fn (StockEntry $e) => [
            'product_id' => $e->productId,
            'type' => $e->type->value,
            'quantity' => $e->quantity,
            'reason' => $e->reason,
            'ref_type' => $e->refType?->value,
            'ref_id' => $e->refId,
            'user_id' => $userId,
            'created_at' => $now,
        ], $entries));

        return new StockRecordResult($plan->changes, $plan->fellBelowReorderPoint);
    }

    /** run のコールバックの実行中で、トランザクションが続いていること（外に持ち出した・途中で確定させた、を拒否する） */
    private function assertOpen(): void
    {
        if (! $this->open || $this->connection->transactionLevel() < $this->level) {
            throw new LogicException('在庫の記録は StockTransaction::run のコールバックの中で、渡された StockTransaction を使って行ってください（トランザクションの外ではロックが効きません）');
        }
    }
}
