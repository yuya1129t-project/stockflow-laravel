<?php

/**
 * 種別変更の競合のテスト用のワーカー（tests/Feature/Partners/ConcurrencyTest.php から別プロセスで起動する）。
 * テストとは別の MySQL の接続で、受注を 1 件挿入して「inserted」を出し、指定の時間だけ確定（commit）せずに待ってから確定する。
 * 接続先は起動するテストが環境変数（DB_HOST など。テスト用 DB）で渡す。
 * 使い方: php insert-sales-order-worker.php <顧客の取引先 ID> <確定までの待ち時間（ミリ秒）>
 */
[, $customerId, $holdMs] = $argv;

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO sales_orders (number, customer_id, order_date, status, subtotal, tax, total, created_at, updated_at)
    VALUES (?, ?, CURRENT_DATE, 'draft', 0, 0, 0, NOW(), NOW())")
    ->execute(['SO-TEST-'.getmypid(), (int) $customerId]);
echo "inserted\n";
usleep((int) $holdMs * 1000);
$pdo->commit();
echo "committed\n";
