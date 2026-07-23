<?php

use App\Http\Controllers\Api\Storefront\CheckoutController;
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
    Route::post('/products/{slug}/view', [StorefrontController::class, 'recordView'])->name('api.storefront.product.view');
    Route::get('/stock',             [StorefrontController::class, 'stock'])->name('api.storefront.stock');
    Route::get('/promotions',        [StorefrontController::class, 'promotions'])->name('api.storefront.promotions');

    // Blog/Artikel SEO
    Route::get('/article-categories', [StorefrontController::class, 'articleCategories'])->name('api.storefront.article-categories');
    Route::get('/articles',           [StorefrontController::class, 'articles'])->name('api.storefront.articles');
    Route::get('/articles/{slug}',    [StorefrontController::class, 'article'])->name('api.storefront.article');

    // Checkout: cek alamat/ongkir, buat pesanan, status & klaim transfer.
    Route::get ('/shipping/areas',        [CheckoutController::class, 'areas'])->name('api.storefront.shipping.areas');
    Route::post('/shipping/rates',        [CheckoutController::class, 'rates'])->name('api.storefront.shipping.rates');
    Route::post('/cart/quote',            [CheckoutController::class, 'quote'])->name('api.storefront.cart.quote');
    Route::post('/checkout',              [CheckoutController::class, 'checkout'])->name('api.storefront.checkout');
    Route::get ('/orders/{token}',        [CheckoutController::class, 'show'])->name('api.storefront.orders.show');
    Route::post('/orders/{token}/claim',  [CheckoutController::class, 'claim'])->name('api.storefront.orders.claim');
    Route::post('/orders/lookup',         [CheckoutController::class, 'lookup'])->middleware('throttle:8,1')->name('api.storefront.orders.lookup');
    Route::post('/orders/pin-status',     [CheckoutController::class, 'pinStatus'])->middleware('throttle:30,1')->name('api.storefront.orders.pin-status');
});
