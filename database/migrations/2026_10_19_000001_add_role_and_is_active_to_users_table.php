<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーにロール（admin／staff／viewer）と有効フラグを追加する（docs/spec.md §2・§3）。
 * enum は文字列の列＋CHECK 制約で表す（他のテーブルも同じ）。値をバイト単位で比べるよう、列の照合順序を
 * utf8mb4_0900_bin（大文字・小文字を区別し、末尾の空白も無視しない NO PAD）にする
 * （既定の utf8mb4_unicode_ci だと 'ADMIN' が、PAD SPACE の utf8mb4_bin だと 'admin ' が CHECK を通ってしまう）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->collation('utf8mb4_0900_bin')->default('viewer')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'staff', 'viewer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
