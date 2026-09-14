<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sapu baris notifikasi 'dilewati' milik pesanan marketplace.
 *
 * Sampai hari ini setiap pesanan marketplace meninggalkan satu baris berstatus
 * 'dilewati' di layar Notifikasi Pesanan. Niatnya jejak, hasilnya kebisingan:
 * pembeli Shopee/Tokopedia dikabari platformnya sendiri, jadi tak pernah ada
 * yang perlu ditindaklanjuti dari baris-baris itu — sementara sinkron Jubelio
 * tiap lima menit terus menambahnya sampai skip yang BENAR-BENAR perlu dilihat
 * (opt-in kosong, nomor tidak valid) tak lagi kelihatan.
 *
 * OrderNotificationService kini berhenti sebelum membuat barisnya. Yang di sini
 * membersihkan yang terlanjur menumpuk.
 *
 * Hanya menyentuh status 'dilewati': baris terkirim/gagal adalah catatan
 * kejadian nyata dan tidak boleh hilang, apa pun pelanggannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('crm_outbox')
            ->where('status', 'dilewati')
            ->where('reason', 'like', 'Pesanan marketplace%')
            ->delete();
    }

    public function down(): void
    {
        // Tak bisa dikembalikan, dan memang tak perlu: yang dihapus adalah
        // catatan bahwa kita TIDAK mengirim apa pun ke pembeli marketplace —
        // fakta yang tetap benar tanpa barisnya.
    }
};
