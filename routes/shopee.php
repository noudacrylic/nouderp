<?php

use App\Modules\Marketplace\Shopee\Controllers\MutasiShopeeController;
use App\Modules\Marketplace\Shopee\Controllers\ShopeeBayanganController;
use App\Modules\Marketplace\Shopee\Controllers\ShopeeSettingController;
use Illuminate\Support\Facades\Route;

/*
| Shopee Open Platform. Nama rute sengaja menumpang pola menu yang sudah ada:
|  - settings.integrations.*                    → hak akses menu Pengaturan › Integrasi
|  - finance.cash-bank.settlements.*            → hak akses menu Rekonsiliasi Marketplace
*/

Route::middleware('auth')->group(function () {
    Route::prefix('erp/settings/shopee')->name('settings.integrations.shopee.')->group(function () {
        Route::get('/', [ShopeeSettingController::class, 'index'])->name('index');
        Route::put('/', [ShopeeSettingController::class, 'simpan'])->name('update');
        Route::post('/cek-kunci', [ShopeeSettingController::class, 'cekKunci'])->name('cek-kunci');
        Route::get('/hubungkan', [ShopeeSettingController::class, 'hubungkan'])->name('hubungkan');
        Route::post('/{id}/uji', [ShopeeSettingController::class, 'uji'])->whereNumber('id')->name('uji');
        Route::delete('/{id}', [ShopeeSettingController::class, 'putuskan'])->whereNumber('id')->name('putuskan');
        Route::put('/chat', [ShopeeSettingController::class, 'simpanChat'])->name('chat');

        // Tahap 2 — bayangan (baca saja)
        Route::get('/peta-sku', [ShopeeBayanganController::class, 'petaSku'])->name('peta-sku');
        Route::put('/peta-sku/{id}', [ShopeeBayanganController::class, 'simpanPeta'])->whereNumber('id')->name('peta-sku.simpan');
        Route::post('/peta-sku/tarik', [ShopeeBayanganController::class, 'tarikKatalog'])->name('peta-sku.tarik');
        Route::get('/pesanan', [ShopeeBayanganController::class, 'pesanan'])->name('pesanan');
        Route::post('/pesanan/tarik', [ShopeeBayanganController::class, 'tarikPesanan'])->name('pesanan.tarik');
        Route::post('/pesanan/banding-ulang', [ShopeeBayanganController::class, 'bandingUlang'])->name('pesanan.banding-ulang');
    });
    // Alamat kembali dari halaman izin Shopee (domainnya didaftarkan di app Shopee).
    Route::get('erp/integrasi/shopee/callback', [ShopeeSettingController::class, 'callback'])
        ->name('settings.integrations.shopee.callback');

    Route::prefix('erp/finance/cash-bank/settlements/mutasi-shopee')->name('finance.cash-bank.settlements.mutasi-shopee.')->group(function () {
        Route::get('/', [MutasiShopeeController::class, 'index'])->name('index');
        Route::post('/impor', [MutasiShopeeController::class, 'impor'])->name('impor');
        Route::post('/cocokkan', [MutasiShopeeController::class, 'cocokkan'])->name('cocokkan');
    });
});
