<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal DANA retur yang ditulis tangan.
 *
 * Retur yang diajukan konsumen hasilnya bermacam-macam — pembeli bisa dapat ganti penuh,
 * sebagian, atau tak sama sekali — dan tak semua bentuknya bisa disimpulkan dari kondisi
 * barang. Kolom ini menyimpan baris jurnal yang diketik sendiri untuk blok dana; NULL =
 * pakai hitungan sistem seperti biasa.
 *
 * Yang TIDAK pernah masuk sini: jurnal barang (Persediaan/HPP/Beban Kerugian Retur). Baris
 * itu cerminan pergerakan stok yang benar-benar terjadi, jadi mengetiknya sendiri hanya
 * akan membuat buku besar dan kartu stok bercerita beda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->json('journal_override')->nullable()->after('fee_reversed');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('journal_override');
        });
    }
};
