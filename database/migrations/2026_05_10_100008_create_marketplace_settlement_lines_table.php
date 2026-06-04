<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_settlement_id')->constrained()->cascadeOnDelete();
            $table->string('order_ref')->nullable();
            $table->date('settlement_date')->nullable();
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('fee_actual', 18, 2)->default(0);
            $table->decimal('fee_config', 18, 2)->default(0);
            $table->decimal('fee_diff', 18, 2)->default(0); // positif = fee aktual lebih besar (rugi)
            $table->decimal('net_amount', 18, 2)->default(0);
            // Match status
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->boolean('is_matched')->default(false);
            $table->text('raw_row')->nullable(); // JSON dari row excel utk audit
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('order_ref');
            $table->index('sales_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settlement_lines');
    }
};
