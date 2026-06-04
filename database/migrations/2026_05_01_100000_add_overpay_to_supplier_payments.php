<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('overpay', 18, 2)->default(0)->after('remaining_amount');
            $table->decimal('used_balance', 18, 2)->default(0)->after('overpay');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['overpay', 'used_balance']);
        });
    }
};
