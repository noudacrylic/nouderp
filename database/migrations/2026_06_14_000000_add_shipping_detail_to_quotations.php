<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embed cek ongkir di Penawaran (mirror SO/Invoice).
 * shipping_charge tetap = ongkir NET yang ditawarkan ke customer (dipakai grand_total existing).
 * Kolom baru menyimpan rincian: ongkir kotor dari kurir, diskon ongkir, kurir terpilih, dimensi paket.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->decimal('shipping_gross', 15, 2)->default(0)->after('shipping_charge');
            $table->string('shipping_discount_type', 10)->default('nominal')->after('shipping_gross'); // nominal|percent
            $table->decimal('shipping_discount_value', 15, 2)->default(0)->after('shipping_discount_type');
            $table->string('shipping_courier_code', 50)->nullable()->after('shipping_discount_value');
            $table->string('shipping_service_code', 80)->nullable()->after('shipping_courier_code');
            $table->string('shipping_service_name', 150)->nullable()->after('shipping_service_code');
            $table->decimal('package_length', 8, 2)->nullable()->after('shipping_service_name');
            $table->decimal('package_width', 8, 2)->nullable()->after('package_length');
            $table->decimal('package_height', 8, 2)->nullable()->after('package_width');
            $table->date('pickup_date')->nullable()->after('package_height');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_gross', 'shipping_discount_type', 'shipping_discount_value',
                'shipping_courier_code', 'shipping_service_code', 'shipping_service_name',
                'package_length', 'package_width', 'package_height', 'pickup_date',
            ]);
        });
    }
};
