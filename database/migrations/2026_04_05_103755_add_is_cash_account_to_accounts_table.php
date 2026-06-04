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
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash_account')->default(false)->after('is_active');
        });

        // Initialize existing cash accounts based on account_category
        \App\Core\Accounting\Account::whereIn('account_category', ['cash', 'cash_equivalent'])
            ->update(['is_cash_account' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash_account');
        });
    }
};
