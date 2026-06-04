<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->id();

            $table->string('delivery_number')->unique();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->date('delivery_date');

            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable();

            $table->enum('status', ['draft', 'posted', 'void'])
                ->default('draft');

            $table->timestamps();

            $table->foreign('sales_order_id')
                ->references('id')
                ->on('sales_orders')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_deliveries');
    }
};
