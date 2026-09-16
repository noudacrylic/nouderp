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
            /*
             * Versi WAHA TIDAK boleh menyuruh membalas (15 Sep 2026): nomor WAHA
             * cuma pengirim notifikasi, chat-nya tidak dibaca siapa pun — balasan
             * ke sana hilang. Diganti nomor admin. Body Meta sengaja dibiarkan:
             * di jalur resmi membalas memang sampai ke admin, dan mengubahnya
             * berarti mengajukan ulang template.
             */
            'kalimat_balas' => ' Di luar jam tersebut, silakan balas pesan ini untuk membuat janji pengambilan.',
            'ganti_balas'   => ' Di luar jam tersebut, silakan hubungi admin kami di {admin} untuk membuat janji pengambilan.',
            /*
             * Kekurangan bayar pesanan ambil-toko (15 Sep 2026). Pesanan ambil-toko tidak
             * dapat "Pengingat Pelunasan" — sisanya disebut di sini, dilunasi di kasir.
             * Variabel {{5}} OPSIONAL & khusus WAHA: hanya diisi bila ada sisa, dan
             * dipangkas dari jalur resmi (variabelResmi()) karena template Meta-nya
             * cuma punya empat variabel.
             */
            /*
             * Alamat & peta toko ({{6}} & {{7}}, 16 Sep 2026). Pesan yang menyuruh
             * orang datang tapi tidak menyebut ke mana memaksa mereka bertanya
             * dulu — dan di luar jam kerja pertanyaan itu tidak terjawab.
             * Khusus WAHA dengan alasan yang sama seperti {{5}}: body Meta punya
             * empat variabel, dan mengubahnya berarti mengajukan ulang template.
             */
            'tambahan_waha' => [
                [
                    'setelah' => ' kepada petugas.',
                    'kalimat' => ' Masih ada sisa pembayaran sebesar Rp{{5}} yang dapat Kakak lunasi di kasir saat pengambilan.',
                ],
                [
                    // Dua variabel sekaligus: alamat tanpa peta (atau sebaliknya)
                    // setengah menolong, jadi keduanya harus ada atau tidak sama sekali.
                    'setelah'  => ' Kami buka {{4}}.',
                    'variabel' => 2,
                    'kalimat'  => ' Alamat toko kami: {{6}} — peta lokasi: {{7}}.',
                ],
            ],
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
                        . '({{4}}) karena pesanannya memang dibatalkan otomatis kalau lewat. '
                        . 'SENGAJA tanpa tautan bayar, sama seperti tagihan_pelunasan: tautan hanya '
                        . 'dibagikan dari nomor admin utama ({{5}}), supaya pelanggan tidak terbiasa '
                        . 'membayar lewat tautan yang datang dari nomor lain.',
            'contoh'   => ['Budi', 'SO-2609-0012', '500.000', '7 Oktober 2026', '08998844666'],
            'hanya_waha' => true,
            'body'     => 'Halo Kak {{1}}, kami dari tim marketing Noud Acrylic Shop ingin mengingatkan '
                        . 'bahwa pesanan {{2}} senilai Rp{{3}} masih menunggu pembayaran. '
                        . 'Silakan selesaikan pembayaran melalui tautan yang sudah dibagikan oleh admin '
                        . 'kami lewat WhatsApp {{5}}. Demi keamanan, tautan pembayaran hanya kami kirim '
                        . 'dari nomor tersebut. Bila sampai {{4}} belum ada pembayaran, pesanannya kami '
                        . 'batalkan otomatis. Bila tautannya belum diterima, sudah membayar, atau ingin '
                        . 'mengubah pesanan, silakan hubungi admin kami di nomor yang sama.',
        ],
        [
            'nama'     => 'tagihan_pelunasan',
            'kategori' => 'UTILITY',
            'guna'     => 'Pengingat pelunasan pesanan kirim yang barangnya sudah siap (bucket "Belum Lunas"). '
                        . 'HANYA jalur WAHA — lihat hanyaWaha(). SENGAJA tanpa tautan bayar: tautan hanya '
                        . 'dibagikan dari nomor admin utama ({{4}}), supaya pelanggan tidak terbiasa membayar '
                        . 'lewat tautan yang datang dari nomor lain.',
            'contoh'   => ['Budi', 'SO-2609-0012', '95.000', '08998844666'],
            'hanya_waha' => true,
            'body'     => 'Halo Kak {{1}}, pesanan {{2}} sudah siap dan tinggal menunggu pelunasan sebesar Rp{{3}} '
                        . 'sebelum kami kirimkan. Silakan selesaikan pembayaran melalui tautan yang sudah dibagikan '
                        . 'oleh admin kami lewat WhatsApp {{4}}. Demi keamanan, tautan pembayaran hanya kami kirim '
                        . 'dari nomor tersebut. Bila tautannya belum diterima atau ada kendala, Kakak bisa langsung '
                        . 'menghubungi admin kami di nomor yang sama. Terima kasih, Kak.',
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

    /** Perkenalan diri pesan WAHA — satu tempat, supaya ejaan nama toko tidak bercabang. */
    public const PERKENALAN = 'kami dari tim marketing Noud Acrylic Shop';

    /**
     * Perkenalan diri untuk jalur TIDAK RESMI, diselipkan setelah sapaan.
     *
     * WAHA mengirim dari nomor WhatsApp biasa: tak ada centang hijau, tak ada
     * nama bisnis terverifikasi. Karena itu tiap pesan WAHA wajib menyebut
     * siapa pengirimnya. Dulu berupa kepala surat "*NOUD ACRYLIC* — Pesan
     * resmi dari…" di atas badan; diganti (15 Sep 2026) jadi kalimat
     * perkenalan yang manusiawi karena kepala kaku justru terbaca seperti
     * pesan massal — yang meyakinkan pelanggan adalah isinya yang spesifik
     * (namanya, nomor SO, kode ambil), bukan kop suratnya.
     *
     *   "Halo Kak Budi, pesanan SO-1 sudah…"
     *   → "Halo Kak Budi, kami dari tim marketing Noud Acrylic Shop. Pesanan SO-1 sudah…"
     *
     * Badan yang sudah memperkenalkan diri (tagihan_pembayaran) dibiarkan.
     */
    public static function perkenalkanDiri(string $teks): string
    {
        if (str_contains($teks, self::PERKENALAN)) {
            return $teks;
        }

        if (preg_match('/^(Halo [^,\n]{1,80}), (.)/u', $teks, $m)) {
            return $m[1] . ', ' . self::PERKENALAN . '. '
                 . mb_strtoupper($m[2]) . mb_substr($teks, mb_strlen($m[0]));
        }

        // Tanpa sapaan "Halo …," — perkenalan jadi kalimat pembuka sendiri.
        return 'Halo Kak, ' . self::PERKENALAN . '. ' . $teks;
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

        $body = self::sisipTambahan($nama, $body, $variabel);

        $i = 0;
        foreach (array_values($variabel) as $nilai) {
            $i++;
            $body = str_replace(['{{' . $i . '}}', '{{ ' . $i . ' }}'], (string) $nilai, $body);
        }

        return trim(self::gantiAjakanBalas($nama, self::lepasTombol($nama, $body, $urlLacak)));
    }

    /**
     * Sisipkan kalimat tambahan khusus WAHA bila variabel opsionalnya terisi.
     *
     * Variabel opsional = yang nomornya melampaui jumlah {{n}} di body Meta. Kosong atau
     * tidak dikirim → kalimatnya tidak disisipkan, jadi pesan tanpa sisa tetap persis
     * sama dengan bunyi template.
     */
    private static function sisipTambahan(string $nama, string $body, array $variabel): string
    {
        $daftar = (array) (self::usulan($nama)['tambahan_waha'] ?? []);

        if (! $daftar) {
            return $body;
        }

        // Bentuk lama (satu entri) tetap diterima apa adanya.
        if (isset($daftar['kalimat'])) {
            $daftar = [$daftar];
        }

        $nilai = array_values($variabel);

        /*
         * Dihitung SEKALI dari body asli. Tiap kalimat yang disisipkan membawa
         * {{n}} baru, jadi menghitung ulang di dalam gelung akan menggeser
         * slot variabel berikutnya dan memasangkan kalimat dengan nilai milik
         * kalimat lain.
         */
        $offset = self::jumlahVariabel($body);

        foreach ($daftar as $tambahan) {
            $kalimat = (string) ($tambahan['kalimat'] ?? '');
            $setelah = (string) ($tambahan['setelah'] ?? '');
            $jumlah  = max(1, (int) ($tambahan['variabel'] ?? 1));

            $terisi = true;
            for ($i = 0; $i < $jumlah; $i++) {
                if (blank($nilai[$offset + $i] ?? null)) {
                    $terisi = false;
                    break;
                }
            }

            // Slot tetap dimajukan walau kalimatnya dilewati: posisi variabel
            // milik kalimat berikutnya tidak boleh bergantung pada terisi atau
            // tidaknya yang sebelumnya.
            $offset += $jumlah;

            if (! $terisi || $kalimat === '' || $setelah === '') {
                continue;
            }

            $pos = strpos($body, $setelah);

            if ($pos === false) {
                continue;
            }

            $body = substr_replace($body, $setelah . $kalimat, $pos, strlen($setelah));
        }

        return $body;
    }

    /** Jumlah {{n}} berbeda di sebuah body. */
    private static function jumlahVariabel(string $body): int
    {
        preg_match_all('~\{\{\s*\d+\s*\}\}~', $body, $cocok);

        return count(array_unique(array_map(fn ($v) => preg_replace('~\s~', '', $v), $cocok[0])));
    }

    /**
     * Variabel untuk jalur RESMI: dipangkas ke jumlah {{n}} template Meta-nya.
     *
     * Variabel opsional khusus WAHA ('tambahan_waha') tidak boleh ikut — Meta menolak
     * pesan yang jumlah parameternya tak sama dengan templatenya. Template yang tak
     * dikenal di sini dibiarkan apa adanya.
     */
    public static function variabelResmi(string $nama, array $variabel): array
    {
        $body = self::body($nama);

        $variabel = array_values($variabel);

        return $body === null ? $variabel : array_slice($variabel, 0, self::jumlahVariabel($body));
    }

    /**
     * Ganti "silakan balas pesan ini" dengan nomor admin — khusus teks WAHA.
     *
     * Balasan ke nomor WAHA tidak sampai ke siapa pun. Pasangan kalimatnya
     * duduk di entri template ('kalimat_balas' & 'ganti_balas') dengan alasan
     * yang sama seperti lepasTombol(): bersebelahan dengan bodynya supaya tak
     * diam-diam berhenti cocok saat body diubah.
     */
    private static function gantiAjakanBalas(string $nama, string $body): string
    {
        $usulan  = self::usulan($nama) ?? [];
        $kalimat = (string) ($usulan['kalimat_balas'] ?? '');

        if ($kalimat === '' || ! str_contains($body, $kalimat)) {
            return $body;
        }

        $ganti = str_replace('{admin}', (string) config('crm.admin_phone'), (string) ($usulan['ganti_balas'] ?? ''));

        return str_replace($kalimat, $ganti, $body);
    }
}
