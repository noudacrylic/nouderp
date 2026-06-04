<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');

            $table->enum('item_type', ['product', 'service', 'shipping', 'expense'])->default('product');

            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2);

            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->decimal('ppn_percent', 5, 2)->default(0);
            $table->decimal('ppn_amount', 18, 2)->default(0);

            $table->decimal('pph_percent', 5, 2)->default(0);
            $table->decimal('pph_amount', 18, 2)->default(0);

            // ===== COGS SNAPSHOT =====
            $table->decimal('cogs_unit', 18, 2)->default(0);
            $table->decimal('cogs_total', 18, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
    }
};
