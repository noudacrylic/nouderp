<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk media etalase (foto & video Produk Store)
    |--------------------------------------------------------------------------
    | Kredensial R2 DIPUSATKAN di Settings → Integrasi → Cloudflare R2 (DB,
    | model R2Setting). Bila R2 aktif, StoreMediaService membangun disk S3 dari
    | DB. Bila TIDAK aktif, media jatuh ke disk lokal di bawah ini ('public').
    | Saat go-live R2: composer require league/flysystem-aws-s3-v3, lalu isi &
    | aktifkan kredensial di halaman Settings (tanpa edit .env).
    */
    'local_disk' => env('STORE_LOCAL_DISK', 'public'),

    /*
    | Alamat publik etalase. Dipakai ERP untuk mengantar pembeli dari halaman
    | bayar ke halaman lacak pesanan — halaman itu tinggal di toko, bukan di ERP.
    */
    'storefront_url' => env('STOREFRONT_URL', 'https://noudakrilik.com'),

    // Prefix folder di dalam disk.
    'media_path'    => 'store/products',
    'article_path'  => 'store/articles',
    'tutorial_path' => 'store/tutorials',
    'homepage_path' => 'store/homepage',

    /*
    | Garbage collector: file media yang sudah soft-deleted & lebih tua dari
    | sekian hari baru benar-benar dihapus dari disk (jeda aman lewat propagasi
    | cache katalog supaya tak ada gambar rusak). Lihat command store:gc-media.
    */
    'media_gc_days' => env('STORE_MEDIA_GC_DAYS', 7),

    // Batas & jenis file upload foto/video.
    'image_max_kb' => env('STORE_IMAGE_MAX_KB', 5120),    // 5 MB

    /*
    | Gambar INLINE di editor artikel & tutorial punya batas sendiri yang lebih
    | longgar: berbeda dengan foto produk, gambar ini selalu diperkecil dulu di
    | server (ImageDownscaler, sisi panjang 1600 px) sehingga foto 10 MB pun
    | berakhir di kisaran 200 KB. Batas 5 MB di sini hanya berarti satu hal:
    | foto ponsel apa adanya ditolak, dan admin yang memotret langkah pemasangan
    | selalu memotret dengan ponsel.
    */
    'editor_image_max_kb' => env('STORE_EDITOR_IMAGE_MAX_KB', 12288),   // 12 MB
    'video_max_kb' => env('STORE_VIDEO_MAX_KB', 51200),   // 50 MB
    'image_mimes'  => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    'video_mimes'  => ['mp4', 'webm', 'mov'],

];
