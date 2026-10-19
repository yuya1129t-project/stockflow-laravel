<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 入出庫履歴（docs/spec.md §3）。現在庫 = quantity（符号付き）の合計。履歴は更新・削除しない（updated_at なし）。
 * ref_type＋ref_id で発生元の伝票（受注・発注）を指す。棚卸調整は両方 null
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 10)->collation('utf8mb4_bin');
            $table->integer('quantity'); // in は正、out は負、adjust は 0 以外
            $table->string('reason')->nullable();
            $table->string('ref_type', 20)->collation('utf8mb4_bin')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            // 履歴を残すため、ユーザーは物理削除せず無効化する（参照があれば削除できない）
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['ref_type', 'ref_id']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('in', 'out', 'adjust'))");
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (
                (type = 'in' AND quantity > 0)
                OR (type = 'out' AND quantity < 0)
                OR (type = 'adjust' AND quantity <> 0)
            )
            SQL);
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_ref_type_check CHECK (ref_type IS NULL OR ref_type IN ('sales_order', 'purchase_order'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_ref_check CHECK ((ref_type IS NULL) = (ref_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
