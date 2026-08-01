<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider kurir yang dipilih saat Cek Ongkir, ikut tersimpan di SO/Invoice.
 *
 * Sebelumnya hanya kode kurir & layanan yang disimpan, dan booking resi selalu
 * menganggapnya milik Biteship. Begitu ada lebih dari satu agregator (Jubelio Shipment
 * memakai ID numerik, RajaOngkir hanya cek ongkir), kode saja tidak cukup untuk tahu
 * ke mana resi harus dipesan.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'sales_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'shipping_provider')) {
                    $t->string('shipping_provider', 30)->nullable()->after('shipping_courier_code');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'sales_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'shipping_provider')) {
                    $t->dropColumn('shipping_provider');
                }
            });
        }
    }
};
