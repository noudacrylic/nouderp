<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_disbursement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_disbursement_id')->constrained()->cascadeOnDelete();
            // Untuk general: akun beban. Untuk freight: akun titipan ongkir (1203). Untuk customer_refund: akun overpay (2106).
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            // Optional links untuk context (freight → invoice, customer_refund → overpay row)
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('customer_overpayment_id')->nullable()->constrained('customer_overpayments')->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_disbursement_lines');
    }
};
