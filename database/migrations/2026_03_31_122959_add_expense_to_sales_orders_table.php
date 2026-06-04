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
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('expense', 18, 2)->default(0)->after('shipping_cost');
        });

        // Copy existing data from selling_expense to expense if selling_expense exists
        if (Schema::hasColumn('sales_orders', 'selling_expense')) {
            DB::table('sales_orders')->update([
                'expense' => DB::raw('selling_expense')
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
             $table->dropColumn('expense');
        });
    }
};
