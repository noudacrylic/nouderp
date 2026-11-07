<?php

use App\Modules\KaryawanVirtual\Controllers\MinStokController;
use App\Modules\KaryawanVirtual\Controllers\PengaturanAdminController;
use App\Modules\KaryawanVirtual\Controllers\ProdukDesainController;
use App\Modules\Production\PrintRusak\PrintRusakController;
use App\Modules\Purchasing\Controllers\LaporStokController;
use App\Modules\Tasks\LaporOwner\LaporOwnerController;
use App\Modules\Tasks\MejaKerja\KartuPembelianController;
use App\Modules\Tasks\MejaKerja\MejaKerjaController;
use App\Modules\Tasks\TugasOwner\TugasOwnerController;
use Illuminate\Support\Facades\Route;

/*
| Meja Kerja Admin (Task Manager). Di-require SEBELUM grup erp/tasks supaya "meja" tidak
| tertangkap parameter {task}. Nama rute tasks.meja.* → hak akses menu Task Manager.
*/

Route::middleware('auth')->prefix('erp/tasks/meja')->name('tasks.meja.')->group(function () {
    Route::get('/', [MejaKerjaController::class, 'index'])->name('index');
    Route::get('/lencana', [MejaKerjaController::class, 'lencana'])->name('lencana');
    Route::post('/sinkron', [MejaKerjaController::class, 'sinkron'])->name('sinkron');
    // Print rusak: lembar bahan print rusak → stok keluar, Dr 5007 (HPP). Lihat PrintRusakService.
    Route::get('/print-rusak', [PrintRusakController::class, 'index'])->name('print-rusak.index');
    Route::post('/print-rusak', [PrintRusakController::class, 'store'])->name('print-rusak.store');
    Route::post('/print-rusak/{printRusak}/batal', [PrintRusakController::class, 'void'])->whereNumber('printRusak')->name('print-rusak.void');
    Route::post('/print-rusak/bahan', [PrintRusakController::class, 'tambahBahan'])->name('print-rusak.bahan.tambah');
    Route::delete('/print-rusak/bahan/{product}', [PrintRusakController::class, 'hapusBahan'])->whereNumber('product')->name('print-rusak.bahan.hapus');
    Route::post('/{task}/mulai', [MejaKerjaController::class, 'mulai'])->whereNumber('task')->name('mulai');
    Route::post('/{task}/lanjutkan', [MejaKerjaController::class, 'lanjutkan'])->whereNumber('task')->name('lanjutkan');
    Route::post('/{task}/pending', [MejaKerjaController::class, 'pending'])->whereNumber('task')->name('pending');
    Route::post('/{task}/selesai', [MejaKerjaController::class, 'selesai'])->whereNumber('task')->name('selesai');
    Route::post('/{task}/jawab', [MejaKerjaController::class, 'jawab'])->whereNumber('task')->name('jawab');
    // Per SO, bukan per task: kartu Bayangan tidak tersimpan (id negatif) tapi tombolnya tetap dipakai.
    Route::post('/chat-shopee/{so}', [MejaKerjaController::class, 'chatShopee'])->whereNumber('so')->name('chat-shopee');
    Route::post('/ulasan/{ulasan}/balas', [MejaKerjaController::class, 'balasUlasan'])->whereNumber('ulasan')->name('ulasan.balas');
    Route::post('/{task}/bayar', [KartuPembelianController::class, 'bayar'])->whereNumber('task')->name('bayar');
    Route::post('/{task}/isi-saldo', [KartuPembelianController::class, 'isiSaldo'])->whereNumber('task')->name('isi-saldo');
    Route::post('/{task}/selesaikan', [KartuPembelianController::class, 'selesaikan'])->whereNumber('task')->name('selesaikan');
    Route::post('/{task}/penarikan', [KartuPembelianController::class, 'penarikan'])->whereNumber('task')->name('penarikan');
    Route::prefix('lapor')->name('lapor.')->controller(LaporOwnerController::class)->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('/{lapor}/balas', 'balas')->whereNumber('lapor')->name('balas');
        Route::get('/{lapor}/foto', 'foto')->whereNumber('lapor')->name('foto');
    });
    // Tugas dari Owner: halaman sendiri, dibuka dari tombol di Meja Kerja.
    Route::prefix('tugas')->name('tugas.')->controller(TugasOwnerController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/lampiran/{lampiran}', 'lampiran')->whereNumber('lampiran')->name('lampiran');
        Route::get('/{tugas}', 'show')->whereNumber('tugas')->name('show');
        Route::post('/{tugas}/unggah', 'unggah')->whereNumber('tugas')->name('unggah');
        Route::post('/{tugas}/prioritas', 'prioritas')->whereNumber('tugas')->name('prioritas');
        Route::post('/{tugas}/aksi/{aksi}', 'aksi')->whereNumber('tugas')->where('aksi', '[a-z_]+')->name('aksi');
    });
    // Pengaturan pindah ke karyawan virtual Manajer Admin; tautan lama tetap sampai.
    Route::redirect('/pengaturan', '/erp/karyawan-virtual/manajer-admin/pengaturan-admin')->name('pengaturan');
});

/*
| Pengaturan karyawan virtual Manajer Admin (pemilik skill Meja Kerja): peran & parameter
| skill, min stok bahan untuk skill Purchasing ready stok, dan produk butuh desain untuk skill Desain pesanan. Satu awalan dengan menu Karyawan Virtual.
*/
Route::middleware('auth')->prefix('erp/karyawan-virtual/{kode}')->name('karyawan-virtual.admin.')->group(function () {
    Route::get('/pengaturan-admin', [PengaturanAdminController::class, 'show'])->name('pengaturan');
    Route::put('/pengaturan-admin', [PengaturanAdminController::class, 'simpan'])->name('pengaturan.simpan');
    Route::get('/pengaturan-admin/skill/{skill}', [PengaturanAdminController::class, 'skill'])->where('skill', '[a-z0-9-]+')->name('pengaturan.skill');
    Route::put('/pengaturan-admin/skill/{skill}', [PengaturanAdminController::class, 'simpanSkill'])->where('skill', '[a-z0-9-]+')->name('pengaturan.skill.simpan');
    Route::get('/min-stok', [MinStokController::class, 'index'])->name('min-stok');
    Route::post('/min-stok', [MinStokController::class, 'simpan'])->name('min-stok.simpan');
    Route::get('/produk-desain', [ProdukDesainController::class, 'index'])->name('produk-desain');
    Route::post('/produk-desain', [ProdukDesainController::class, 'tambah'])->name('produk-desain.tambah');
    Route::delete('/produk-desain', [ProdukDesainController::class, 'hapus'])->name('produk-desain.hapus');
});

/*
| Lapor Stok — pintu masuk laporan barang habis dari layar kerja divisi ke Purchasing
| (skill "Purchasing — laporan divisi"). Hanya butuh login.
*/
Route::middleware('auth')->prefix('erp/lapor-stok')->name('lapor-stok.')->group(function () {
    Route::get('/pilihan', [LaporStokController::class, 'pilihan'])->name('pilihan');
    Route::post('/', [LaporStokController::class, 'simpan'])->name('simpan');
});
