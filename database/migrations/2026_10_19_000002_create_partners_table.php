<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 取引先（docs/spec.md §3）。郵便番号はハイフンなし 7 桁で保存する */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // 例 C-0001／S-0001
            $table->string('name');
            $table->string('kind', 20)->collation('utf8mb4_bin');
            $table->char('postal_code', 7)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index(['kind', 'is_active']);
        });

        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_kind_check CHECK (kind IN ('customer', 'supplier', 'both'))");
        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_postal_code_check CHECK (postal_code IS NULL OR postal_code REGEXP '^[0-9]{7}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
