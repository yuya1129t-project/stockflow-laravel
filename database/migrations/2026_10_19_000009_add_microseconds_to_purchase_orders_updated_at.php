<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 発注の updated_at をマイクロ秒まで持たせる（編集・ステータス変更の競合検出に使う。App\Orders\OrderVersion）。
 * 秒の精度では、同じ 1 秒の中の 2 回目の更新で値が変わらず、古い画面からの保存を見逃すため
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_orders MODIFY updated_at TIMESTAMP(6) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_orders MODIFY updated_at TIMESTAMP NULL DEFAULT NULL');
    }
};
