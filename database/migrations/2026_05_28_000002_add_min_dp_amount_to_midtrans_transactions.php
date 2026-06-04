<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('midtrans_transactions', function (Blueprint $table) {
            // Batas minimal DP untuk link Sales Order (null = pakai default 50% grand total).
            $table->decimal('min_dp_amount', 18, 2)->nullable()->after('base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_transactions', function (Blueprint $table) {
            $table->dropColumn('min_dp_amount');
        });
    }
};
