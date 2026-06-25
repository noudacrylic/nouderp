<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak kegagalan "Proses Pesanan" pada SO non-marketplace (mis. generate faktur/SJ
 * gagal saat bulk). Marketplace sudah punya jejaknya sendiri (jubelio_order_links.
 * wms_last_error). Dipakai tab "Perlu Diproses" untuk filter "Gagal Proses".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->text('process_error')->nullable()->after('seller_notes');
            $table->timestamp('process_failed_at')->nullable()->after('process_error');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['process_error', 'process_failed_at']);
        });
    }
};
