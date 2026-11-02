<?php

/*
|--------------------------------------------------------------------------
| Galeri — aset foto & video produk (menu Galeri di PWA /cs)
|--------------------------------------------------------------------------
|
| Berkas TIDAK disimpan di disk server: `root` menunjuk folder Synology
| (`photo/Foto Produk`) yang di-mount ke server lewat SMB. Folder itulah satu-satunya
| sumber kebenaran — yang ditaruh admin dari Windows Explorer ikut tampil di HP,
| dan sebaliknya. Server hanya menyimpan thumbnail (kecil, bisa dibuat ulang)
| dan potongan unggahan yang sedang berjalan.
|
| Di server: GALERI_ROOT=/mnt/nas/foto-produk dan GALERI_WAJIB_MOUNT=true.
| Tanpa mount, folder mount-point hanyalah folder kosong di disk server —
| unggahan akan mendarat diam-diam di server, bukan di NAS. `wajib_mount`
| menolak semua tulisan selama NAS tidak tersambung.
|
*/

return [

    // Nama yang tampil sebagai akar breadcrumb (nama shared folder di Synology).
    'nama' => env('GALERI_NAMA', 'Foto Produk'),

    // Folder lokal untuk pengembangan; di server diisi path mount NAS.
    'root' => env('GALERI_ROOT', storage_path('app/galeri-dev')),

    'wajib_mount' => (bool) env('GALERI_WAJIB_MOUNT', false),

    // Berkas yang dihapus dipindah ke sini (relatif terhadap root), bukan dihapus
    // permanen. Root adalah subfolder share `photo`, jadi ini folder biasa di dalam
    // Foto Produk, BUKAN Recycle Bin DSM (yang ada di akar share): tak tampil di HP,
    // terlihat di Windows, dan tidak terhapus otomatis.
    'sampah' => env('GALERI_SAMPAH', '#recycle'),

    // Disk server: thumbnail & potongan unggahan.
    'thumb_dir' => storage_path('app/galeri-thumbs'),
    'tmp_dir'   => storage_path('app/galeri-tmp'),

    // Lebar/tinggi terpanjang thumbnail (px).
    'thumb_px' => 480,

    // Biner ffmpeg untuk thumbnail video. Kosong / tidak ada → video tampil
    // dengan ikon ▶ saja.
    'ffmpeg' => env('GALERI_FFMPEG', '/usr/bin/ffmpeg'),

    // Ukuran satu potongan unggahan (MB). Lewat Cloudflare satu request
    // dibatasi 100 MB; dibuat kecil supaya juga di bawah upload_max_filesize.
    'potongan_mb' => (int) env('GALERI_POTONGAN_MB', 8),

    // Batas satu berkas (MB). Video pendek < 10 menit.
    'maks_mb' => (int) env('GALERI_MAKS_MB', 2048),

    'ekstensi' => [
        'gambar' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'],
        'video'  => ['mp4', 'mov', 'm4v', '3gp', 'webm', 'mkv'],
    ],

];
