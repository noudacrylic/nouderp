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
        Schema::table('sales_quotation_items', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->after('product_id');
            $table->string('unit_name')->nullable()->after('unit_id');
            $table->decimal('conversion_to_base', 12, 4)->default(1)->after('unit_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_quotation_items', function (Blueprint $table) {
            $table->dropColumn(['unit_id', 'unit_name', 'conversion_to_base']);
        });
    }
};
