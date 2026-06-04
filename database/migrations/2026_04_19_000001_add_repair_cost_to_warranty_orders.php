<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->decimal('repair_cost', 18, 2)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_orders', function (Blueprint $table) {
            $table->dropColumn('repair_cost');
        });
    }
};
