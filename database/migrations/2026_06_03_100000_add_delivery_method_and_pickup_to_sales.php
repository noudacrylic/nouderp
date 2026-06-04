<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — metode pengiriman (kurir / ambil_toko) + sistem booking code untuk
 * ambil di toko. delivery_method mengalir: Penawaran → SO → Invoice → Surat Jalan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('kurir')->after('warehouse_id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('kurir')->after('warehouse_id');
            $table->string('pickup_code', 30)->nullable()->unique()->after('delivery_method');
            $table->string('pickup_status', 20)->nullable()->after('pickup_code'); // null|pending|picked_up
            $table->timestamp('picked_up_at')->nullable()->after('pickup_status');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('kurir')->after('warehouse_id');
        });

        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('kurir')->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', fn (Blueprint $t) => $t->dropColumn('delivery_method'));
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropColumn(['delivery_method', 'pickup_code', 'pickup_status', 'picked_up_at']);
        });
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('delivery_method'));
        Schema::table('sales_deliveries', fn (Blueprint $t) => $t->dropColumn('delivery_method'));
    }
};
