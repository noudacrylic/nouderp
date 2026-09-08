<?php

namespace App\Modules\CRM\Support;

/**
 * Bunyi template yang sudah disepakati & diajukan ke Meta.
 *
 * SUMBER TUNGGAL. Sengaja duduk di lapis Support, bukan di controller, karena
 * sejak jalur WAHA ada, bunyinya dipakai DUA arah:
 *  - jalur resmi mengirim NAMA template-nya (bunyinya ada di Meta);
 *  - jalur WAHA mengirim TEKSNYA, dirangkai di sini.
 *
 * Karena itu keduanya wajib berangkat dari daftar yang sama persis. Pelanggan
 * tidak boleh bisa menebak jalur mana yang dipakai dari kalimat yang ia terima —
 * dan kalau nanti sebuah pesan WAHA dipersoalkan, kalimatnya harus sama dengan
 * yang pernah disetujui Meta, bukan karangan sendiri.
 *
 * Mengubah 'body' di sini = mengubah template di Meta juga. Kalau tidak,
 * kedua jalur diam-diam mengirim kalimat berbeda.
 */
class TemplateResmi
{
    public const USULAN = [
        [
            'nama'     => 'sapa_umum',
            'kategori' => 'UTILITY',
            'guna'     => 'Menyapa duluan nomor yang ditinggalkan pelanggan di toko. Tanpa variabel — tidak mungkin salah isi.',
            'body'     => 'Selamat siang, kami dari Noud Acrylic. Kami ingin melanjutkan pembahasan pesanan Anda. Mohon balas pesan ini ya.',
        ],
        [
            'nama'     => 'konfirmasi_desain',
            'kategori' => 'UTILITY',
            'guna'     => 'Menyapa terkait pesanan yang sudah ada. Menempel pada transaksi berjalan, jadi peluang masuk UTILITY lebih besar.',
            'body'     => 'Halo {{1}}, kami dari Noud Acrylic ingin mengonfirmasi desain untuk pesanan {{2}}. Mohon balas pesan ini agar kami kirimkan pratinjaunya.',
        ],
        [
            'nama'     => 'pembayaran_diterima',
            'kategori' => 'UTILITY',
            'guna'     => 'Satu template melayani DP dan pelunasan — {{4}} sengaja teks bebas.',
            'body'     => 'Halo {{1}}, pembayaran sebesar Rp{{2}} untuk pesanan {{3}} sudah kami terima. Status pembayaran saat ini: {{4}}. Terima kasih atas kepercayaan Anda.',
        ],
        [
            'nama'     => 'pesanan_siap_diambil',
            'kategori' => 'UTILITY',
            'guna'     => 'Jam toko sengaja jadi variabel — jam berubah saat Lebaran, dan mengubah body template berarti ajukan ulang ke Meta.',
            'body'     => 'Halo {{1}}, pesanan {{2}} sudah selesai dan siap diambil di toko kami. Tunjukkan kode pengambilan {{3}} kepada petugas. Kami buka {{4}}. Di luar jam tersebut, silakan balas pesan ini untuk membuat janji pengambilan.',
        ],
        [
            'nama'     => 'pesanan_dikirim',
            'kategori' => 'UTILITY',
            'guna'     => 'Tombol URL dinamis → halaman lacak pesanan.',
            'body'     => 'Halo {{1}}, pesanan {{2}} sudah kami serahkan ke {{3}} dengan nomor resi {{4}}. Silakan pantau perjalanan paket Anda lewat tombol di bawah ini.',
        ],
    ];

    /** Bunyi badan satu template, atau null bila namanya tak dikenal. */
    public static function body(string $nama): ?string
    {
        foreach (self::USULAN as $t) {
            if ($t['nama'] === $nama) {
                return $t['body'];
            }
        }

        return null;
    }

    /**
     * Rangkai teks siap kirim untuk jalur yang TIDAK punya template (WAHA).
     *
     * Dua penyimpangan yang disengaja dari bunyi Meta, keduanya karena pesan
     * teks biasa tidak punya tombol:
     *  1. kalimat "lewat tombol di bawah ini" digantikan URL lacak yang
     *     ditempel di baris terakhir;
     *  2. bila URL-nya tidak ada, kalimat itu dibuang seluruhnya — menjanjikan
     *     tombol yang tak pernah muncul lebih buruk daripada tidak menyebutnya.
     *
     * Mengembalikan null bila template tak dikenal, supaya pemanggil gagal
     * dengan keterangan alih-alih mengirim kalimat setengah jadi.
     */
    public static function render(string $nama, array $variabel, ?string $urlLacak = null): ?string
    {
        $body = self::body($nama);

        if ($body === null) {
            return null;
        }

        $i = 0;
        foreach (array_values($variabel) as $nilai) {
            $i++;
            $body = str_replace(['{{' . $i . '}}', '{{ ' . $i . ' }}'], (string) $nilai, $body);
        }

        $kalimatTombol = ' Silakan pantau perjalanan paket Anda lewat tombol di bawah ini.';

        if (str_contains($body, $kalimatTombol)) {
            $body = str_replace(
                $kalimatTombol,
                $urlLacak ? ' Silakan pantau perjalanan paket Anda di sini:' . PHP_EOL . $urlLacak : '',
                $body
            );
        }

        return trim($body);
    }
}
