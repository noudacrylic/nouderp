<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_receipt_id')->constrained()->cascadeOnDelete();
            // Untuk general: akun pendapatan. Untuk supplier_refund: akun 1108 (piutang lebih bayar supplier).
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('supplier_overpayment_id')->nullable()->constrained('supplier_overpayments')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipt_lines');
    }
};
