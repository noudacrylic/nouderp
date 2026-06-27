<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_outputs', function (Blueprint $table) {
            // Alokasi hasil produksi per gudang: [{warehouse_id, qty}, ...].
            // Null = semua masuk gudang order (perilaku lama, default gudang Utama).
            $table->json('warehouse_allocations')->nullable()->after('variance_notes');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_outputs', function (Blueprint $table) {
            $table->dropColumn('warehouse_allocations');
        });
    }
};
