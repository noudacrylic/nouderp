<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tiga agen bawaan. SEMUANYA MATI.
 *
 * Barisnya dibuat sekarang meski dua di antaranya belum punya alat apa pun,
 * karena kode memanggil agen lewat KODE-nya (`penyambutan`, dst.) — barisnya
 * harus ada sebelum layar bisa menampilkannya. Yang belum dikerjakan adalah
 * pengetahuannya, bukan keberadaannya.
 *
 * Persona hanya diisi untuk Penyambutan, agen yang ditajamkan lebih dulu.
 * Mengisi persona untuk agen yang belum pernah diuji cuma menaruh kalimat yang
 * kelihatan resmi tapi tidak pernah dibaca siapa pun.
 *
 * Catatan soal persona: ia mengatur NADA, bukan pagar. Larangan (diskon, ubah
 * harga, janji tanggal) hidup di App\Modules\CRM\Agents\AturanTetap dan tidak
 * bisa dilonggarkan dari layar — aturan yang bisa terhapus tak sengaja bukan
 * pagar.
 */
return new class extends Migration
{
    private const PERSONA_PENYAMBUTAN = <<<'TXT'
Namamu Nia, asisten Noud Acrylic — toko akrilik di Semarang.

Perkenalkan diri SEKALI saja, di pesan pertama percakapan: "Halo Kak, saya Nia,
asisten Noud Acrylic." Sesudah itu jangan diulang lagi, dan jangan pernah
menyebut dirimu AI di tengah percakapan.

Kamu menyapa pelanggan dengan "Kak". Tugasmu menyambut, menjawab pertanyaan
paling umum (jam buka, alamat, cara pesan, rekening, stok & harga barang), lalu
melempar ke tim begitu pembahasannya masuk ke hal yang perlu diputuskan manusia.

Kamu bukan penjual yang mengejar. Kalau pelanggan hanya bertanya, jawab
pertanyaannya — jangan menawarkan barang lain yang tidak ditanyakan.
TXT;

    private const BAWAAN = [
        [
            'kode'      => 'penyambutan',
            'nama'      => 'Agen Penyambutan',
            'deskripsi' => 'Menyapa, menjawab pertanyaan umum, lalu melempar ke tim.',
            'persona'   => self::PERSONA_PENYAMBUTAN,
        ],
        [
            'kode'      => 'penjualan',
            'nama'      => 'Agen Penjualan',
            'deskripsi' => 'Membantu dari cek ongkir sampai pesanan. Belum dikerjakan.',
            'persona'   => null,
        ],
        [
            'kode'      => 'cetak',
            'nama'      => 'Agen Pengumpul Info Cetak',
            'deskripsi' => 'Mengumpulkan ukuran, bahan, warna, jumlah, berkas. Belum dikerjakan.',
            'persona'   => null,
        ],
    ];

    public function up(): void
    {
        foreach (self::BAWAAN as $a) {
            if (DB::table('crm_agents')->where('kode', $a['kode'])->exists()) {
                continue;
            }

            DB::table('crm_agents')->insert($a + [
                'maks_giliran' => 6,
                'is_active'    => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('crm_agents')
            ->whereIn('kode', array_column(self::BAWAAN, 'kode'))
            ->delete();
    }
};
