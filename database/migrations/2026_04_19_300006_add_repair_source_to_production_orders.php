<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('repair_source_type')->nullable()->after('sales_order_id'); // adjustment, return, warranty
            $table->string('repair_source_ref')->nullable()->after('repair_source_type');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['repair_source_type', 'repair_source_ref']);
        });
    }
};
