<?php

/*
| Berkas brief & hasil Tugas Owner: folder NAS terpisah dari "Foto Produk", karena isinya bukan
| aset produk. Di server: TUGAS_OWNER_ROOT=/mnt/nas/brief-owner. Unggahan ditolak selama mount
| putus bila GALERI_WAJIB_MOUNT=true (penjaganya GaleriService::tersambung).
*/

return [
    'root' => env('TUGAS_OWNER_ROOT', storage_path('app/tugas-owner')),

    // Selain foto & video milik config galeri.ekstensi: rekaman suara dari HP.
    'ekstensi_suara' => ['m4a', 'mp3', 'aac', 'ogg', 'opus', 'wav', 'amr'],

    'jenis' => [
        'upload_produk' => 'Upload produk',
        'tutorial'      => 'Bikin tutorial',
        'konten'        => 'Bikin konten',
        'lain'          => 'Lainnya',
    ],
];
