<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table) {
            // Untuk item bundle: kondisi per KOMPONEN (utuh/perbaikan/rusak) agar tiap
            // komponen dirutekan sendiri (persediaan / Gudang Perbaikan / beban kerugian).
            // Format: { "<component_product_id>": "good|repair|damaged", ... }. NULL utk non-bundle.
            $table->json('component_conditions')->nullable()->after('condition');
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->dropColumn('component_conditions');
        });
    }
};
