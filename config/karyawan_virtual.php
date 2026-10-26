<?php

/*
|--------------------------------------------------------------------------
| Karyawan Virtual
|--------------------------------------------------------------------------
|
| Otomasi ERP dibungkus jadi "karyawan": profil + daftar skill + jurnal kerja.
| Logika tetap di service modulnya masing-masing; skill hanya pembungkus yang
| menentukan KAPAN boleh bertindak (mode) dan MENCATAT apa yang dikerjakan.
|
| Per karyawan:
|   'akses'  → menu_key yang membuka halaman karyawan ini (ikut hak divisinya,
|              bukan izin baru di User & Akses)
|   'skills' → kunci skill => kelas yang mengimplementasikan Contracts\Skill
|
| Mode skill (lihat Contracts\Skill::MODE_*):
|   nonaktif    → tidak dijalankan
|   bayangan    → jalan di data asli, HANYA mencatat "seandainya aktif"
|   persetujuan → membuat usulan; dikerjakan setelah disetujui (Telegram/web)
|                 — hanya skill yang mengimplementasikan Contracts\BisaDiusulkan
|   otomatis    → langsung bertindak
| Skill baru: lolos tes → Bayangan 1-2 minggu → baru naik. Ganti mode: super_admin.
|
*/

return [

    'manager-produksi' => [
        'nama'      => 'Manager Produksi',
        'ikon'      => '🏭',
        'divisi'    => 'Produksi',
        'deskripsi' => 'Menjaga stok produk ready tidak kosong (termasuk Nama Meja dari BNM) dan memastikan setiap pesanan pre-order punya order produksi.',
        'akses'     => 'production.orders',
        'skills'    => [
            'produksi.op-stok-menipis' => \App\Modules\KaryawanVirtual\Skills\Produksi\OpStokMenipis::class,
            'produksi.op-preorder'     => \App\Modules\KaryawanVirtual\Skills\Produksi\OpPreorder::class,
            'produksi.op-nm-dari-bnm'  => \App\Modules\KaryawanVirtual\Skills\Produksi\OpNmDariBnm::class,
            'produksi.isi-antrean-rakit' => \App\Modules\KaryawanVirtual\Skills\Produksi\OpIsiAntreanRakit::class,
            'produksi.isi-antrean-cnc'   => \App\Modules\KaryawanVirtual\Skills\Produksi\OpIsiAntreanCnc::class,
            'produksi.coret-sampel-mesin' => \App\Modules\KaryawanVirtual\Skills\Produksi\CoretSampelMesin::class,
        ],
        // Skill yang masih diuji: diberi label "Dalam percobaan" di halaman & pesan Telegram.
        // Hapus dari daftar ini setelah hasilnya dipercaya (biasanya bersamaan naik ke Otomatis).
        'percobaan' => ['produksi.op-nm-dari-bnm', 'produksi.isi-antrean-rakit', 'produksi.isi-antrean-cnc'],
    ],

];
