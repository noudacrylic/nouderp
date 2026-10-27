<?php

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
    Route::get('/pengaturan', [MejaKerjaController::class, 'pengaturan'])->name('pengaturan');
    Route::put('/pengaturan', [MejaKerjaController::class, 'simpanPengaturan'])->name('pengaturan.simpan');
});
