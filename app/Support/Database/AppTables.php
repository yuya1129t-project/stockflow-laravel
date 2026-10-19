<?php

namespace App\Support\Database;

/**
 * このアプリのテーブル。デモデータのシード（database/seeders/DatabaseSeeder.php）で中身を消す対象。
 *
 * 参照する側を先に並べている（この順に DELETE すれば外部キーに引っかからない）。
 * フレームワークの管理用テーブル（FRAMEWORK_TABLES）は消さない。
 * マイグレーションとずれていないことはテスト（tests/Feature/Database/SchemaConstraintsTest.php の最後のケース）で確認する
 */
final class AppTables
{
    /** @var list<string> */
    public const array ALL = [
        'sales_order_lines',
        'purchase_order_lines',
        'stock_movements',
        'audit_logs',
        'passkeys',
        'sessions',
        'password_reset_tokens',
        'document_sequences',
        'sales_orders',
        'purchase_orders',
        'products',
        'partners',
        'users',
    ];

    /** @var list<string> シードで消さないテーブル（マイグレーション履歴・キャッシュ・キュー） */
    public const array FRAMEWORK_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];
}
