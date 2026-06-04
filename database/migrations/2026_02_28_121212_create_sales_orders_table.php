<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {

            $table->id();

            $table->string('order_number')->unique();

            $table->foreignId('quotation_id')
                ->nullable()
                ->unique()
                ->constrained('sales_quotations')
                ->nullOnDelete();

            $table->string('customer_po_number')->nullable();

            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->date('order_date');

            // Financial Summary
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);

            // Tax Global
            $table->decimal('ppn_percent', 5, 2)->default(0);
            $table->decimal('ppn_amount', 18, 2)->default(0);

            $table->decimal('pph_percent', 5, 2)->default(0);
            $table->decimal('pph_amount', 18, 2)->default(0);

            // Additional
            $table->decimal('shipping_cost', 18, 2)->default(0);
            $table->decimal('selling_expense', 18, 2)->default(0);

            $table->decimal('grand_total', 18, 2)->default(0);

            $table->enum('status', ['draft', 'confirmed', 'cancelled'])
                ->default('draft');

            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->restrictOnDelete();

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
