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

    // Prefix folder di dalam disk.
    'media_path' => 'store/products',

    /*
    | Garbage collector: file media yang sudah soft-deleted & lebih tua dari
    | sekian hari baru benar-benar dihapus dari disk (jeda aman lewat propagasi
    | cache katalog supaya tak ada gambar rusak). Lihat command store:gc-media.
    */
    'media_gc_days' => env('STORE_MEDIA_GC_DAYS', 7),

    // Batas & jenis file upload foto/video.
    'image_max_kb' => env('STORE_IMAGE_MAX_KB', 5120),    // 5 MB
    'video_max_kb' => env('STORE_VIDEO_MAX_KB', 51200),   // 50 MB
    'image_mimes'  => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    'video_mimes'  => ['mp4', 'webm', 'mov'],

];
