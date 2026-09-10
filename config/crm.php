<?php

/**
 * Modul CRM (chat pelanggan: WhatsApp / Instagram / Messenger).
 *
 * Nilai di sini adalah DEFAULT & pengaman; kredensial asli tinggal di tabel
 * crm_settings (pola singleton-per-provider, sama seperti shipping_settings).
 */
return [

    /*
     * Driver aktif: 'apicoid' | 'fake'.
     * 'fake' = driver palsu (mencatat, tidak pernah menyentuh jaringan) —
     * dipakai seluruh pengujian dan boleh dipakai di lokal.
     */
    'driver' => env('CRM_CHAT_DRIVER', 'apicoid'),

    /*
     * SAKLAR "JANGAN KIRIM" global.
     * Menyala = ChatManager menyerahkan driver palsu ke SEMUA pemanggil, jadi
     * pesan tetap tercatat lengkap tapi tak satu pun benar-benar terkirim.
     * Ini pengaman utama sepanjang pembangunan; matikan hanya di Tahap 6.
     */
    'dry_run' => env('CRM_CHAT_DRY_RUN', true),

    /*
     |--------------------------------------------------------------------------
     | Jalur NOTIFIKASI (beda dari jalur chat di atas)
     |--------------------------------------------------------------------------
     |
     | Chat & notifikasi dipisah berdasarkan PERAN, bukan vendor:
     |  - chat  : jalur resmi berbayar. Kebanyakan KITA yang membalas, jadi yang
     |            berbayar cuma memulai percakapan — murah, dan aman.
     |  - notif : volumenya paling besar tapi isinya paling sederhana, jadi
     |            paling mahal kalau dibayar per pesan. Ini yang pindah ke WAHA.
     |
     | 'driver': 'resmi' (template Meta lewat ChatProvider) | 'waha' | 'fake'.
     | Bawaannya SENGAJA 'resmi' — menyalakan WAHA harus keputusan sadar, dan
     | baru sah setelah sesinya benar-benar tertaut.
     */
    'notifikasi' => [

        'driver' => env('CRM_NOTIF_DRIVER', 'resmi'),

        /*
         | Saklar per JENIS notifikasi, diatur dari layar Notifikasi Pesanan.
         | Bawaannya semua menyala: bawaan yang mendiamkan notifikasi berbahaya —
         | pelanggan berhenti dapat kabar tanpa gejala apa pun.
         |
         | Dimatikan BUKAN berarti hilang: barisnya tetap dibuat dengan status
         | 'dilewati' beserta alasannya, supaya pertanyaan "kenapa pelanggan ini
         | tidak dapat kabar?" tetap ada jawabannya.
         */
        'aktif' => [
            'pembayaran_diterima' => true,
            'siap_diambil'        => true,
            'dikirim'             => true,
            'pancingan'           => true,
        ],

        /*
         | Berapa lama sebuah notifikasi boleh TERTAHAN (sesi WAHA mati) sebelum
         | dialihkan ke template Meta berbayar. Menahan itu benar — sesi putus
         | lumrah dan biasanya pulih sendiri — tapi kabar "pesanan Anda sudah
         | dikirim" yang datang sehari kemudian sama tak bergunanya dengan tidak
         | datang sama sekali. Dipakai Tahap 3.
         */
        'tahan_maks_jam' => (int) env('CRM_NOTIF_TAHAN_MAKS_JAM', 3),

        /*
         | Jeda sebelum baris yang tertahan dicoba lagi (menit). Bukan
         | percobaan-ulang kilat: sesi yang mati butuh scan QR oleh manusia.
         */
        'tahan_jeda_menit' => (int) env('CRM_NOTIF_TAHAN_JEDA_MENIT', 10),

        /*
         | Rem pengulangan peringatan Telegram saat sesi putus. Sesi mati
         | semalaman tidak boleh jadi ratusan pesan: peringatan yang terlalu
         | sering berhenti dibaca, persis saat ia paling perlu dibaca.
         */
        'peringatan_jeda_menit' => (int) env('CRM_NOTIF_PERINGATAN_JEDA_MENIT', 60),

        'waha' => [
            /*
             | ⚠️ WAJIB 127.0.0.1. API key WAHA = kunci penuh sebuah akun
             | WhatsApp, dan instance WAHA terbuka rutin dipindai bot. ERP &
             | WAHA satu server — tidak ada alasan alamat ini keluar localhost,
             | dan JANGAN disambungkan ke Cloudflare Tunnel.
             */
            'base_url' => env('CRM_WAHA_BASE_URL', 'http://127.0.0.1:3000'),

            /*
             | Nama sesi pengirim. Dua sesi dijalankan: nomor aktif + nomor
             | cadangan yang sudah dipanaskan — itu yang membuat "tinggal ganti
             | kalau kena blokir" jadi nyata, bukan sekadar rencana.
             */
            'session' => env('CRM_WAHA_SESSION', 'notifikasi'),

            'timeout' => (int) env('CRM_WAHA_TIMEOUT', 20),
        ],
    ],

    /*
     * Daftar putih penerima (nomor E.164 tanpa '+', mis. '628998844666').
     * Selama tidak kosong, pengiriman ke nomor di luar daftar DITOLAK di
     * adapter — sebelum menyentuh jaringan. Kosongkan hanya setelah uji nyata
     * selesai. JANGAN pernah menguji ke nomor pelanggan asli.
     */
    'allowed_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CRM_CHAT_ALLOWED_RECIPIENTS', ''))
    ))),

    /*
     * Alamat etalase, dipakai rail Produk untuk merangkai tautan yang dikirim
     * ke pelanggan. Tanpa garis miring di ujung.
     */
    'storefront_url' => rtrim((string) env('CRM_STOREFRONT_URL', 'https://noudakrilik.com'), '/'),

    /*
     * Nomor admin yang dicantumkan di notifikasi "stok sudah ada".
     *
     * Jadi variabel, bukan teks mati di dalam template: kalau nomornya berganti
     * (ganti SIM, pindah ke nomor bisnis kedua), yang harus diubah satu baris
     * env — bukan bunyi template yang punya konsekuensi ke sisi Meta.
     */
    'admin_phone' => env('CRM_ADMIN_PHONE', '08998844666'),

    /*
     * Penagihan pesanan yang tautan bayarnya sudah dibuat tapi belum dibayar.
     *
     * `hari_kirim` dihitung dari lahirnya tautan bayar, bukan dari SO-nya:
     * yang ditagih adalah tautan itu, dan SO bisa saja dibuat jauh lebih dulu
     * (nego panjang) tanpa satu pun tagihan pantas dikirim.
     *
     * Rapat di depan lalu merenggang: tiga hari pertama tiap hari (saat niat
     * membeli masih hangat), sesudahnya mingguan (menagih tiap hari selama
     * sebulan adalah cara tercepat diblokir). `batal_hari` = 4 minggu.
     */
    'tagihan' => [
        'hari_kirim' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('CRM_TAGIHAN_HARI', '1,2,3,10,17,24'))
        ))),
        'batal_hari' => (int) env('CRM_TAGIHAN_BATAL_HARI', 28),
    ],

    /*
     |--------------------------------------------------------------------------
     | Pancingan jendela 24 jam
     |--------------------------------------------------------------------------
     |
     | Sejam sebelum jendela habis, percakapan yang pembahasannya masih hidup
     | dikirimi pesan bertombol. Selama jendelanya belum tutup, pesan itu GRATIS
     | — dan itulah seluruh alasan fitur ini ada: memindahkan pancingan ke sisi
     | jendela yang tidak berbayar, supaya template Meta tinggal jadi jaring
     | pengaman.
     |
     | Jamnya BUKAN jam toko. Yang ditakar di sini bukan kapan kita melayani,
     | melainkan kapan pantas mengganggu orang — pelanggan justru paling banyak
     | membuka WhatsApp selepas jam kerja. Jendela yang akan habis di tengah
     | malam ditarik maju ke jam sopan terakhir, bukan dibiarkan berbunyi
     | pukul dua pagi.
     */
    'pancingan' => [
        /*
         | Saklar mati-nyalanya BUKAN di sini melainkan di layar Notifikasi
         | Pesanan, sederet dengan jenis lain (JenisNotifikasi::aktif). Yang
         | tersisa di sini cuma yang tak masuk akal diatur dari layar.
         */
        'jam_mulai'      => (int) env('CRM_PANCINGAN_JAM_MULAI', 7),
        'jam_akhir'      => (int) env('CRM_PANCINGAN_JAM_AKHIR', 21),
        // Rem yang sama alasannya dengan crm:kirim-notifikasi: vendor membatasi
        // 60 permintaan per menit, dan penjadwal tidak boleh jadi yang pertama
        // menabraknya.
        'maks_per_jalan' => (int) env('CRM_PANCINGAN_MAKS', 50),
    ],

    /* Jam toko untuk template "siap diambil" ({{4}}). Diubah lewat Pengaturan nanti. */
    'store_hours_text' => env('CRM_STORE_HOURS', 'Senin–Sabtu 08.00–16.00'),

    /*
     * Jam buka/tutup dalam angka, dipakai penjadwal "jam sopan".
     * WAJIB sejalan dengan kalimat di atas: yang satu dibaca pelanggan, yang
     * satu lagi menentukan kapan pesannya dikirim. Beda = pelanggan diberi tahu
     * pada jam yang bukan jam bukanya.
     */
    'store_open_hour'  => (int) env('CRM_STORE_OPEN_HOUR', 8),
    'store_close_hour' => (int) env('CRM_STORE_CLOSE_HOUR', 16),

    /*
     |--------------------------------------------------------------------------
     | Lampiran (media masuk)
     |--------------------------------------------------------------------------
     |
     | Media WAJIB diunduh ke penyimpanan sendiri: Meta membuang salinannya
     | setelah ~30 hari, sedangkan diskusi custom bisa menggantung berbulan-bulan
     | lalu kehilangan logonya — dan logo itu tidak bisa diminta ulang ke API.
     |
     | Disknya SENGAJA 'local' (storage/app/private), bukan 'public': isinya
     | berkas milik pelanggan. Disk 'public' bisa dibaca siapa pun yang menebak
     | URL-nya, tanpa login.
     */
    /*
     | Umur tautan sementara lampiran KELUAR (menit). Meta harus sempat
     | mengambil berkasnya, tapi tautannya tak boleh hidup lebih lama dari
     | perlunya. Beberapa menit sudah cukup; 30 memberi ruang saat jaringan
     | lambat atau vendor mengantre.
     */
    'media_link_minutes' => (int) env('CRM_MEDIA_LINK_MINUTES', 30),

    'media_disk' => env('CRM_MEDIA_DISK', 'local'),
    'media_path' => env('CRM_MEDIA_PATH', 'crm/lampiran'),

    /*
     | Batas ukuran satu berkas. Bukan soal ruang (server lega), melainkan rem:
     | satu video besar atau webhook yang mengulang tidak boleh menulis tanpa
     | henti ke disk yang sama dengan database.
     */
    'max_media_bytes' => (int) env('CRM_MAX_MEDIA_BYTES', 25 * 1024 * 1024),

    /*
     | Masa simpan lampiran yang TIDAK tertaut dokumen ERP (lead mati, basa-basi,
     | tangkapan layar yang sudah diverifikasi). Lampiran di percakapan yang punya
     | pelanggan atau dokumen tidak pernah tersapu — di situlah logo yang sudah
     | tercetak berada, dan itu tak bisa diambil lagi dari mana pun.
     */
    'attachment_retention_days' => (int) env('CRM_ATTACHMENT_RETENTION_DAYS', 180),

    /*
     |--------------------------------------------------------------------------
     | Agen AI (Tahap 8)
     |--------------------------------------------------------------------------
     |
     | 'aktif' adalah saklar INDUK, di atas saklar per-agen di crm_agents. Selama
     | ia mati, tidak ada satu pun agen yang boleh membalas pelanggan, apa pun
     | isi barisnya di basis data. Dua lapis sengaja, dengan alasan yang sama
     | seperti 'dry_run' di atas: yang paling mahal di modul ini bukan salah
     | hitung melainkan pesan yang terlanjur keluar.
     |
     | Mode uji di layar TIDAK dijaga saklar ini — ia memang tidak pernah
     | menyentuh pelanggan.
     */
    'agen' => [
        'aktif' => (bool) env('CRM_AGEN_AKTIF', false),

        /*
         | Model per agen; baris di crm_agents boleh menimpanya.
         |
         | Penyambutan pakai Haiku: kerjanya klasifikasi & jawaban pendek, dan ia
         | jalan di SETIAP pesan masuk — di situlah volume, jadi di situ pula
         | selisih harga paling terasa. Penjualan & cetak pakai Sonnet: keduanya
         | berantai panjang dan berujung pada uang atau akrilik yang terlanjur
         | dipotong, dan salah di langkah ketiga baru ketahuan di langkah ketujuh.
         */
        'model_bawaan' => [
            'penyambutan' => env('CRM_AGEN_MODEL_PENYAMBUTAN', 'claude-haiku-4-5'),
            'penjualan'   => env('CRM_AGEN_MODEL_PENJUALAN', 'claude-sonnet-5'),
            'cetak'       => env('CRM_AGEN_MODEL_CETAK', 'claude-sonnet-5'),
        ],

        // Dipakai kalau kodenya tidak ada di peta di atas.
        'model_cadangan' => env('CRM_AGEN_MODEL_CADANGAN', 'claude-haiku-4-5'),

        'maks_token_keluar' => (int) env('CRM_AGEN_MAKS_TOKEN', 1024),

        /*
         | Tarif per 1 juta token, dalam USD, apa adanya dari daftar harga
         | Anthropic. Ditaruh di config karena harga model berubah — dan biaya
         | tiap run dihitung SAAT run, bukan saat dibaca, supaya angka masa lalu
         | tidak ikut berubah waktu tarifnya naik.
         |
         | 'cache' = pembacaan dari cache, 0,1x tarif input.
         */
        'tarif' => [
            'claude-haiku-4-5' => ['masuk' => 1.00, 'cache' => 0.10, 'keluar' => 5.00],
            'claude-sonnet-5'  => ['masuk' => 2.00, 'cache' => 0.20, 'keluar' => 10.00],
            'claude-opus-5'    => ['masuk' => 5.00, 'cache' => 0.50, 'keluar' => 25.00],
        ],

        // Kurs untuk mengubah tarif USD di atas jadi rupiah yang terbaca di layar.
        'kurs_usd' => (float) env('CRM_AGEN_KURS_USD', 16400),
    ],
];
