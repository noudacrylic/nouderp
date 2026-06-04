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
        Schema::table('sales_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_deliveries', 'reference_type')) {
                $table->string('reference_type')->nullable()->after('delivery_number');
            }
            if (!Schema::hasColumn('sales_deliveries', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }
            if (Schema::hasColumn('sales_deliveries', 'sales_order_id')) {
                $table->dropForeign(['sales_order_id']);
                $table->dropColumn('sales_order_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable()->after('delivery_number');
            $table->dropColumn(['reference_type', 'reference_id']);
        });
    }
};
