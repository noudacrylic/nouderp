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
     * Daftar putih penerima (nomor E.164 tanpa '+', mis. '628998844666').
     * Selama tidak kosong, pengiriman ke nomor di luar daftar DITOLAK di
     * adapter — sebelum menyentuh jaringan. Kosongkan hanya setelah uji nyata
     * selesai. JANGAN pernah menguji ke nomor pelanggan asli.
     */
    'allowed_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CRM_CHAT_ALLOWED_RECIPIENTS', ''))
    ))),

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
];
