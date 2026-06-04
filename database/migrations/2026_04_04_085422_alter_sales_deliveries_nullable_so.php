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
        Schema::table('sales_deliveries', function (Blueprint $table) {
            // Check if foreign exists before dropping. In Laravel, it's usually table_column_foreign
            // But sometimes it might be different. Let's try simple change first.
            // If it fails because of foreign key, we'll need a more complex one.
            
            $table->unsignedBigInteger('sales_order_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable(false)->change();
        });
    }
};
