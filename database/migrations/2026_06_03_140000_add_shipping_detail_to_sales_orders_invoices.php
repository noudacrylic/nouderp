<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (lanjutan) — embed cek ongkir di SO & Invoice.
 * shipping_cost tetap = ongkir NET yang ditagih ke customer (dipakai grand_total existing).
 * Kolom baru menyimpan rincian: ongkir kotor dari kurir, diskon ongkir, kurir terpilih.
 */
return new class extends Migration {
    private array $tables = ['sales_orders', 'sales_invoices'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->decimal('shipping_gross', 15, 2)->default(0)->after('shipping_cost');
                $table->string('shipping_discount_type', 10)->default('nominal')->after('shipping_gross'); // nominal|percent
                $table->decimal('shipping_discount_value', 15, 2)->default(0)->after('shipping_discount_type');
                $table->string('shipping_courier_code', 50)->nullable()->after('shipping_discount_value');
                $table->string('shipping_service_name', 150)->nullable()->after('shipping_courier_code');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn([
                    'shipping_gross', 'shipping_discount_type', 'shipping_discount_value',
                    'shipping_courier_code', 'shipping_service_name',
                ]);
            });
        }
    }
};
