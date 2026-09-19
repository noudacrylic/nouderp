<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur bertahap — pendefinisian kasus di tahap "Retur Baru".
 *
 *  - `return_type`           : jenis kasus retur (paket hilang / gagal kirim / dst). NULL =
 *                              belum didefinisikan; itulah yang menahan retur di tahap "baru".
 *  - `external_return_number`: nomor retur dari marketplace. SENGAJA terpisah dari
 *                              `return_number` (nomor dokumen ERP yang harus urut & unik):
 *                              paket retur fisik datang membawa nomor ini, sering berbeda dari
 *                              nomor pesanan, jadi gudang mencarinya dengan nomor ini.
 *
 * Hasil klaim TIDAK disimpan di sini. Menang/kalahnya klaim terbaca dari KONDISI BARANG per
 * baris: `tidak_kembali` = barang hilang tapi dananya diganti (tidak membalik penjualan),
 * `damaged` = barang & dana sama-sama hilang. Per baris, bukan per dokumen, supaya satu
 * pesanan bisa sebagian diganti dan sebagian tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('return_type', 30)->nullable()->after('stage');
            $table->string('external_return_number', 60)->nullable()->after('return_number');

            $table->index('external_return_number');
            $table->index('return_type');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex(['external_return_number']);
            $table->dropIndex(['return_type']);
            $table->dropColumn(['return_type', 'external_return_number']);
        });
    }
};
