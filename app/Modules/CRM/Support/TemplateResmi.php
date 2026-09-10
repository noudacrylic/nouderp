<?php

namespace App\Modules\CRM\Support;

/**
 * Bunyi NOTIFIKASI — pesan yang dikirim sistem sendiri, tanpa ada yang mengetik.
 *
 * Sejak template pembuka chat pindah ke tabel `crm_templates` (10 Sep 2026),
 * yang tersisa di sini hanya yang dikirim KODE. Pemisahannya bukan soal harga
 * melainkan soal siapa yang mengirim, dan itu yang menentukan boleh-tidaknya
 * bunyinya disunting dari layar:
 *
 *  - Notifikasi (berkas ini): TIDAK boleh. Satu bunyi berangkat lewat DUA jalur
 *    — WAHA merangkai teksnya, jalur resmi mengirim nama templatenya — dan
 *    keduanya wajib mengucapkan kalimat yang sama persis. Antrean WAHA yang
 *    tertahan tiga jam dieskalasi ke jalur resmi; pelanggan tidak boleh bisa
 *    menebak jalur mana yang dipakai dari kalimat yang ia terima.
 *  - Template pembuka chat (`CrmTemplate`): boleh, karena manusia yang memilih
 *    dan mengirimnya satu per satu, dan ia tak punya jalur WAHA sama sekali.
 *
 * SUMBER TUNGGAL. Sengaja duduk di lapis Support, bukan di controller, karena
 * bunyinya dipakai DUA arah:
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
 *
 * Sapaan "Kak" dipakai di SEMUA template (9 Sep 2026) — begitulah pelanggan
 * disapa di chat, dan pesan otomatis yang tiba-tiba berbahasa lain terbaca
 * seperti datang dari perusahaan lain.
 */
class TemplateResmi
{
    /**
     * Nama template PANCINGAN, disebut dari kode (CrmReplyService, layar chat).
     *
     * Diberi konstanta karena ia satu-satunya template yang dipanggil dari
     * dalam kode berdasarkan namanya — sisanya dipilih manusia dari daftar.
     * Nama yang diketik ulang di beberapa berkas adalah cara paling sunyi
     * untuk mengirim template yang tak dikenal Meta.
     */
    public const TEMPLATE_PANCINGAN = 'lanjut_diskusi';

    public const USULAN = [
        [
            'nama'     => 'pembayaran_diterima',
            'kategori' => 'UTILITY',
            'guna'     => 'Satu template melayani DP dan pelunasan — {{4}} sengaja teks bebas.',
            'contoh'   => ['Budi', '150.000', 'SO-2609-0012', 'DP diterima, sisa 350.000'],
            'body'     => 'Halo Kak {{1}}, pembayaran sebesar Rp{{2}} untuk pesanan {{3}} sudah kami terima. Status pembayaran saat ini: {{4}}. Terima kasih atas kepercayaan Kakak.',
        ],
        [
            'nama'     => 'pesanan_siap_diambil',
            'kategori' => 'UTILITY',
            'guna'     => 'Jam toko sengaja jadi variabel — jam berubah saat Lebaran, dan mengubah body template berarti ajukan ulang ke Meta.',
            'contoh'   => ['Budi', 'SO-2609-0012', 'AMB-4471', 'Senin–Sabtu 08.00–16.00'],
            'body'     => 'Halo Kak {{1}}, pesanan {{2}} sudah selesai dan siap diambil di toko kami. Tunjukkan kode pengambilan {{3}} kepada petugas. Kami buka {{4}}. Di luar jam tersebut, silakan balas pesan ini untuk membuat janji pengambilan.',
        ],
        [
            'nama'     => 'stok_tersedia',
            'kategori' => 'UTILITY',
            'guna'     => 'Menepati janji "nanti saya kabari kalau stoknya ada". HANYA jalur WAHA — lihat hanyaWaha(). '
                        . 'TIDAK meminta pelanggan membalas: ini pemberitahuan, dan jalan meneruskannya '
                        . 'ditunjukkan sendiri ({{3}} tautan produk, {{4}} nomor admin).',
            'contoh'   => ['Budi', 'Akrilik Frame Poster A3', 'https://noudakrilik.com/produk/frame-a3', '08998844666'],
            'hanya_waha' => true,
            'body'     => 'Halo Kak {{1}}, kabar baik — {{2}} yang Kakak tanyakan sudah tersedia lagi. Kakak bisa langsung pesan di website kami: {{3}} atau menghubungi admin kami di {{4}} untuk melanjutkan pesanan.',
        ],
        [
            'nama'     => 'jatuh_tempo',
            'kategori' => 'UTILITY',
            'guna'     => 'Pengingat jatuh tempo pesanan tempo — H-3 dan hari-H. '
                        . 'WAJIB lewat jalur resmi (lihat wajibResmi()): pesan soal utang yang '
                        . 'jatuh tempo datang dari nomor bercentang, bukan dari nomor pribadi. '
                        . 'Tanggalnya jadi variabel supaya SATU template melayani kedua titik kirim '
                        . '— dua template berarti dua pengajuan ke Meta untuk kalimat yang hampir sama.',
            'contoh'   => ['Budi', 'SO-2609-0012', '500.000', '12 Oktober 2026', '08998844666'],
            'body'     => 'Halo Kak {{1}}, pengingat pembayaran: pesanan {{2}} sebesar Rp{{3}} '
                        . 'jatuh tempo pada {{4}}. Mohon pembayarannya diselesaikan tepat waktu ya. '
                        . 'Bila sudah membayar, mohon abaikan pesan ini. '
                        . 'Butuh bantuan? Hubungi admin kami di {{5}}.',
        ],
        [
            'nama'     => 'tagihan_pembayaran',
            'kategori' => 'UTILITY',
            'guna'     => 'Menagih pesanan yang tautan bayarnya sudah dibuat tapi belum dibayar. '
                        . 'HANYA jalur WAHA — lihat hanyaWaha(). Batas waktunya disebut terang-terangan '
                        . '({{5}}) karena pesanannya memang dibatalkan otomatis kalau lewat.',
            'contoh'   => ['Budi', 'SO-2609-0012', '500.000', 'https://noudakrilik.com/pay/contoh', '7 Oktober 2026', '08998844666'],
            'hanya_waha' => true,
            'body'     => 'Halo Kak {{1}}, kami dari tim marketing Noud Akrilik Shop ingin mengingatkan '
                        . 'bahwa pesanan {{2}} senilai Rp{{3}} masih menunggu pembayaran. '
                        . 'Kakak bisa membayar lewat tautan berikut:' . PHP_EOL . '{{4}}' . PHP_EOL
                        . 'Bila sampai {{5}} belum ada pembayaran, pesanannya kami batalkan otomatis. '
                        . 'Kalau sudah membayar atau ingin mengubah pesanan, silakan hubungi admin kami di {{6}}.',
        ],
        [
            'nama'     => 'pesanan_dikirim',
            'kategori' => 'UTILITY',
            'guna'     => 'Tombol URL dinamis → halaman lacak pesanan.',
            'contoh'   => ['Budi', 'SO-2609-0012', 'JNE', 'JX1234567890'],
            'body'     => 'Halo Kak {{1}}, pesanan {{2}} sudah kami serahkan ke {{3}} dengan nomor resi {{4}}. Silakan pantau perjalanan paket Kakak lewat tombol di bawah ini.',
            // Tombol URL-nya dipasang langsung di dasbor vendor, sebelum field
            // 'tombol' di bawah ada. TIDAK didaftarkan ulang di sini: nama
            // template unik per bahasa, jadi ia tak mungkin diajukan lagi —
            // mendeklarasikannya cuma memberi kesan tombolnya berasal dari kode.
            'kalimat_tombol' => ' Silakan pantau perjalanan paket Kakak lewat tombol di bawah ini.',
            'tanpa_tombol'   => ' Silakan pantau perjalanan paket Kakak di sini:' . PHP_EOL . '{url}',
        ],
        [
            'nama'     => 'lanjut_diskusi',
            'kategori' => 'UTILITY',
            'guna'     => 'PANCINGAN. Dikirim saat jendela 24 jam hampir/sudah tutup padahal '
                        . 'pembahasan belum kelar — terutama di hari libur & di luar jam kerja. '
                        . 'Tombolnya yang bekerja: sekali ditekan, pelanggan mengirim pesan masuk, '
                        . 'dan pesan masuk itulah yang membuka jendela 24 jam yang baru. '
                        . 'Jam layanan jadi variabel dengan alasan yang sama seperti '
                        . 'template "pesanan_siap_diambil": jamnya berubah saat Lebaran, dan mengubah '
                        . 'body berarti mengajukan ulang ke Meta.',
            'contoh'   => ['Kak Budi', 'Senin–Sabtu 08.00–16.00'],
            /*
             * Tombol BALASAN CEPAT, bukan tombol URL. Bedanya justru inti dari
             * template ini: tombol URL membawa pelanggan KELUAR ke peramban dan
             * tidak menghasilkan satu pun pesan masuk, jadi jendelanya tetap
             * tertutup dan kita tetap tak bisa menjawab. Balasan cepat mengirim
             * pesan sungguhan dari nomor pelanggan — itulah yang dihitung Meta.
             */
            'tombol'   => [['type' => 'QUICK_REPLY', 'text' => 'Lanjutkan diskusi']],
            /*
             * Sapaannya utuh di dalam {{1}} ('Kak Budi'), bukan 'Halo Kak {{1}}'
             * seperti tetangganya. Sebabnya khas template ini: ia dipakai pada
             * PERCAKAPAN, dan percakapan boleh saja belum punya nama sama sekali
             * (lead yang baru chat sekali). Pola tetangga memaksa nilai cadangan
             * masuk ke belakang kata "Kak", dan yang keluar adalah "Halo Kak
             * Pelanggan". Di sini cadangannya cukup "Kak" — dan yang bernama
             * tetap menerima kalimat yang sama persis.
             */
            'body'     => 'Halo {{1}}, mohon maaf pembahasan pesanan Kakak belum sempat kami tuntaskan. '
                        . 'Tim kami sedang di luar jam layanan dan kembali melayani {{2}}. '
                        . 'Agar obrolan ini bisa kami sambung lagi, silakan tekan tombol di bawah ini '
                        . '— kami balas begitu jam layanan dibuka. Terima kasih sudah menunggu, Kak.',
            'kalimat_tombol' => ' Agar obrolan ini bisa kami sambung lagi, silakan tekan tombol di bawah ini '
                              . '— kami balas begitu jam layanan dibuka.',
            'tanpa_tombol'   => ' Agar obrolan ini bisa kami sambung lagi, silakan balas pesan ini '
                              . '— kami jawab begitu jam layanan dibuka.',
        ],
    ];

    /** Judul tombol pancingan. Maks 20 karakter — batas Meta, bukan selera. */
    public const TOMBOL_PANCINGAN = 'Lanjutkan diskusi';

    /**
     * Bunyi pancingan versi GRATIS — pesan sesi biasa, dikirim SELAGI jendela
     * masih terbuka (kira-kira sejam sebelum habis).
     *
     * Duduk di sini walau BUKAN template Meta, dan itu disengaja: ia kembaran
     * dari 'lanjut_diskusi', cuma beda jalur dan beda harga. Pelanggan yang
     * sama bisa menerima keduanya di minggu yang berbeda, dan dua kalimat yang
     * perlahan menyimpang akan terbaca seperti dua perusahaan. Bersebelahan,
     * keduanya berubah bersama.
     *
     * Bedanya dengan versi berbayar tinggal satu hal, dan itu soal kejujuran:
     * di sini jendelanya BELUM tutup, jadi kalimatnya tidak boleh berbunyi
     * seolah kita sudah tak bisa dihubungi.
     */
    public static function bunyiPancinganSesi(string $sapaan, string $jamLayanan): string
    {
        return 'Halo ' . $sapaan . ', mohon maaf pembahasan pesanan Kakak belum sempat kami tuntaskan. '
             . 'Tim kami kembali melayani ' . $jamLayanan . '. '
             . 'Supaya obrolan ini tetap bisa kami balas nanti, silakan tekan tombol di bawah ini ya '
             . '— kami lanjutkan begitu jam layanan dibuka. Terima kasih sudah menunggu, Kak.';
    }

    /**
     * Kepala surat untuk jalur TIDAK RESMI.
     *
     * WAHA mengirim dari nomor WhatsApp biasa: tak ada centang hijau, tak ada
     * nama bisnis terverifikasi, cuma sederet angka asing. Pesan "pesanan Anda
     * belum dibayar, klik tautan ini" dari nomor semacam itu adalah bentuk
     * penipuan yang paling lazim — dan pelanggan yang berhati-hati justru
     * benar kalau mengabaikannya.
     *
     * Karena itu tiap pesan WAHA memperkenalkan diri lebih dulu. Ini SATU-
     * SATUNYA penyimpangan yang boleh dari bunyi template: badannya tetap sama
     * persis, yang ditambahkan hanya kepala suratnya.
     */
    public static function pembukaWaha(): string
    {
        $situs = preg_replace('~^https?://~', '', rtrim((string) config('crm.storefront_url'), '/'));

        return '*NOUD ACRYLIC*' . PHP_EOL
             . 'Pesan resmi dari ' . ($situs ?: 'noudakrilik.com')
             . '. Nomor ini kami pakai untuk mengabari pesanan.' . PHP_EOL
             . '——————' . PHP_EOL . PHP_EOL;
    }

    /**
     * Template ini TIDAK punya padanan berbayar di Meta.
     *
     * Ada satu yang begitu — kabar "stok sudah ada". Ia lahir dari titipan
     * pelanggan sendiri di dalam chat, jadi jalur tidak resmi memang tempatnya;
     * mengajukannya ke Meta berarti menambah template yang dipakai sesekali
     * dan berisiko dibaca sebagai promosi. Konsekuensinya: pengirim TIDAK BOLEH
     * mengeskalasikannya ke jalur berbayar saat WAHA mati — nama templatenya
     * tak dikenal di sana, dan yang terjadi cuma kegagalan yang membingungkan.
     */
    public static function hanyaWaha(string $nama): bool
    {
        foreach (self::USULAN as $t) {
            if ($t['nama'] === $nama) {
                return (bool) ($t['hanya_waha'] ?? false);
            }
        }

        return false;
    }

    /**
     * Template ini WAJIB lewat jalur resmi, apa pun driver yang sedang dipilih.
     *
     * Kebalikan dari hanyaWaha(), dan alasannya bukan teknis melainkan siapa
     * yang membaca. Pesan soal UTANG YANG JATUH TEMPO adalah pesan yang paling
     * mudah disalahartikan sebagai penipuan, dan yang paling merugikan kalau
     * salah dipercaya. Dari nomor pribadi tanpa centang, ia menempatkan
     * pelanggan pada pilihan yang sama-sama buruk: mengabaikan tagihan yang
     * sah, atau memercayai pesan yang tak bisa ia verifikasi. Untuk yang satu
     * ini kita membayar template.
     */
    public static function wajibResmi(string $nama): bool
    {
        return $nama === 'jatuh_tempo';
    }

    /** Satu entri usulan apa adanya, atau null bila namanya tak dikenal. */
    public static function usulan(string $nama): ?array
    {
        foreach (self::USULAN as $t) {
            if ($t['nama'] === $nama) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Nilai contoh tiap {{n}}.
     *
     * Bukan hiasan: vendor menolak pengajuan bila jumlah `variables` tidak sama
     * dengan jumlah placeholder di body. Disimpan bersebelahan dengan bodynya
     * supaya keduanya berubah bersama — daftar contoh yang hidup di berkas lain
     * akan tertinggal diam-diam begitu sebuah variabel ditambah.
     *
     * @return string[]
     */
    public static function contoh(string $nama): array
    {
        return array_map('strval', (array) (self::usulan($nama)['contoh'] ?? []));
    }

    /**
     * Deklarasi tombol untuk pengajuan ke Meta, kosong bila template ini
     * memang tak bertombol.
     *
     * @return array<array{type:string, text:string, url?:string, phone_number?:string}>
     */
    public static function tombol(string $nama): array
    {
        return array_values((array) (self::usulan($nama)['tombol'] ?? []));
    }

    /**
     * Ganti kalimat yang menyebut-nyebut tombol dengan padanan tanpa tombol.
     *
     * Pasangan kalimatnya duduk di entri templatenya sendiri
     * ('kalimat_tombol' & 'tanpa_tombol'), bukan dipatri di sini. Dulu ia
     * dipatri, dan akibatnya persis yang bisa ditebak: begitu sapaan diubah
     * ke "Kak", kalimat di body tak lagi cocok dengan kalimat di kode, jadi
     * tak pernah terpotong — dan pesan WAHA dengan tenang menjanjikan tombol
     * yang tak akan pernah muncul. Bersebelahan dengan bodynya, keduanya
     * berubah bersama atau tidak sama sekali.
     *
     * '{url}' di dalam 'tanpa_tombol' diisi $urlLacak. Bila penanda itu ada
     * TAPI urlnya tidak, kalimatnya dibuang seluruhnya: menjanjikan tautan
     * yang tak disertakan sama buruknya dengan menjanjikan tombol yang tak ada.
     */
    private static function lepasTombol(string $nama, string $body, ?string $urlLacak): string
    {
        $usulan  = self::usulan($nama) ?? [];
        $kalimat = (string) ($usulan['kalimat_tombol'] ?? '');

        if ($kalimat === '' || ! str_contains($body, $kalimat)) {
            return $body;
        }

        $ganti = (string) ($usulan['tanpa_tombol'] ?? '');

        if (str_contains($ganti, '{url}')) {
            $ganti = $urlLacak ? str_replace('{url}', $urlLacak, $ganti) : '';
        }

        return str_replace($kalimat, $ganti, $body);
    }

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

        return trim(self::lepasTombol($nama, $body, $urlLacak));
    }
}
