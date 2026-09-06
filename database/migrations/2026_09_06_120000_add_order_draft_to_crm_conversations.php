<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keranjang pesanan yang sedang disusun dari layar chat.
 *
 * Alamat tujuan, tarif kurir terpilih, dan isi keranjang adalah hasil kerja —
 * mencari kecamatan, menghitung ongkir, memilih layanan — dan semuanya sudah
 * jadi bagian dari SO yang akan lahir. Selama ini semua itu cuma hidup di
 * memori browser, jadi satu kali muat ulang (atau membuka chat lain lalu
 * kembali) menghapusnya diam-diam dan operator mengulang dari nol.
 *
 * Ditempel ke PERCAKAPAN, bukan ke sesi browser: pesanan yang sama sering
 * dilanjutkan admin lain, dan draft yang hanya ada di satu laptop membuat
 * operan chat kehilangan konteksnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->json('order_draft')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('crm_conversations', function (Blueprint $table) {
            $table->dropColumn('order_draft');
        });
    }
};
