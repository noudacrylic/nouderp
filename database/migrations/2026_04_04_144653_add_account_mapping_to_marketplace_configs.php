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
        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->foreignId('account_receivable_hold_id')->nullable()->after('is_active');
            $table->foreignId('account_fee_id')->nullable()->after('account_receivable_hold_id');
            $table->foreignId('account_wallet_id')->nullable()->after('account_fee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->dropColumn(['account_receivable_hold_id', 'account_fee_id', 'account_wallet_id']);
        });
    }
};
