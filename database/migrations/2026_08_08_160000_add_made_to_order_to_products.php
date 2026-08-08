<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "dibuat khusus per pesanan" untuk produk preorder.
 *
 * Tipe `preorder` selama ini menampung dua watak yang sangat berbeda dan tidak pernah
 * dibedakan:
 *
 *  - Produk berspesifikasi tetap (Box Charger, Tempat Obat). Satu unit bisa menggantikan
 *    unit lain. Sisa produksi dari pesanan yang batal SEHARUSNYA dipakai pesanan berikutnya.
 *  - Produk yang dibuat mengikuti permintaan pembeli (CS1, CS2, …). SKU-nya cuma wadah;
 *    dua unit di bawah SKU yang sama bisa beda ukuran, warna, atau cetakan. HPP-nya
 *    menempel pada order produksi milik pesanan itu.
 *
 * Karena tak dibedakan, ERP memperlakukan semuanya seperti kelompok kedua: tiap pesanan
 * selalu memicu order produksi baru walau barangnya sudah ada di rak, dan sisa pesanan
 * batal menumpuk jadi deadstock.
 *
 * Bawaannya SENGAJA true. Salah tebak ke arah "dibuat khusus" cuma mengulang perilaku
 * lama (deadstock); salah tebak ke arah sebaliknya bisa mengirim barang milik spesifikasi
 * orang lain ke pembeli. Jadi produk lama & produk baru mulai dari sisi yang aman, dan
 * pelepasannya keputusan sadar lewat daftar Produk.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('made_to_order')->default(true)->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('made_to_order'));
    }
};
