<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename column in sales_orders
        if (Schema::hasColumn('sales_orders', 'expense')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->renameColumn('expense', 'additional_fee');
            });
        }

        // 2. Rename column in sales_invoices
        if (Schema::hasColumn('sales_invoices', 'selling_expense')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->renameColumn('selling_expense', 'additional_fee');
            });
        }

        // 3. Ensure account 4002 exists
        $exists = DB::table('accounts')->where('code', '4002')->exists();
        if (!$exists) {
            DB::table('accounts')->insert([
                'code' => '4002',
                'name' => 'Pendapatan Tambahan',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'is_system' => 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Update if already exists but different name
            DB::table('accounts')->where('code', '4002')->update([
                'name' => 'Pendapatan Tambahan',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'additional_fee')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->renameColumn('additional_fee', 'expense');
            });
        }

        if (Schema::hasColumn('sales_invoices', 'additional_fee')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->renameColumn('additional_fee', 'selling_expense');
            });
        }
    }
};
