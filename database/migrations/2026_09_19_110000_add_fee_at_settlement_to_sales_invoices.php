<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda konvensi nilai faktur marketplace.
 *
 * Faktur marketplace GAYA BARU terbit saat PENGIRIMAN, mengikuti Sales Order persis:
 * `grand_total` = nilai KOTOR dan `marketplace_fee` = 0. Biaya admin baru dibukukan saat
 * pesanan selesai oleh MarketplaceEngineService, yang lalu menuliskan nominalnya ke
 * `marketplace_fee` sebagai catatan "sudah dibebankan" — TANPA menurunkan `grand_total`.
 *
 * Faktur LAMA (7.372 buah) memakai konvensi sebaliknya: `grand_total` sudah BERSIH dan
 * biaya adminnya ikut dijurnal di faktur itu sendiri.
 *
 * Tanpa penanda ini, rekonsiliasi tak bisa membedakan keduanya dan akan salah menghitung
 * nilai jual salah satu kelompok — lihat MarketplaceSettlementService::resolveGross().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('fee_at_settlement')->default(false)->after('marketplace_fee');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('fee_at_settlement');
        });
    }
};
