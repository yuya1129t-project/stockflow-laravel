<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 発注と発注明細（docs/spec.md §3・§4）。
 * 金額は円の整数で、合計 = 小計 + 税（CHECK 制約）。明細は発注を消すと一緒に消える（cascade）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique(); // PO-YYYYMMDD-NNN
            $table->foreignId('supplier_id')->constrained('partners')->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->collation('utf8mb4_bin')->default('draft');
            $table->text('note')->nullable();
            $table->integer('subtotal')->default(0);
            $table->integer('tax')->default(0);
            $table->integer('total')->default(0);
            $table->timestamps();

            $table->index(['status', 'order_date']);
            $table->index('order_date');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'ordered', 'received', 'cancelled'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_amounts_check CHECK (subtotal >= 0 AND tax >= 0 AND total = subtotal + tax)');

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->integer('unit_price'); // 発注時点の単価（商品マスタの変更の影響を受けない）

            // 同じ発注に同じ商品の明細は 1 行だけ。発注 ID での検索もこの索引（先頭列）を使う
            $table->unique(['purchase_order_id', 'product_id']);
        });

        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_unit_price_check CHECK (unit_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
