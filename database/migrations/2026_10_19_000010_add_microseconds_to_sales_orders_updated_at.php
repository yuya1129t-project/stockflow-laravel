<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 受注の updated_at をマイクロ秒まで持たせる（編集・ステータス変更・出荷の競合検出に使う。App\Orders\OrderVersion）。
 * 発注（2026_10_19_000009）と同じ。秒の精度では、同じ 1 秒の中の 2 回目の更新で値が変わらず、古い画面からの操作を見逃すため
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_orders MODIFY updated_at TIMESTAMP(6) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_orders MODIFY updated_at TIMESTAMP NULL DEFAULT NULL');
    }
};
