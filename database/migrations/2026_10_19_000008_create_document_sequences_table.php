<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 伝票番号の採番（SO-YYYYMMDD-NNN／PO-YYYYMMDD-NNN の NNN）。種類＋日付ごとに最後の番号を持つ */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('kind', 2)->collation('utf8mb4_bin');
            $table->date('date');
            $table->integer('last_number')->default(0);

            $table->primary(['kind', 'date']);
        });

        DB::statement("ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_kind_check CHECK (kind IN ('SO', 'PO'))");
        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_last_number_check CHECK (last_number >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
