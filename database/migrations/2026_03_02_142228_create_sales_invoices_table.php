<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();

            $table->string('invoice_number')->unique();

            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();

            $table->date('invoice_date');

            // ===== NILAI DASAR =====
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->enum('global_discount_type', ['nominal', 'percent'])->nullable();
            $table->decimal('global_discount_value', 18, 2)->default(0);
            $table->decimal('global_discount_amount', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);

            $table->decimal('ppn_percent', 5, 2)->default(0);
            $table->decimal('ppn_amount', 18, 2)->default(0);

            $table->decimal('pph_percent', 5, 2)->default(0);
            $table->decimal('pph_amount', 18, 2)->default(0);

            $table->decimal('shipping_cost', 18, 2)->default(0);
            $table->decimal('selling_expense', 18, 2)->default(0);

            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('total_cogs', 18, 2)->default(0);

            $table->enum('status', ['draft', 'posted', 'void', 'paid'])->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
