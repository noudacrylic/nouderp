<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // Untuk filter "rekonsiliasi sudah dilakukan untuk bulan X"
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('book_balance', 18, 2)->default(0); // saldo akhir per buku (auto)
            $table->decimal('statement_balance', 18, 2)->default(0); // saldo akhir per koran (input)
            $table->decimal('difference', 18, 2)->default(0);
            $table->enum('status', ['draft', 'completed', 'void'])->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'period_year', 'period_month']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
