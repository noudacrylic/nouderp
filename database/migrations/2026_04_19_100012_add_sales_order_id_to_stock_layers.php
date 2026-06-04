<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_order_id')->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->dropColumn('sales_order_id');
        });
    }
};
