<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda waktu notifikasi "pesanan instant baru" sudah dikirim ke tim packing.
 * Dipakai sebagai klaim atomik agar notifikasi web (Web Push) hanya terkirim SEKALI
 * per pesanan — webhook & cron bisa memproses pesanan baru yang sama nyaris bersamaan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->timestamp('packing_notified_at')->nullable()->after('is_instant_courier');
        });
    }

    public function down(): void
    {
        Schema::table('jubelio_order_links', function (Blueprint $table) {
            $table->dropColumn('packing_notified_at');
        });
    }
};
