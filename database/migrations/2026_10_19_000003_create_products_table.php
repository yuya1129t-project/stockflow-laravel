<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 商品（docs/spec.md §3）。価格・発注点は 0 以上の整数。無効化は is_active = false（論理削除） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // 例 P-0001
            $table->string('name');
            $table->string('category', 50);
            $table->string('unit', 20); // 個／箱／kg など
            $table->integer('sales_price');
            $table->integer('purchase_price');
            $table->integer('reorder_point');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('category');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sales_price_check CHECK (sales_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_purchase_price_check CHECK (purchase_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_reorder_point_check CHECK (reorder_point >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
