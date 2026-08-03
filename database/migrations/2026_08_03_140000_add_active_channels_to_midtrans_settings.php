<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Metode bayar yang DITAWARKAN ke pembeli di halaman /pay.
     *
     * Sebelumnya halaman itu memajang keenam metode apa adanya, padahal Alfamart,
     * Kredivo/Akulaku (dan kadang Kartu Kredit) butuh pengajuan terpisah ke Midtrans.
     * Pembeli yang memilih metode yang belum disetujui akan mentok di halaman Snap
     * tanpa penjelasan — persis di detik ia hendak membayar.
     *
     * Kolom ini memisahkan "metode yang tarifnya sudah kita catat" (channel_fees, tetap
     * lengkap untuk keperluan jurnal) dari "metode yang boleh dipilih pembeli". Kosong
     * atau null = pakai bawaan aman di MidtransSetting::activeChannels().
     */
    public function up(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            // ["qris","va","ewallet"]
            $table->json('active_channels')->nullable()->after('channel_fees');
        });
    }

    public function down(): void
    {
        Schema::table('midtrans_settings', function (Blueprint $table) {
            $table->dropColumn('active_channels');
        });
    }
};
