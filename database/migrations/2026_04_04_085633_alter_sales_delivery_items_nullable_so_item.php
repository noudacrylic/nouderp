<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_delivery_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_item_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_delivery_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_item_id')->nullable(false)->change();
        });
    }
};
