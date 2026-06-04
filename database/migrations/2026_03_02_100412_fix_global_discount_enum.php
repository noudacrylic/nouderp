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
        // 1. Expand ENUM agar bisa menerima 'nominal'
        DB::statement("
            ALTER TABLE sales_orders 
            MODIFY global_discount_type 
            ENUM('percent','amount','nominal') 
            NULL
        ");

        // 2. Ubah data lama
        DB::table('sales_orders')
            ->whereNull('global_discount_type')
            ->orWhere('global_discount_type', 'amount')
            ->orWhere('global_discount_type', '')
            ->update(['global_discount_type' => 'nominal']);

        // 3. Kunci ke ENUM target utama
        DB::statement("
            ALTER TABLE sales_orders 
            MODIFY global_discount_type 
            ENUM('percent','nominal') 
            NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
