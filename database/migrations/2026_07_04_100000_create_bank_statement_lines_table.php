<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')
                ->constrained('bank_reconciliations')
                ->cascadeOnDelete();
            $table->date('statement_date');
            // Nilai bertanda dari sudut pandang perusahaan: + uang masuk (debit kas),
            // - uang keluar (kredit kas). Disamakan dgn kolom tabel rekonsiliasi ERP.
            $table->decimal('amount', 18, 2);
            $table->string('description')->nullable();
            // journal_line_id yang tercocok saat rekonsiliasi diselesaikan (jejak audit).
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->unsignedInteger('source_row')->nullable(); // no baris Excel utk pesan error
            $table->timestamps();

            $table->index('bank_reconciliation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
