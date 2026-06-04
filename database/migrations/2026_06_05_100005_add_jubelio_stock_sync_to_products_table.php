<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom sinkron stok Jubelio (Fase 2):
 * - jubelio_sync_pending: ditandai observer saat stok produk berubah; cron push memprosesnya
 *   (hindari panggilan HTTP di dalam transaksi posting stok inti).
 * - jubelio_synced_qty: nilai stok available terakhir yang sudah didorong ke Jubelio
 *   (baseline untuk hitung delta tanpa harus GET tiap kali).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('jubelio_sync_pending')->default(false)->after('jubelio_location_id');
            $table->decimal('jubelio_synced_qty', 18, 4)->nullable()->after('jubelio_sync_pending');
            $table->index('jubelio_sync_pending');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['jubelio_sync_pending']);
            $table->dropColumn(['jubelio_sync_pending', 'jubelio_synced_qty']);
        });
    }
};
