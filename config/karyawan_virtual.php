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
|   persetujuan → membuat usulan untuk disetujui (tahap 2)
|   otomatis    → langsung bertindak
| Skill baru: lolos tes → Bayangan 1-2 minggu → baru naik. Ganti mode: super_admin.
|
*/

return [

    'manager-produksi' => [
        'nama'      => 'Manager Produksi',
        'ikon'      => '🏭',
        'divisi'    => 'Produksi',
        'deskripsi' => 'Menjaga stok produk ready tidak kosong dan memastikan setiap pesanan pre-order punya order produksi.',
        'akses'     => 'production.orders',
        'skills'    => [
            'produksi.op-stok-menipis' => \App\Modules\KaryawanVirtual\Skills\Produksi\OpStokMenipis::class,
            'produksi.op-preorder'     => \App\Modules\KaryawanVirtual\Skills\Produksi\OpPreorder::class,
        ],
    ],

];
