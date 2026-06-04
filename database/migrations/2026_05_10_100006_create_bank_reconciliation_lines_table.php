<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->cascadeOnDelete();
            $table->boolean('is_matched')->default(false);
            $table->date('statement_date')->nullable(); // tanggal di rekening koran (opsional, untuk dokumentasi)
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'journal_line_id'], 'br_jl_unique');
            $table->index('journal_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
    }
};
