<?php

use App\Modules\KaryawanVirtual\Controllers\MinStokController;
use App\Modules\KaryawanVirtual\Controllers\PengaturanAdminController;
use App\Modules\Purchasing\Controllers\LaporStokController;
use App\Modules\Tasks\MejaKerja\MejaKerjaController;
use Illuminate\Support\Facades\Route;

/*
| Meja Kerja Admin (Task Manager). Di-require SEBELUM grup erp/tasks supaya "meja" tidak
| tertangkap parameter {task}. Nama rute tasks.meja.* → hak akses menu Task Manager.
*/

Route::middleware('auth')->prefix('erp/tasks/meja')->name('tasks.meja.')->group(function () {
    Route::get('/', [MejaKerjaController::class, 'index'])->name('index');
    Route::post('/ambil', [MejaKerjaController::class, 'ambil'])->name('ambil');
    Route::post('/sinkron', [MejaKerjaController::class, 'sinkron'])->name('sinkron');
    Route::post('/{task}/mulai', [MejaKerjaController::class, 'mulai'])->whereNumber('task')->name('mulai');
    Route::post('/{task}/pending', [MejaKerjaController::class, 'pending'])->whereNumber('task')->name('pending');
    Route::post('/{task}/selesai', [MejaKerjaController::class, 'selesai'])->whereNumber('task')->name('selesai');
    Route::post('/{task}/jawab', [MejaKerjaController::class, 'jawab'])->whereNumber('task')->name('jawab');
    // Pengaturan pindah ke karyawan virtual Manajer Admin; tautan lama tetap sampai.
    Route::redirect('/pengaturan', '/erp/karyawan-virtual/manajer-admin/pengaturan-admin')->name('pengaturan');
});

/*
| Pengaturan karyawan virtual Manajer Admin (pemilik skill Meja Kerja): peran & parameter
| skill, serta min stok bahan untuk skill Purchasing ready stok. Satu awalan dengan menu Karyawan Virtual.
*/
Route::middleware('auth')->prefix('erp/karyawan-virtual/{kode}')->name('karyawan-virtual.admin.')->group(function () {
    Route::get('/pengaturan-admin', [PengaturanAdminController::class, 'show'])->name('pengaturan');
    Route::put('/pengaturan-admin', [PengaturanAdminController::class, 'simpan'])->name('pengaturan.simpan');
    Route::get('/min-stok', [MinStokController::class, 'index'])->name('min-stok');
    Route::post('/min-stok', [MinStokController::class, 'simpan'])->name('min-stok.simpan');
});

/*
| Lapor Stok — pintu masuk laporan barang habis dari layar kerja divisi ke Purchasing
| (skill "Purchasing — laporan divisi"). Hanya butuh login.
*/
Route::middleware('auth')->prefix('erp/lapor-stok')->name('lapor-stok.')->group(function () {
    Route::get('/pilihan', [LaporStokController::class, 'pilihan'])->name('pilihan');
    Route::post('/', [LaporStokController::class, 'simpan'])->name('simpan');
});
