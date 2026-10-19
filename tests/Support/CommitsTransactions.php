<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;

/**
 * テストのトランザクション（RefreshDatabase）を張らずに、実際に確定させて動かすテスト用（ファイルで uses(CommitsTransactions::class)）。
 * App\Stock\StockTransaction::run は最も外側のトランザクションとしてだけ動くため、在庫を記録するテストで使う。
 * 確定したデータはテストの前後で全テーブル（migrations を除く）を空にして消す（ほかのテストは空の DB を前提にしている）
 */
trait CommitsTransactions
{
    use DatabaseTruncation;

    /** RefreshDatabase がトランザクションを張る接続（なし） */
    protected array $connectionsToTransact = [];

    protected function tearDownCommitsTransactions(): void
    {
        $this->truncateTablesForAllConnections();
    }
}
