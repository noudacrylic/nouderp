<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Expand ENUM
        DB::statement("
            ALTER TABLE sales_order_items 
            MODIFY discount_type 
            ENUM('percent','amount','nominal') 
        ");

        // Convert existing 'amount' or empty values to 'nominal'
        DB::table('sales_order_items')
            ->where('discount_type', 'amount')
            ->orWhere('discount_type', '')
            ->update(['discount_type' => 'nominal']);

        // Restrict ENUM to final target
        DB::statement("
            ALTER TABLE sales_order_items 
            MODIFY discount_type 
            ENUM('percent','nominal') 
            NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            //
        });
    }
};
