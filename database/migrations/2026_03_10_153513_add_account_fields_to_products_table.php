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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'revenue_account_id')) {
                $table->unsignedBigInteger('revenue_account_id')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('products', 'expense_account_id')) {
                $table->unsignedBigInteger('expense_account_id')->nullable()->after('revenue_account_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['revenue_account_id', 'expense_account_id']);
        });
    }
};
