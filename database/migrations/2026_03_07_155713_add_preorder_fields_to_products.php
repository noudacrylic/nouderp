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
            if (!Schema::hasColumn('products', 'preorder_stock')) {
                $table->integer('preorder_stock')->nullable()->after('sale_type');
            }
            if (!Schema::hasColumn('products', 'lead_time_days')) {
                $table->integer('lead_time_days')->nullable()->after('preorder_stock');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['preorder_stock']);
            // We don't drop lead_time_days as it was already present
        });
    }
};
