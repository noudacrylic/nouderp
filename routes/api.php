<?php

use App\Http\Controllers\Api\Storefront\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Stateless, prefix otomatis /api. Endpoint baca etalase (noudakrilik.com)
| digerbangi middleware storefront.api (kunci rahasia, server-side only).
*/

Route::prefix('storefront')->middleware('storefront.api')->group(function () {
    Route::get('/categories',        [StorefrontController::class, 'categories'])->name('api.storefront.categories');
    Route::get('/products',          [StorefrontController::class, 'products'])->name('api.storefront.products');
    Route::get('/products/{slug}',   [StorefrontController::class, 'product'])->name('api.storefront.product');
    Route::get('/stock',             [StorefrontController::class, 'stock'])->name('api.storefront.stock');
    Route::get('/promotions',        [StorefrontController::class, 'promotions'])->name('api.storefront.promotions');
});
