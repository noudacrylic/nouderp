<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya layanan/admin marketplace per order.
 *
 * Sebelumnya potongan marketplace (mis. service_fee + order_processing_fee Shopee)
 * dilipat ke global_discount → salah masuk akun Diskon (4003). Kolom ini memisahkan
 * biaya admin marketplace agar saat posting invoice dibukukan ke akun fee yang
 * dimapping (MarketplaceConfig.account_fee_id), dengan pendapatan tetap penuh.
 * Di SO kolom ini hanya pencatatan (tanpa jurnal).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'sales_invoices'] as $table) {
            if (Schema::hasColumn($table, 'marketplace_fee')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('marketplace_fee', 18, 2)->default(0)->after('additional_fee');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'sales_invoices'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('marketplace_fee');
            });
        }
    }
};
