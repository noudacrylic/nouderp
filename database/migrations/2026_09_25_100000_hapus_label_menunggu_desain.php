<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buang label "Menunggu Desain" — tak pernah dipakai sejak lahir.
 *
 * Ia lahir sebagai baris seed di `2026_09_07_110000_create_crm_labels_table`
 * bersama tiga label lain, tapi berbeda dari ketiganya ia TIDAK PERNAH dipasang
 * satu baris kode pun: `menunggu_kita` dipasang webhook masuk, `menunggu_pelanggan`
 * dipasang setiap kali kita membalas, `dingin` dipasang cermin WAHA saat thread
 * lahir. Yang ini hanya bisa dipilih tangan, dan tidak pernah dipilih.
 *
 * Tombol "Hapus" di layar Label sengaja bersembunyi selama sebuah label masih
 * dipakai — itu benar dan tidak diubah. Yang membuat label ini tidak bisa
 * dihapus dari layar bukan aturan itu, melainkan angka "Dipakai" yang dihitung
 * dari SELURUH percakapan aktif; satu chat yang pernah iseng diberi label ini
 * sudah cukup menyembunyikan tombolnya selamanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Percakapan yang terlanjur memakainya dikembalikan ke 'dingin', BUKAN
         * ke 'menunggu_kita'. Bedanya bukan selera: 'menunggu_kita' adalah
         * antrean kerja yang dipercaya orang setiap pagi, dan menyuntikkan chat
         * lama ke sana berarti pekerjaan yang tidak pernah diminta muncul
         * sebagai pekerjaan yang terlewat.
         *
         * Dijalankan lebih dulu supaya baris labelnya dihapus dalam keadaan
         * sudah tidak dirujuk siapa pun.
         */
        DB::table('crm_conversations')
            ->where('queue_state', 'menunggu_desain')
            ->update(['queue_state' => 'dingin']);

        DB::table('crm_labels')->where('kode', 'menunggu_desain')->delete();
    }

    public function down(): void
    {
        /*
         * Labelnya dikembalikan, percakapannya TIDAK. Yang sudah digeser ke
         * 'dingin' tidak bisa dibedakan lagi dari yang memang sudah dingin
         * sejak awal, dan menebaknya hanya akan melempar chat acak ke antrean
         * yang salah. Mengembalikan wadahnya saja sudah cukup untuk memutar
         * balik migrasi ini tanpa kehilangan apa pun yang bisa dipulihkan.
         */
        $ada = DB::table('crm_labels')->where('kode', 'menunggu_desain')->exists();

        if (! $ada) {
            DB::table('crm_labels')->insert([
                'kode'       => 'menunggu_desain',
                'nama'       => 'Menunggu Desain',
                'warna'      => 'purple',
                'urutan'     => 3,
                'aktif'      => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
