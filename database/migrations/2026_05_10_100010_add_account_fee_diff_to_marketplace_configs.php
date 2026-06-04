<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_configs', function (Blueprint $table) {
            // Akun untuk selisih biaya admin (fee aktual vs fee config).
            // Positif (fee aktual > config) → debit akun ini (rugi). Negatif (fee aktual < config) → credit (laba).
            $table->foreignId('account_fee_diff_id')->nullable()->after('account_wallet_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_configs', function (Blueprint $table) {
            $table->dropForeign(['account_fee_diff_id']);
            $table->dropColumn('account_fee_diff_id');
        });
    }
};
