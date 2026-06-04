<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — booking resi di Surat Jalan.
 * + lengkapi celah Fase 2: simpan service_code kurir di SO & Invoice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('shipping_service_code', 80)->nullable()->after('shipping_courier_code');
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('shipping_service_code', 80)->nullable()->after('shipping_courier_code');
        });

        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->string('shipping_provider', 30)->nullable()->after('delivery_method');     // biteship
            $table->string('shipping_courier_code', 50)->nullable()->after('shipping_provider');
            $table->string('shipping_service_code', 80)->nullable()->after('shipping_courier_code');
            $table->string('provider_order_id', 100)->nullable()->after('shipping_service_code');
            $table->string('collection_method', 20)->nullable()->after('provider_order_id');    // pickup|dropoff
            $table->string('shipping_status', 30)->nullable()->after('collection_method');       // booked|failed|...
            $table->decimal('shipping_cost', 15, 2)->default(0)->after('shipping_status');
            $table->longText('shipping_raw')->nullable()->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('shipping_service_code'));
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('shipping_service_code'));
        Schema::table('sales_deliveries', function (Blueprint $t) {
            $t->dropColumn([
                'shipping_provider', 'shipping_courier_code', 'shipping_service_code',
                'provider_order_id', 'collection_method', 'shipping_status', 'shipping_cost', 'shipping_raw',
            ]);
        });
    }
};
