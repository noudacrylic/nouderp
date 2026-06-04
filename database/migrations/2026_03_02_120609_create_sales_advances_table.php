<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_advances', function (Blueprint $table) {
            $table->id();

            $table->string('advance_number')->unique();

            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('customer_id');

            $table->date('advance_date');

            $table->decimal('amount', 18, 2);
            $table->decimal('applied_amount', 18, 2)->default(0);

            $table->enum('status', [
                'draft',
                'posted',
                'applied',
                'cancelled'
            ])->default('draft');

            $table->timestamps();

            // optional FK (kalau mau strict)
            // $table->foreign('sales_order_id')->references('id')->on('sales_orders');
            // $table->foreign('customer_id')->references('id')->on('customers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_advances');
    }
};
