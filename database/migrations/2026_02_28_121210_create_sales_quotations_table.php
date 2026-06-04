<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();

            $table->string('quotation_number')->nullable()->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->date('quotation_date');
            $table->date('valid_until')->nullable();

            $table->decimal('subtotal_product', 18, 2)->default(0);
            $table->decimal('discount_global', 18, 2)->default(0);
            $table->decimal('shipping_charge', 18, 2)->default(0);
            $table->decimal('service_charge', 18, 2)->default(0);
            $table->decimal('other_expense', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);

            $table->enum('status', ['draft', 'sent', 'converted', 'cancelled'])
                ->default('draft');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_quotations');
    }
};
