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
    Route::get('/homepage',          [StorefrontController::class, 'homepage'])->name('api.storefront.homepage');

    // Blog/Artikel SEO
    Route::get('/article-categories', [StorefrontController::class, 'articleCategories'])->name('api.storefront.article-categories');
    Route::get('/articles',           [StorefrontController::class, 'articles'])->name('api.storefront.articles');
    Route::get('/articles/{slug}',    [StorefrontController::class, 'article'])->name('api.storefront.article');

    // Tutorial pemasangan. `resolve` dipanggil storefront saat QR di-scan:
    // kode -> slug, sekaligus mencatat scan.
    Route::get('/tutorials',              [StorefrontController::class, 'tutorials'])->name('api.storefront.tutorials');
    Route::get('/tutorials/{slug}',       [StorefrontController::class, 'tutorial'])->name('api.storefront.tutorial');
    Route::post('/tutorials/{slug}/view', [StorefrontController::class, 'recordTutorialView'])->name('api.storefront.tutorial.view');
    Route::post('/tutorials/scan/{code}', [StorefrontController::class, 'recordTutorialScan'])->name('api.storefront.tutorial.scan');

    // Checkout: cek alamat/ongkir, buat pesanan, status & klaim transfer.
    Route::get ('/shipping/areas',        [CheckoutController::class, 'areas'])->name('api.storefront.shipping.areas');
    Route::post('/shipping/rates',        [CheckoutController::class, 'rates'])->name('api.storefront.shipping.rates');
    // Peta checkout (kurir instant): titik pembeli → alamat/area, dan titik toko sebagai pusat awal peta.
    // Dibatasi karena reverse geocode-nya menumpang layanan gratis (Nominatim).
    Route::post('/shipping/point',        [CheckoutController::class, 'resolvePoint'])->middleware('throttle:30,1')->name('api.storefront.shipping.point');
    Route::get ('/shipping/origin',       [CheckoutController::class, 'originPoint'])->name('api.storefront.shipping.origin');
    Route::post('/cart/quote',            [CheckoutController::class, 'quote'])->name('api.storefront.cart.quote');
    Route::post('/checkout',              [CheckoutController::class, 'checkout'])->name('api.storefront.checkout');
    Route::get ('/payment-methods',       [CheckoutController::class, 'paymentMethods'])->name('api.storefront.payment-methods');
    Route::get ('/orders/{token}',        [CheckoutController::class, 'show'])->name('api.storefront.orders.show');
    Route::post('/orders/{token}/qris',   [CheckoutController::class, 'refreshQris'])->middleware('throttle:10,1')->name('api.storefront.orders.qris');
    Route::post('/orders/{token}/claim',  [CheckoutController::class, 'claim'])->name('api.storefront.orders.claim');
    // Riwayat perjalanan paket. Dibatasi ketat: tiap panggilan yang lolos cache
    // meneruskan permintaan ke agregator kurir, jadi halaman pembeli tidak boleh
    // bisa memukulnya sesering ia menyegarkan status.
    Route::get ('/orders/{token}/tracking', [CheckoutController::class, 'tracking'])->middleware('throttle:20,1')->name('api.storefront.orders.tracking');
    Route::post('/orders/lookup',         [CheckoutController::class, 'lookup'])->middleware('throttle:8,1')->name('api.storefront.orders.lookup');
    Route::post('/orders/pin-status',     [CheckoutController::class, 'pinStatus'])->middleware('throttle:30,1')->name('api.storefront.orders.pin-status');
    // Alamat tersimpan (HP + PIN). Dibatasi ketat: jawabannya berisi alamat pembeli,
    // dan PIN cuma 4-6 digit — tanpa rem ini bisa ditebak beruntun.
    Route::post('/orders/profile',        [CheckoutController::class, 'profile'])->middleware('throttle:8,1')->name('api.storefront.orders.profile');
});
