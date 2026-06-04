<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->decimal('service_fee', 18, 2)->nullable()->after('repair_cost');
            $table->decimal('shipping_cost', 18, 2)->nullable()->after('service_fee');
            $table->string('shipping_paid_by', 20)->nullable()->after('shipping_cost'); // 'customer' | 'company'
            $table->unsignedBigInteger('fee_cash_account_id')->nullable()->after('shipping_paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->dropColumn(['service_fee', 'shipping_cost', 'shipping_paid_by', 'fee_cash_account_id']);
        });
    }
};
