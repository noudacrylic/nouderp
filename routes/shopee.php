<?php

use App\Modules\Marketplace\Shopee\Controllers\MutasiShopeeController;
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
