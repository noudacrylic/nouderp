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
|   'akses'  → menu_key divisinya: pemegangnya menerima & boleh memutus usulan
|              (Telegram). Membuka halaman Karyawan Virtual = izin menu tersendiri
|              `karyawan-virtual` di User & Akses.
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
            'produksi.ganti-pelaksana'    => \App\Modules\KaryawanVirtual\Skills\Produksi\GantiPelaksana::class,
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
        'deskripsi'  => 'Memantau pekerjaan admin yang muncul di ERP (desain pesanan, QC, purchasing bahan ready stok & non stok, barang PO yang belum datang, cetak stiker untuk OP, desain gambar & langkah cetak/tempel stiker OP custom, retur penjualan, ulasan Shopee bintang jelek), lalu memasukkannya ke meja peran yang tepat di Meja Kerja (Layanan, Desain). Retur Shopee yang dibatalkan pembeli dan paket hilang yang sudah diganti Shopee diselesaikannya sendiri; hasil retur Shopee yang sudah final ditulisnya ke draft untuk diperiksa admin. Ulasan Shopee bintang bagus dibalasnya sendiri (template atau AI).',
        'akses'      => 'tasks.board',
        'pengaturan' => 'karyawan-virtual.admin.pengaturan',
        'skills'     => [
            'admin.desain'             => \App\Modules\Tasks\MejaKerja\Skills\DesainSkill::class,
            'admin.desain-gambar'      => \App\Modules\Tasks\MejaKerja\Skills\DesainGambarSkill::class,
            'admin.desain-gambar-op'   => \App\Modules\Tasks\MejaKerja\Skills\DesainGambarOpSkill::class,
            'admin.langkah-desain'     => \App\Modules\Tasks\MejaKerja\Skills\LangkahDesainSkill::class,
            'admin.foto-custom'        => \App\Modules\Tasks\MejaKerja\Skills\FotoCustomSkill::class,
            'admin.qc'                 => \App\Modules\Tasks\MejaKerja\Skills\QcSkill::class,
            'admin.purchasing-ready'   => \App\Modules\Tasks\MejaKerja\Skills\PurchasingReadySkill::class,
            'admin.purchasing-nonstok' => \App\Modules\Tasks\MejaKerja\Skills\PurchasingNonStokSkill::class,
            'admin.purchasing-laporan' => \App\Modules\Tasks\MejaKerja\Skills\PurchasingLaporanSkill::class,
            'admin.selesaikan-pesanan' => \App\Modules\Tasks\MejaKerja\Skills\SelesaikanPesananSkill::class,
            'admin.cetak-stiker'       => \App\Modules\Tasks\MejaKerja\Skills\CetakStikerSkill::class,
            'admin.penugasan'          => \App\Modules\Tasks\MejaKerja\Skills\PenugasanSkill::class,
            'admin.prioritas'          => \App\Modules\Tasks\MejaKerja\Skills\PrioritasSkill::class,
            'admin.tugas-owner'        => \App\Modules\Tasks\MejaKerja\Skills\TugasOwnerSkill::class,
            'admin.retur'              => \App\Modules\Tasks\MejaKerja\Skills\ReturSkill::class,
            'admin.ulasan-jelek'       => \App\Modules\Tasks\MejaKerja\Skills\UlasanJelekSkill::class,
            'admin.ulasan-bagus'       => \App\Modules\KaryawanVirtual\Skills\Admin\BalasUlasanBagus::class,
            'admin.retur-tidak-jadi'   => \App\Modules\KaryawanVirtual\Skills\Admin\ReturTidakJadi::class,
            'admin.retur-paket-hilang' => \App\Modules\KaryawanVirtual\Skills\Admin\ReturPaketHilang::class,
            'admin.retur-isi-hasil'    => \App\Modules\KaryawanVirtual\Skills\Admin\ReturIsiHasil::class,
        ],
        // Skill berjadwal sendiri — tidak ikut sinkron Meja Kerja tiap 2 menit (memanggil API luar).
        'jadwal_sendiri' => ['admin.retur-tidak-jadi', 'admin.retur-paket-hilang', 'admin.retur-isi-hasil', 'admin.ulasan-bagus'],
        'percobaan' => ['admin.desain-gambar', 'admin.desain-gambar-op', 'admin.langkah-desain', 'admin.purchasing-ready', 'admin.purchasing-nonstok', 'admin.purchasing-laporan', 'admin.selesaikan-pesanan', 'admin.cetak-stiker', 'admin.retur-tidak-jadi', 'admin.retur-paket-hilang', 'admin.retur', 'admin.retur-isi-hasil', 'admin.ulasan-jelek', 'admin.ulasan-bagus', 'admin.prioritas', 'admin.tugas-owner'],
    ],

    // Mengurus pekerjaan pribadi owner: yang butuh keputusannya masuk Meja Owner (satu klik di
    // kartu atau Telegram), pencatatan keuangan lewat chat Telegram.
    'asisten-owner' => [
        'nama'       => 'Asisten Owner',
        'ikon'       => '🧑‍💼',
        'divisi'     => 'Owner',
        'deskripsi'  => 'Mengumpulkan pekerjaan yang butuh keputusan owner ke Meja Owner (usulan Karyawan Virtual, pengajuan izin karyawan) dan mencatat transaksi keuangan dari chat Telegram.',
        'akses'      => 'karyawan-virtual',
        'hanya_owner' => true,
        'pengaturan' => 'karyawan-virtual.admin.pengaturan',
        'skills'     => [
            'owner.persetujuan-usulan'  => \App\Modules\Tasks\MejaKerja\Skills\PersetujuanUsulanSkill::class,
            'owner.pengajuan-izin'      => \App\Modules\Tasks\MejaKerja\Skills\PengajuanIzinSkill::class,
            'owner.lapor-owner'         => \App\Modules\Tasks\MejaKerja\Skills\LaporOwnerSkill::class,
            'owner.isi-saldo'           => \App\Modules\Tasks\MejaKerja\Skills\IsiSaldoSkill::class,
            'owner.bayar-owner'         => \App\Modules\Tasks\MejaKerja\Skills\BayarOwnerSkill::class,
            'owner.penarikan-saldo'     => \App\Modules\Tasks\MejaKerja\Skills\PenarikanSaldoSkill::class,
            'owner.pencatatan-telegram' => \App\Modules\KaryawanVirtual\Skills\Owner\PencatatanTelegram::class,
        ],
        // Pencatatan berjalan saat ada pesan Telegram — tak ikut sinkron & tak punya "Jalankan sekarang".
        'jadwal_sendiri' => ['owner.pencatatan-telegram'],
        'tanpa_jalankan' => ['owner.pencatatan-telegram'],
        'percobaan' => ['owner.pengajuan-izin'],
    ],

];
