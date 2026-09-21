<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun 4004 Retur Penjualan — kontra-pendapatan.
 *
 * Sebelum ini retur mendebit langsung 4001 Penjualan Produk, jadi omzet menyusut diam-diam:
 * laporan tak pernah bisa menjawab "bulan ini jual berapa, diretur berapa", yang terlihat
 * hanya hasil bersihnya. Dengan akun sendiri keduanya terlihat dan rasio retur bisa dipantau.
 *
 * Bertipe `revenue` dengan saldo normal DEBIT — sama persis dengan 4003 Diskon Penjualan yang
 * sudah ada. Karena kodenya diawali "40", Laporan Laba Rugi otomatis menempatkannya di bagian
 * Pendapatan sebagai deduksi (aturan prefix di config/income_statement.php), tanpa konfigurasi
 * tambahan. Laba tidak berubah sepeser pun — ini murni penyajian.
 *
 * CATATAN: akun 6105 Beban Kerugian Retur TETAP dipakai dan urusannya berbeda — ia menampung
 * nilai MODAL (FIFO) barang yang tidak kembali atau kembali rusak, dan duduk di Beban
 * Operasional. 4004 menampung nilai JUAL yang dibatalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('accounts')->where('code', '4004')->exists()) {
            return;
        }

        DB::table('accounts')->insert([
            'code'           => '4004',
            'name'           => 'Retur Penjualan',
            'type'           => 'revenue',
            'normal_balance' => 'debit',
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('accounts')->where('code', '4004')->value('id');
        if (!$id) {
            return;
        }

        // Jangan hapus akun yang sudah punya jurnal — buku besarnya akan bolong.
        if (DB::table('journal_lines')->where('account_id', $id)->exists()) {
            return;
        }

        DB::table('accounts')->where('id', $id)->delete();
    }
};
