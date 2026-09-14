<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi pesanan berpindah dari IZIN ke PENOLAKAN.
 *
 * Sebelumnya `wa_opt_in` harus menyala sebelum satu pesan pun berangkat. Aturan
 * itu keliru menakar keadaannya: orang yang sudah membuat pesanan di Noud
 * justru MENUNGGU kabar tentang pesanannya — pembayaran masuk, barang siap,
 * nomor resi. Yang dikirim bukan promosi, melainkan perkembangan transaksi yang
 * ia mulai sendiri. Meminta izin terpisah untuk itu membuat 72 dari 73
 * pelanggan tidak pernah dikabari apa pun, tanpa satu pun dari mereka yang
 * pernah menyatakan keberatan.
 *
 * Yang dicatat sekarang adalah kebalikannya: KEBERATAN. Kolom ini terisi hanya
 * bila seseorang benar-benar menyatakan tidak mau — pembeli melepas centang di
 * checkout, atau admin mencentang "jangan kirim" di master pelanggan. Selama
 * kosong, pelanggan dikabari.
 *
 * `wa_opt_in` & jejaknya TIDAK dibuang: persetujuan eksplisit tetap bukti
 * terkuat bila Meta bertanya, dan checkout masih mencatatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('wa_opt_out_at')->nullable()->after('wa_opt_in_source');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('wa_opt_out_at');
        });
    }
};
