<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dua peran nomor, dijelaskan sekali dan berlaku di semua tingkat.
 *
 * NOMOR UTAMA menerima kabar (pembayaran, tagihan, resi). NOMOR PENERIMA
 * diberikan ke kurir dan dicetak di label. Keduanya ada di pusat maupun di
 * cabang, dan yang kosong selalu jatuh ke tingkat di atasnya:
 *
 *   notifikasi  = cabang.utama ?: pusat.utama
 *   pengiriman  = cabang.penerima ?: cabang.utama ?: pusat.penerima ?: pusat.utama
 *
 * Migrasi ini menambah yang belum ada — `phone` di cabang — lalu menambal satu
 * kekacauan lama: sampai sekarang notifikasi memilih nomor dengan
 * `recipient_phone ?: phone`, sehingga mengisi "No. HP Penerima" diam-diam
 * MEMINDAHKAN semua kabar (termasuk kabar uang) ke nomor penerima barang.
 *
 * Membalik aturannya begitu saja akan membisukan 20 dari 77 pelanggan yang
 * nomor utamanya memang kosong sejak awal — form pelanggan cepat dulu hanya
 * mengisi `recipient_phone`. Jadi nomor mereka DINAIKKAN lebih dulu ke nomor
 * utama. Aman karena tidak ada satu pun pelanggan yang kedua nomornya terisi
 * dan berbeda: tak ada nomor yang tertimpa.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_branches', function (Blueprint $t) {
            $t->string('phone', 30)->nullable()->after('pic_name');
        });

        // Nomor notifikasi tambahan: satu perusahaan, beberapa orang dengan
        // posisi berbeda (purchasing, lapangan) yang sama-sama perlu dikabari.
        // Sengaja milik PELANGGAN, bukan cabang — orang-orang ini mengikuti
        // perusahaannya, bukan alamat kirimnya.
        Schema::create('customer_notification_phones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('label')->nullable();
            $t->string('phone', 30);
            $t->timestamps();

            $t->index('customer_id');
        });

        DB::table('customers')
            ->where(function ($q) {
                $q->whereNull('phone')->orWhere('phone', '');
            })
            ->whereNotNull('recipient_phone')
            ->where('recipient_phone', '!=', '')
            ->update(['phone' => DB::raw('recipient_phone')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notification_phones');

        Schema::table('customer_branches', function (Blueprint $t) {
            $t->dropColumn('phone');
        });
    }
};
