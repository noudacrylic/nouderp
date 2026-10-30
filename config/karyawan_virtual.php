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
            'produksi.op-yatim'           => \App\Modules\KaryawanVirtual\Skills\Produksi\OpYatim::class,
            'produksi.op-koin'            => \App\Modules\KaryawanVirtual\Skills\Produksi\OpKoin::class,
        ],
        // Skill yang masih diuji: diberi label "Dalam percobaan" di halaman & pesan Telegram.
        // Hapus dari daftar ini setelah hasilnya dipercaya (biasanya bersamaan naik ke Otomatis).
        'percobaan' => ['produksi.op-nm-dari-bnm', 'produksi.isi-antrean-rakit', 'produksi.isi-antrean-cnc', 'produksi.op-yatim', 'produksi.op-koin'],
    ],

    // Membagi kerja ke manusia: tiap skill memindai pekerjaan terbuka di ERP lalu menaruhnya
    // sebagai task di Meja Kerja peran yang tepat (lihat Tasks\MejaKerja). Peran & bobot
    // diatur di 'pengaturan'.
    'manajer-admin' => [
        'nama'       => 'Manajer Admin',
        'ikon'       => '🗂️',
        'divisi'     => 'Admin',
        'deskripsi'  => 'Memantau pekerjaan admin yang muncul di ERP (desain pesanan, QC, persetujuan owner, purchasing bahan ready stok & non stok, barang PO yang belum datang, cetak stiker untuk OP), lalu memasukkannya ke meja peran yang tepat di Meja Kerja (Layanan, Desain, Owner). Retur Shopee yang dibatalkan pembeli diselesaikannya sendiri.',
        'akses'      => 'tasks.board',
        'pengaturan' => 'karyawan-virtual.admin.pengaturan',
        'skills'     => [
            'admin.desain'             => \App\Modules\Tasks\MejaKerja\Skills\DesainSkill::class,
            'admin.qc'                 => \App\Modules\Tasks\MejaKerja\Skills\QcSkill::class,
            'admin.persetujuan-usulan' => \App\Modules\Tasks\MejaKerja\Skills\PersetujuanUsulanSkill::class,
            'admin.purchasing-ready'   => \App\Modules\Tasks\MejaKerja\Skills\PurchasingReadySkill::class,
            'admin.purchasing-nonstok' => \App\Modules\Tasks\MejaKerja\Skills\PurchasingNonStokSkill::class,
            'admin.purchasing-laporan' => \App\Modules\Tasks\MejaKerja\Skills\PurchasingLaporanSkill::class,
            'admin.kedatangan'         => \App\Modules\Tasks\MejaKerja\Skills\KedatanganSkill::class,
            'admin.cetak-stiker'       => \App\Modules\Tasks\MejaKerja\Skills\CetakStikerSkill::class,
            'admin.penugasan'          => \App\Modules\Tasks\MejaKerja\Skills\PenugasanSkill::class,
            'admin.retur-tidak-jadi'   => \App\Modules\KaryawanVirtual\Skills\Admin\ReturTidakJadi::class,
        ],
        // Skill berjadwal sendiri — tidak ikut sinkron Meja Kerja tiap 2 menit (memanggil API luar).
        'jadwal_sendiri' => ['admin.retur-tidak-jadi'],
        'percobaan' => ['admin.purchasing-ready', 'admin.purchasing-nonstok', 'admin.purchasing-laporan', 'admin.kedatangan', 'admin.cetak-stiker', 'admin.retur-tidak-jadi'],
    ],

];
