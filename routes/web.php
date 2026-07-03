<?php

use Illuminate\Support\Facades\Route;
use App\Core\Period\PeriodService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Core\Journal\JournalPostingService;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\ProductBundleController;
use App\Http\Controllers\Sales\QuotationController;

use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockLedgerController;
use App\Http\Controllers\Inventory\PreorderController;
use App\Http\Controllers\Inventory\InventoryTransferController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Sales\SalesOrderController;
use App\Http\Controllers\Sales\SalesDeliveryController;
use App\Http\Controllers\CustomerController;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\ProductStock;
use App\Core\Journal\Journal;

// Auth routes (di luar middleware group)
Route::get ('/login',  [\App\Http\Controllers\Auth\AuthController::class, 'showLogin'])->name('login');
Route::post('/login',  [\App\Http\Controllers\Auth\AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [\App\Http\Controllers\Auth\AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/', function () {
    return redirect(auth()->check() ? user_landing_url() : '/login');
});

// ── Midtrans public pay & webhook (NO auth) ──────────────────────────
Route::get ('/pay/{token}',          [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'show'])->name('pay.show');
Route::post('/pay/{token}/charge',   [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'charge'])->name('pay.charge');
Route::get ('/pay/{token}/status',   [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'status'])->name('pay.status');
Route::get ('/pay/{token}/done',     [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'done'])->name('pay.done');
Route::get ('/pay/{token}/invoice.pdf', [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'invoicePdf'])->name('pay.invoice.pdf');
Route::get ('/pay/{token}/so.pdf',      [\App\Modules\Payment\Controllers\MidtransPublicController::class, 'soPdf'])->name('pay.so.pdf');
Route::post('/midtrans/notify',   [\App\Modules\Payment\Controllers\MidtransWebhookController::class, 'handle'])
    ->middleware('midtrans.signature')->name('midtrans.notify');

// ── Jubelio webhook (NO auth, server-to-server) — hybrid; cron tetap andalan ──
Route::prefix('jubelio/webhook')->middleware('jubelio.signature')->group(function () {
    Route::post('/salesorder',  [\App\Modules\Marketplace\Jubelio\Controllers\JubelioWebhookController::class, 'salesOrder'])->name('jubelio.webhook.salesorder');
    Route::post('/salesreturn', [\App\Modules\Marketplace\Jubelio\Controllers\JubelioWebhookController::class, 'salesReturn'])->name('jubelio.webhook.salesreturn');
    Route::post('/stock',       [\App\Modules\Marketplace\Jubelio\Controllers\JubelioWebhookController::class, 'stock'])->name('jubelio.webhook.stock');
});

// ── Telegram "Noud Bot" webhook (NO auth/CSRF, server-to-server) ──
// {secret} = telegram_settings.webhook_secret. Menangkap chat_id saat /start.
Route::post('/telegram/webhook/{secret}', [\App\Http\Controllers\TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');

Route::prefix('erp')->group(function () {
    Route::view('/health', 'erp.health');
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/dashboard/chart', [\App\Http\Controllers\DashboardController::class, 'chartData'])->name('dashboard.chart');
    Route::get('/dashboard/audit', [\App\Http\Controllers\DashboardController::class, 'audit'])->name('dashboard.audit');

    // Panduan penggunaan in-app (semua user yang login)
    Route::get('/panduan', [\App\Http\Controllers\PanduanController::class, 'index'])->name('panduan.index');

    // Marketplace Settings
    Route::prefix('settings')->group(function () {
        Route::get('/inventory', [\App\Http\Controllers\Inventory\InventorySettingController::class, 'edit'])
            ->name('settings.inventory');
        Route::post('/inventory', [\App\Http\Controllers\Inventory\InventorySettingController::class, 'update'])
            ->name('settings.inventory.update');

        Route::get('/marketplace', [\App\Http\Controllers\Settings\MarketplaceConfigController::class, 'index'])->name('settings.marketplace.index');
        Route::get('/marketplace/stores', [\App\Http\Controllers\Settings\MarketplaceConfigController::class, 'fetchStores'])->name('settings.marketplace.stores');
        Route::post('/marketplace', [\App\Http\Controllers\Settings\MarketplaceConfigController::class, 'store'])->name('settings.marketplace.store');
        Route::put('/marketplace/{id}', [\App\Http\Controllers\Settings\MarketplaceConfigController::class, 'update'])->name('settings.marketplace.update');
        Route::delete('/marketplace/{id}', [\App\Http\Controllers\Settings\MarketplaceConfigController::class, 'destroy'])->name('settings.marketplace.destroy');
        
        Route::resource('payment-fee', \App\Http\Controllers\Settings\PaymentFeeSettingController::class)->names('settings.payment-fee');

        Route::get('/freight', [\App\Http\Controllers\Settings\FreightSettingController::class, 'edit'])->name('settings.freight.edit');
        Route::post('/freight', [\App\Http\Controllers\Settings\FreightSettingController::class, 'update'])->name('settings.freight.update');
        Route::post('/freight/reconcile', [\App\Http\Controllers\Settings\FreightSettingController::class, 'reconcile'])->name('settings.freight.reconcile');

        Route::get('/business-profile', [\App\Http\Controllers\Settings\BusinessProfileController::class, 'edit'])->name('settings.business-profile.edit');
        Route::post('/business-profile', [\App\Http\Controllers\Settings\BusinessProfileController::class, 'update'])->name('settings.business-profile.update');

        // Integrasi — hub aplikasi yang terhubung dengan Noud ERP (Midtrans, Biteship, dst)
        Route::get('/integrations', [\App\Http\Controllers\Settings\IntegrationsController::class, 'index'])->name('settings.integrations.index');

        // Integrasi — Claude AI (Anthropic): asisten pencatat keuangan via Telegram
        Route::get ('/anthropic', [\App\Http\Controllers\Settings\AnthropicSettingController::class, 'edit'])->name('settings.anthropic.edit');
        Route::post('/anthropic', [\App\Http\Controllers\Settings\AnthropicSettingController::class, 'update'])->name('settings.anthropic.update');

        // Integrasi — Telegram "Noud Bot" (notifikasi izin, dll)
        Route::get ('/telegram',              [\App\Http\Controllers\Settings\TelegramSettingController::class, 'edit'])->name('settings.telegram.edit');
        Route::post('/telegram',              [\App\Http\Controllers\Settings\TelegramSettingController::class, 'update'])->name('settings.telegram.update');
        Route::post('/telegram/set-webhook',  [\App\Http\Controllers\Settings\TelegramSettingController::class, 'setWebhook'])->name('settings.telegram.set-webhook');
        Route::post('/telegram/delete-webhook',[\App\Http\Controllers\Settings\TelegramSettingController::class, 'deleteWebhook'])->name('settings.telegram.delete-webhook');
        Route::post('/telegram/link',         [\App\Http\Controllers\Settings\TelegramSettingController::class, 'link'])->name('settings.telegram.link');
        Route::post('/telegram/unlink',       [\App\Http\Controllers\Settings\TelegramSettingController::class, 'unlink'])->name('settings.telegram.unlink');

        Route::get('/midtrans', [\App\Http\Controllers\Settings\MidtransSettingController::class, 'edit'])->name('settings.midtrans.edit');
        Route::post('/midtrans', [\App\Http\Controllers\Settings\MidtransSettingController::class, 'update'])->name('settings.midtrans.update');

        // Integrasi — Cloudflare R2 (penyimpanan media etalase / Produk Store)
        Route::get ('/r2',      [\App\Http\Controllers\Settings\R2SettingController::class, 'edit'])->name('settings.r2.edit');
        Route::post('/r2',      [\App\Http\Controllers\Settings\R2SettingController::class, 'update'])->name('settings.r2.update');
        Route::post('/r2/test', [\App\Http\Controllers\Settings\R2SettingController::class, 'test'])->name('settings.r2.test');

        // Integrasi — Etalase Website (kunci API jembatan /api/storefront/*)
        Route::get ('/storefront',          [\App\Http\Controllers\Settings\StorefrontSettingController::class, 'edit'])->name('settings.storefront.edit');
        Route::post('/storefront',          [\App\Http\Controllers\Settings\StorefrontSettingController::class, 'update'])->name('settings.storefront.update');
        Route::post('/storefront/generate', [\App\Http\Controllers\Settings\StorefrontSettingController::class, 'generate'])->name('settings.storefront.generate');

        // Integrasi marketplace — Jubelio
        Route::get('/jubelio', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'edit'])->name('settings.jubelio.edit');
        Route::get('/jubelio/history', [\App\Http\Controllers\Settings\JubelioSyncLogController::class, 'index'])->name('settings.jubelio.history');
        Route::get('/jubelio/locations', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'fetchLocations'])->name('settings.jubelio.locations');
        Route::post('/jubelio', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'update'])->name('settings.jubelio.update');
        Route::post('/jubelio/test', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'testConnection'])->name('settings.jubelio.test');
        Route::post('/jubelio/reconcile', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'reconcileStock'])->name('settings.jubelio.reconcile');
        Route::post('/jubelio/channel-map', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'storeChannelMap'])->name('settings.jubelio.channel-map.store');
        Route::post('/jubelio/marketplace-config', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'saveMarketplaceConfig'])->name('settings.jubelio.marketplace-config.store');
        Route::delete('/jubelio/channel-map/{id}', [\App\Http\Controllers\Settings\JubelioSettingController::class, 'destroyChannelMap'])->name('settings.jubelio.channel-map.destroy');

        // Integrasi kurir — Biteship
        Route::get('/shipping/biteship', [\App\Http\Controllers\Settings\ShippingSettingController::class, 'biteship'])->name('settings.shipping.biteship');
        Route::post('/shipping/biteship', [\App\Http\Controllers\Settings\ShippingSettingController::class, 'updateBiteship'])->name('settings.shipping.biteship.update');
        Route::get('/shipping/kiriminaja', [\App\Http\Controllers\Settings\ShippingSettingController::class, 'kiriminaja'])->name('settings.shipping.kiriminaja');
        Route::post('/shipping/kiriminaja', [\App\Http\Controllers\Settings\ShippingSettingController::class, 'updateKiriminaja'])->name('settings.shipping.kiriminaja.update');

        // Jasa Kirim — kurir manual (ekspedisi non-API)
        Route::get('/shipping-couriers', [\App\Http\Controllers\Settings\ManualCourierController::class, 'index'])->name('settings.shipping-couriers.index');
        Route::post('/shipping-couriers', [\App\Http\Controllers\Settings\ManualCourierController::class, 'store'])->name('settings.shipping-couriers.store');
        Route::put('/shipping-couriers/{manualCourier}', [\App\Http\Controllers\Settings\ManualCourierController::class, 'update'])->name('settings.shipping-couriers.update');
        Route::post('/shipping-couriers/{manualCourier}/toggle', [\App\Http\Controllers\Settings\ManualCourierController::class, 'toggle'])->name('settings.shipping-couriers.toggle');
        Route::delete('/shipping-couriers/{manualCourier}', [\App\Http\Controllers\Settings\ManualCourierController::class, 'destroy'])->name('settings.shipping-couriers.destroy');

        // User & Akses (super_admin & admin only — di-guard di controller)
        Route::resource('users', \App\Http\Controllers\Settings\UserController::class)
            ->names('settings.users')
            ->parameters(['users' => 'user'])
            ->except(['create','show','edit']);
        Route::post('users/{user}/toggle-active', [\App\Http\Controllers\Settings\UserController::class, 'toggleActive'])
            ->name('settings.users.toggle-active');
    });
});

// SDM Module
Route::prefix('erp/sdm')->name('sdm.')->group(function () {
    Route::post('karyawan/{id}/archive', [\App\Modules\SDM\Controllers\KaryawanController::class, 'archive'])->name('karyawan.archive');
    Route::post('karyawan/{id}/restore', [\App\Modules\SDM\Controllers\KaryawanController::class, 'restore'])->name('karyawan.restore');
    Route::resource('karyawan', \App\Modules\SDM\Controllers\KaryawanController::class);

    // Absensi dashboard — per-karyawan per-bulan dengan kolom gaji/lembur/tunjangan.
    Route::get ('absensi',                [\App\Modules\SDM\Controllers\AttendanceController::class, 'dashboard'])     ->name('absensi.index');
    Route::post('absensi/update-status',  [\App\Modules\SDM\Controllers\AttendanceController::class, 'updateStatus'])  ->name('absensi.update-status');
    Route::post('absensi/upload-excel',   [\App\Modules\SDM\Controllers\AttendanceController::class, 'uploadExcel'])   ->name('absensi.upload-excel');
    Route::post('absensi/save-notes',     [\App\Modules\SDM\Controllers\AttendanceController::class, 'saveNotes'])    ->name('absensi.save-notes');

    Route::get('periode-gaji', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'index'])->name('periode-gaji.index');
    Route::get('periode-gaji/create', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'create'])->name('periode-gaji.create');
    Route::post('periode-gaji', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'store'])->name('periode-gaji.store');
    Route::match(['get', 'post'], 'periode-gaji/open-or-create', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'openOrCreate'])->name('periode-gaji.open-or-create');
    Route::get('periode-gaji/settings', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'settings'])->name('periode-gaji.settings');
    Route::post('periode-gaji/ensure-current', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'ensureCurrentMonth'])->name('periode-gaji.ensure-current');
    Route::get('periode-gaji/{id}', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'show'])->name('periode-gaji.show');
    Route::delete('periode-gaji/{id}', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'destroy'])->name('periode-gaji.destroy');
    Route::post('periode-gaji/{id}/upload-excel', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'uploadExcel'])->name('periode-gaji.upload');
    Route::post('periode-gaji/{id}/generate-slips', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'generateSlips'])->name('periode-gaji.generate-slips');
    Route::post('periode-gaji/{id}/finalize', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'finalize'])->name('periode-gaji.finalize');
    Route::post('periode-gaji/{id}/void', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'void'])->name('periode-gaji.void');
    Route::get('periode-gaji/{id}/print-all', [\App\Modules\SDM\Controllers\PeriodePenggajianController::class, 'printAll'])->name('periode-gaji.print-all');

    Route::get('slip-gaji/{id}', [\App\Modules\SDM\Controllers\SlipGajiController::class, 'show'])->name('slip-gaji.show');
    Route::get('slip-gaji/{id}/edit', [\App\Modules\SDM\Controllers\SlipGajiController::class, 'edit'])->name('slip-gaji.edit');
    Route::put('slip-gaji/{id}', [\App\Modules\SDM\Controllers\SlipGajiController::class, 'update'])->name('slip-gaji.update');
    Route::get('slip-gaji/{id}/print', [\App\Modules\SDM\Controllers\SlipGajiController::class, 'print'])->name('slip-gaji.print');
    Route::post('slip-gaji/{id}/regenerate', [\App\Modules\SDM\Controllers\SlipGajiController::class, 'regenerate'])->name('slip-gaji.regenerate');

    Route::get('attendance/{periodeId}', [\App\Modules\SDM\Controllers\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/{periodeId}/{id}/edit', [\App\Modules\SDM\Controllers\AttendanceController::class, 'edit'])->name('attendance.edit');
    Route::put('attendance/{id}', [\App\Modules\SDM\Controllers\AttendanceController::class, 'update'])->name('attendance.update');

    // Mesin Fingerprint
    Route::get   ('mesin',                [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'index'])  ->name('mesin.index');
    Route::get   ('mesin/create',         [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'create']) ->name('mesin.create');
    Route::post  ('mesin',                [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'store'])  ->name('mesin.store');
    Route::get   ('mesin/{id}',           [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'show'])   ->name('mesin.show');
    Route::get   ('mesin/{id}/edit',      [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'edit'])   ->name('mesin.edit');
    Route::put   ('mesin/{id}',           [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'update']) ->name('mesin.update');
    Route::delete('mesin/{id}',           [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'destroy'])->name('mesin.destroy');
    Route::post  ('mesin/{id}/test',      [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'testConnection'])->name('mesin.test');
    Route::post  ('mesin/{id}/sync',      [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'syncAttendance'])->name('mesin.sync');
    Route::post  ('mesin/{id}/archive',   [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'archive'])  ->name('mesin.archive');
    Route::post  ('mesin/{id}/unarchive', [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'unarchive'])->name('mesin.unarchive');
    Route::get   ('mesin/{id}/logs',      [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'logs'])   ->name('mesin.logs');
    Route::get   ('mesin/{id}/adms-activity', [\App\Modules\SDM\Controllers\FingerprintMachineController::class, 'admsActivity'])->name('mesin.adms-activity');

    // Izin / Jadwal Lembur (sub-tab Absensi)
    Route::get   ('izin',          [\App\Modules\SDM\Controllers\IzinController::class, 'index'])  ->name('izin.index');
    Route::get   ('izin/create',   [\App\Modules\SDM\Controllers\IzinController::class, 'create']) ->name('izin.create');
    Route::post  ('izin',          [\App\Modules\SDM\Controllers\IzinController::class, 'store'])  ->name('izin.store');
    Route::delete('izin/{id}',     [\App\Modules\SDM\Controllers\IzinController::class, 'destroy'])->name('izin.destroy');

    // Pengajuan Izin dari PWA karyawan (kotak masuk approval, sub-tab Absensi)
    Route::get ('pengajuan-izin',              [\App\Modules\SDM\Controllers\PengajuanIzinController::class, 'index'])  ->name('pengajuan-izin.index');
    Route::post('pengajuan-izin/{id}/approve', [\App\Modules\SDM\Controllers\PengajuanIzinController::class, 'approve'])->name('pengajuan-izin.approve');
    Route::post('pengajuan-izin/{id}/reject',  [\App\Modules\SDM\Controllers\PengajuanIzinController::class, 'reject']) ->name('pengajuan-izin.reject');
    Route::post('pengajuan-izin/{id}/cancel',  [\App\Modules\SDM\Controllers\PengajuanIzinController::class, 'cancel']) ->name('pengajuan-izin.cancel');

    // Surat Peringatan (sub-tab Absensi)
    Route::resource('sp', \App\Modules\SDM\Controllers\SpHistoryController::class)->except(['show']);

    // Kebijakan Penggajian (sub-tab Absensi) — landing redirect ke Rule
    Route::get ('kebijakan',          [\App\Modules\SDM\Controllers\KebijakanController::class, 'index'])->name('kebijakan.index');
    Route::get ('kebijakan/kolom',    [\App\Modules\SDM\Controllers\KebijakanController::class, 'kolomIndex'])->name('kebijakan.kolom.index');
    Route::post('kebijakan/kolom',    [\App\Modules\SDM\Controllers\KebijakanController::class, 'kolomStore'])->name('kebijakan.kolom.store');
    Route::put ('kebijakan/kolom/{id}',    [\App\Modules\SDM\Controllers\KebijakanController::class, 'kolomUpdate'])->name('kebijakan.kolom.update');
    Route::delete('kebijakan/kolom/{id}',  [\App\Modules\SDM\Controllers\KebijakanController::class, 'kolomDestroy'])->name('kebijakan.kolom.destroy');
    Route::post('kebijakan/summary',  [\App\Modules\SDM\Controllers\KebijakanController::class, 'summaryStore'])->name('kebijakan.summary.store');
    Route::put ('kebijakan/summary/{id}',   [\App\Modules\SDM\Controllers\KebijakanController::class, 'summaryUpdate'])->name('kebijakan.summary.update');
    Route::delete('kebijakan/summary/{id}', [\App\Modules\SDM\Controllers\KebijakanController::class, 'summaryDestroy'])->name('kebijakan.summary.destroy');
    Route::post('kebijakan/summary/{id}/value', [\App\Modules\SDM\Controllers\KebijakanController::class, 'summaryValueSave'])->name('kebijakan.summary.value.save');
    Route::get ('kebijakan/rule',     [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleIndex'])->name('kebijakan.rule.index');
    Route::get ('kebijakan/rule/create',     [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleCreate'])->name('kebijakan.rule.create');
    Route::post('kebijakan/rule',     [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleStore'])->name('kebijakan.rule.store');
    Route::get ('kebijakan/rule/{id}/edit',  [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleEdit'])->name('kebijakan.rule.edit');
    Route::put ('kebijakan/rule/{id}',       [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleUpdate'])->name('kebijakan.rule.update');
    Route::delete('kebijakan/rule/{id}',     [\App\Modules\SDM\Controllers\KebijakanController::class, 'ruleDestroy'])->name('kebijakan.rule.destroy');

    // Pengaturan Akun & Rate Payroll (sub-tab Kebijakan)
    Route::get('kebijakan/pengaturan', [\App\Modules\SDM\Controllers\PayrollSettingController::class, 'edit'])  ->name('kebijakan.pengaturan.edit');
    Route::put('kebijakan/pengaturan', [\App\Modules\SDM\Controllers\PayrollSettingController::class, 'update'])->name('kebijakan.pengaturan.update');

    // Hari Libur Nasional (sub-tab Absensi)
    Route::resource('libur', \App\Modules\SDM\Controllers\NationalHolidayController::class)->except(['show']);

    // Kasbon (sub-tab Absensi)
    Route::get   ('kasbon',                 [\App\Modules\SDM\Controllers\KasbonController::class, 'index'])  ->name('kasbon.index');
    Route::get   ('kasbon/create',          [\App\Modules\SDM\Controllers\KasbonController::class, 'create']) ->name('kasbon.create');
    Route::post  ('kasbon',                 [\App\Modules\SDM\Controllers\KasbonController::class, 'store'])  ->name('kasbon.store');
    Route::get   ('kasbon/{id}',            [\App\Modules\SDM\Controllers\KasbonController::class, 'show'])   ->name('kasbon.show');
    Route::get   ('kasbon/{id}/edit',       [\App\Modules\SDM\Controllers\KasbonController::class, 'edit'])   ->name('kasbon.edit');
    Route::put   ('kasbon/{id}',            [\App\Modules\SDM\Controllers\KasbonController::class, 'update']) ->name('kasbon.update');
    Route::delete('kasbon/{id}',            [\App\Modules\SDM\Controllers\KasbonController::class, 'destroy'])->name('kasbon.destroy');
    Route::post  ('kasbon/{id}/post',       [\App\Modules\SDM\Controllers\KasbonController::class, 'post'])   ->name('kasbon.post');
    Route::post  ('kasbon/{id}/void',       [\App\Modules\SDM\Controllers\KasbonController::class, 'void'])   ->name('kasbon.void');

    // Pembayaran Kasbon Manual (sub-tab Absensi)
    Route::get   ('kasbon-pembayaran',           [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'index'])  ->name('kasbon-pembayaran.index');
    Route::get   ('kasbon-pembayaran/create',    [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'create']) ->name('kasbon-pembayaran.create');
    Route::post  ('kasbon-pembayaran',           [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'store'])  ->name('kasbon-pembayaran.store');
    Route::get   ('kasbon-pembayaran/{id}',      [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'show'])   ->name('kasbon-pembayaran.show');
    Route::get   ('kasbon-pembayaran/{id}/edit', [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'edit'])   ->name('kasbon-pembayaran.edit');
    Route::put   ('kasbon-pembayaran/{id}',      [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'update']) ->name('kasbon-pembayaran.update');
    Route::delete('kasbon-pembayaran/{id}',      [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'destroy'])->name('kasbon-pembayaran.destroy');
    Route::post  ('kasbon-pembayaran/{id}/post', [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'post'])   ->name('kasbon-pembayaran.post');
    Route::post  ('kasbon-pembayaran/{id}/void', [\App\Modules\SDM\Controllers\KasbonPembayaranController::class, 'void'])   ->name('kasbon-pembayaran.void');

});

// ADMS endpoints untuk fingerprint machine (tanpa CSRF — di-exclude di bootstrap/app.php)
Route::prefix('iclock')->group(function () {
    Route::get   ('ping',       [\App\Modules\SDM\Controllers\AdmsController::class, 'ping']);
    Route::match (['get','post'], 'cdata', [\App\Modules\SDM\Controllers\AdmsController::class, 'cdata']);
    Route::get   ('getrequest', [\App\Modules\SDM\Controllers\AdmsController::class, 'getrequest']);
    Route::post  ('devicecmd',  [\App\Modules\SDM\Controllers\AdmsController::class, 'devicecmd']);
    // Catch-all: log path apapun yang mesin coba akses, return 404
    Route::any   ('{any}',      [\App\Modules\SDM\Controllers\AdmsController::class, 'catchAll'])->where('any', '.*');
});

// API helper SDM
Route::get('/erp/api/karyawan/by-department/{deptId}', [\App\Modules\SDM\Controllers\KaryawanController::class, 'searchByDepartment'])->name('api.karyawan.by-department');
Route::get('/erp/api/karyawan/except-department/{deptId}', [\App\Modules\SDM\Controllers\KaryawanController::class, 'searchExceptDepartment'])->name('api.karyawan.except-department');

Route::prefix('erp/master')->group(function () {
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{id}/archive', [CustomerController::class, 'archive'])->name('customers.archive');
    Route::post('customers/{id}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
});

Route::get('/erp/api/customers/search', [CustomerController::class, 'search']);
Route::get('/erp/api/products/search', [ProductController::class, 'search']);
Route::get('/erp/api/products/{id}/stock', [ProductController::class, 'stock']);
Route::get('/erp/api/products/{id}/units', [ProductController::class, 'units']);
Route::get('/erp/api/products/{id}/prices', [ProductController::class, 'prices']);
Route::get('/erp/api/products/{id}/components', [ProductController::class, 'components']);
Route::get('/erp/api/bundle/{id}/components', [ProductBundleController::class, 'components']);

Route::get('/erp/customers/address/{id}', function ($id) {
    $customer = \App\Models\Customer::find($id);

    return response()->json([
        'shipping_address' => $customer->shipping_address
    ]);
});

Route::post('/erp/customers/store-ajax', [CustomerController::class, 'storeAjax']);
Route::post('/customers/store', [CustomerController::class, 'storeAjax']);

// Embed cek ongkir (SO/Invoice): alamat customer + rates + area search (tanpa nama → lolos menu-gate utk semua authed)
Route::get('/erp/api/customers/{id}/shipping', [CustomerController::class, 'shippingInfo']);
Route::post('/erp/api/customers/{id}/shipping', [CustomerController::class, 'updateShipping']);
Route::match(['get', 'post'], '/erp/api/shipping/rates', \App\Http\Controllers\Shipping\RatesController::class);
Route::get('/erp/api/shipping/areas', \App\Http\Controllers\Shipping\AreaSearchController::class);
Route::get('/erp/api/shipping/reverse-geocode', \App\Http\Controllers\Shipping\ReverseGeocodeController::class);
Route::post('/erp/api/shipping/weight', \App\Http\Controllers\Shipping\WeightController::class);

Route::get('/erp/api/quotation/{id}', function ($id) {
    $q = \App\Models\SalesQuotation::with('items.product', 'customer')
        ->findOrFail($id);
    return response()->json($q);
});

Route::get('/erp/api/quotations/{id}', [QuotationController::class, 'showApi']);
Route::get('/erp/api/sales-order/{id}', [SalesOrderController::class, 'info']);
Route::get('/erp/api/sales-orders/{id}', [SalesOrderController::class, 'showApi']);
Route::get('/erp/api/product-stock/{product}', [\App\Http\Controllers\Inventory\ProductController::class, 'stock']);

Route::get('/erp/api/product-stock/{productId}/{warehouseId}', function ($productId, $warehouseId) {
    $stock = \App\Core\Inventory\ProductStock::where([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId
    ])->first();

    $reserved = \App\Core\Inventory\StockReservation::where([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId
    ])->sum('qty_reserved');

    return response()->json([
        'qty_on_hand' => (float) ($stock->qty_on_hand ?? 0),
        'qty_reserved' => (float) $reserved,
        'qty_available' => (float) (($stock->qty_on_hand ?? 0) - $reserved),
        'available_stock' => (float) (($stock->qty_on_hand ?? 0) - $reserved)
    ]);
});

Route::prefix('erp/accounting')->group(function () {
    Route::prefix('accounts')->group(function () {
        Route::get('/', [\App\Http\Controllers\Accounting\AccountController::class, 'index'])->name('accounts.index');
        Route::get('/create', [\App\Http\Controllers\Accounting\AccountController::class, 'create'])->name('accounts.create');
        Route::post('/', [\App\Http\Controllers\Accounting\AccountController::class, 'store'])->name('accounts.store');
        Route::get('/{account}/edit', [\App\Http\Controllers\Accounting\AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/{account}', [\App\Http\Controllers\Accounting\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/{account}', [\App\Http\Controllers\Accounting\AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::get('/next-code', [\App\Http\Controllers\Accounting\AccountController::class, 'getNextCode'])->name('accounts.next-code');
        Route::get('/search', [\App\Http\Controllers\Accounting\AccountController::class, 'search'])->name('accounts.search');
        Route::post('/{account}/archive', [\App\Http\Controllers\Accounting\AccountController::class, 'archive'])->name('accounts.archive');
        Route::post('/{account}/restore', [\App\Http\Controllers\Accounting\AccountController::class, 'restore'])->name('accounts.restore');
        Route::get('/opening-balance', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'index'])->name('accounts.opening-balance.index');
        Route::get('/opening-balance/create', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'create'])->name('accounts.opening-balance.create');
        Route::post('/opening-balance', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'store'])->name('accounts.opening-balance.store');
        Route::get('/opening-balance/products/template', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'productsImportTemplate'])->name('accounts.opening-balance.products.template');
        Route::post('/opening-balance/products/import', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'productsImport'])->name('accounts.opening-balance.products.import');
        Route::get('/opening-balance/{journal}/edit', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'edit'])->name('accounts.opening-balance.edit');
        Route::put('/opening-balance/{journal}', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'update'])->name('accounts.opening-balance.update');
        Route::delete('/opening-balance/{journal}', [\App\Http\Controllers\Accounting\OpeningBalanceController::class, 'destroy'])->name('accounts.opening-balance.destroy');
        Route::get('/{account}', [\App\Http\Controllers\Accounting\AccountController::class, 'show'])->name('accounts.show');
    });

    Route::get('/period/create/{year}/{month}', function ($year, $month) {
        $service = new PeriodService();
        $service->createPeriod($year, $month);
        return "Period created.";
    });

    Route::prefix('period')->name('accounting.period.')->group(function () {
        Route::get('/list', [\App\Http\Controllers\Accounting\AccountingPeriodController::class, 'index'])->name('index');
        Route::post('/ensure-current', [\App\Http\Controllers\Accounting\AccountingPeriodController::class, 'ensureCurrent'])->name('ensure-current');
        Route::get('/{year}/{month}/preview', [\App\Http\Controllers\Accounting\AccountingPeriodController::class, 'preview'])->whereNumber('year')->whereNumber('month')->name('preview');
        Route::post('/{year}/{month}/close', [\App\Http\Controllers\Accounting\AccountingPeriodController::class, 'close'])->whereNumber('year')->whereNumber('month')->name('close');
        Route::post('/{year}/{month}/reopen', [\App\Http\Controllers\Accounting\AccountingPeriodController::class, 'reopen'])->whereNumber('year')->whereNumber('month')->name('reopen');
    });

    Route::prefix('reports')->name('accounting.reports.')->group(function () {
        Route::get('/balance-sheet', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/income-statement', [\App\Http\Controllers\Accounting\FinancialReportController::class, 'incomeStatement'])->name('income-statement');
    });
});

/*
|--------------------------------------------------------------------------
| STORE — etalase web (Produk Store + Kategori). Konten untuk noudakrilik.com.
|--------------------------------------------------------------------------
*/
Route::prefix('erp/store')->group(function () {
    // Kategori
    Route::get('/categories',            [\App\Http\Controllers\Store\StoreCategoryController::class, 'index'])->name('store.categories.index');
    Route::get('/categories/create',     [\App\Http\Controllers\Store\StoreCategoryController::class, 'create'])->name('store.categories.create');
    Route::post('/categories',           [\App\Http\Controllers\Store\StoreCategoryController::class, 'store'])->name('store.categories.store');
    Route::get('/categories/{id}/edit',  [\App\Http\Controllers\Store\StoreCategoryController::class, 'edit'])->name('store.categories.edit');
    Route::put('/categories/{id}',       [\App\Http\Controllers\Store\StoreCategoryController::class, 'update'])->name('store.categories.update');
    Route::delete('/categories/{id}',    [\App\Http\Controllers\Store\StoreCategoryController::class, 'destroy'])->name('store.categories.destroy');

    // Produk Store
    Route::get('/products',              [\App\Http\Controllers\Store\StoreProductController::class, 'index'])->name('store.products.index');
    Route::get('/products/create',       [\App\Http\Controllers\Store\StoreProductController::class, 'create'])->name('store.products.create');
    Route::post('/products',             [\App\Http\Controllers\Store\StoreProductController::class, 'store'])->name('store.products.store');
    Route::get('/products/{id}/edit',    [\App\Http\Controllers\Store\StoreProductController::class, 'edit'])->name('store.products.edit');
    Route::put('/products/{id}',         [\App\Http\Controllers\Store\StoreProductController::class, 'update'])->name('store.products.update');
    Route::delete('/products/{id}',      [\App\Http\Controllers\Store\StoreProductController::class, 'destroy'])->name('store.products.destroy');

    // Galeri media Produk Store (foto/video) — endpoint JSON, dikelola di halaman edit.
    Route::post('/products/{id}/media',                   [\App\Http\Controllers\Store\StoreProductMediaController::class, 'storeImages'])->name('store.products.media.store');
    Route::post('/products/{id}/media/video',             [\App\Http\Controllers\Store\StoreProductMediaController::class, 'storeVideo'])->name('store.products.media.video');
    Route::post('/products/{id}/media/youtube',           [\App\Http\Controllers\Store\StoreProductMediaController::class, 'storeYoutube'])->name('store.products.media.youtube');
    Route::put('/products/{id}/media/reorder',            [\App\Http\Controllers\Store\StoreProductMediaController::class, 'reorder'])->name('store.products.media.reorder');
    Route::put('/products/{id}/media/{mediaId}/primary',  [\App\Http\Controllers\Store\StoreProductMediaController::class, 'setPrimary'])->name('store.products.media.primary');
    Route::put('/products/{id}/media/{mediaId}/alt',      [\App\Http\Controllers\Store\StoreProductMediaController::class, 'updateAlt'])->name('store.products.media.alt');
    Route::delete('/products/{id}/media/{mediaId}',       [\App\Http\Controllers\Store\StoreProductMediaController::class, 'destroy'])->name('store.products.media.destroy');
});

Route::prefix('erp/inventory')->group(function () {

    Route::get('/warehouses/areas', \App\Http\Controllers\Shipping\AreaSearchController::class)->name('inventory.warehouses.areas');
    Route::get('/warehouses', [WarehouseController::class, 'index'])->name('inventory.warehouses.index');
    Route::get('/warehouses/create', [WarehouseController::class, 'create'])->name('inventory.warehouses.create');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->name('inventory.warehouses.store');
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show'])->whereNumber('id')->name('inventory.warehouses.show');
    Route::get('/warehouses/{id}/edit', [WarehouseController::class, 'edit'])->name('inventory.warehouses.edit');
    Route::put('/warehouses/{id}', [WarehouseController::class, 'update'])->name('inventory.warehouses.update');
    Route::delete('/warehouses/{id}', [WarehouseController::class, 'destroy'])->name('inventory.warehouses.destroy');
    Route::post('/warehouses/{id}/archive', [WarehouseController::class, 'archive'])->name('inventory.warehouses.archive');
    Route::post('/warehouses/{id}/restore', [WarehouseController::class, 'restore'])->name('inventory.warehouses.restore');
    Route::get('/warehouses/{id}/ledger', [WarehouseController::class, 'ledger'])->name('inventory.warehouses.ledger');
    Route::get('/warehouse-stock', [WarehouseController::class, 'warehouseStock'])->name('inventory.warehouses.stock');

    Route::get('/products', [ProductController::class, 'index'])->name('inventory.products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('inventory.products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('inventory.products.store');
    Route::get('/products/bulk-import', [ProductController::class, 'bulkImport'])->name('inventory.products.bulk-import');
    Route::get('/products/bulk-import-template', [ProductController::class, 'bulkImportTemplate'])->name('inventory.products.bulk-import-template');
    Route::post('/products/bulk-import', [ProductController::class, 'bulkImportStore'])->name('inventory.products.bulk-import-store');
    Route::get('/products/export', [ProductController::class, 'export'])->name('inventory.products.export');
    Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('inventory.products.edit');
    Route::post('/products/update-price', [ProductController::class, 'updatePrice'])->name('products.updatePrice');
    Route::post('/products/update-sellable', [ProductController::class, 'updateSellable'])->name('products.updateSellable');
    Route::post('/products/update-jubelio', [ProductController::class, 'updateSyncJubelio'])->name('products.updateSyncJubelio');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('inventory.products.destroy');
    Route::post('/products/{id}/archive', [ProductController::class, 'archive'])->name('inventory.products.archive');
    Route::get('/products/{product}/ledger', [StockLedgerController::class, 'index'])->name('inventory.products.ledger');
    Route::post('/products/{product}/restore', [ProductController::class, 'restore'])->name('inventory.products.restore');
    Route::post('/products/{product}/update-info', [ProductController::class, 'updateInfo'])->name('inventory.products.update-info');
    Route::get('/products/{product}/setup', [ProductController::class, 'setup'])->name('inventory.products.setup');

    // Type-specific setup routes
    Route::get('/products/{product}/setup-ready', [ProductController::class, 'setupReady'])->name('inventory.products.setup.ready');
    Route::get('/products/{product}/setup-preorder', [ProductController::class, 'setupPreorder'])->name('inventory.products.setup.preorder');
    Route::get('/products/{product}/setup-bundle', [ProductController::class, 'setupBundle'])->name('inventory.products.setup.bundle');
    Route::get('/products/{product}/setup-service', [ProductController::class, 'setupService'])->name('inventory.products.setup.service');
    Route::get('/products/{product}/setup-non-stock', [ProductController::class, 'setupNonStock'])->name('inventory.products.setup.non-stock');

    Route::post('/products/{product}/units', [ProductController::class, 'storeUnits'])->name('inventory.products.units.store');
    Route::post('/products/{product}/price', [ProductController::class, 'storePrice'])->name('inventory.products.price.store');
    Route::post('/products/{product}/cost', [ProductController::class, 'saveCost'])->name('inventory.products.cost.store');
    Route::post('/products/{product}/preorder-config', [ProductController::class, 'savePreorderConfig'])->name('inventory.products.preorder-config');
    Route::post('/products/{product}/finish', [ProductController::class, 'finishSetup'])->name('inventory.products.finish');

    Route::get(
        '/products/{id}/components',
        [ProductBundleController::class, 'index']
    );
    Route::post(
        '/products/{id}/components',
        [ProductBundleController::class, 'store']
    );
    Route::post('/products/{product}/bundle', [ProductController::class, 'saveBundle'])->name('inventory.products.bundle.save');
    Route::post('/products/{product}/service-config', [ProductController::class, 'saveServiceConfig'])->name('inventory.products.service-config');
    Route::post('/products/{product}/non-stock-config', [ProductController::class, 'saveNonStockConfig'])->name('inventory.products.non-stock-config');

    Route::get(
        '/products/{id}/preorder',
        [PreorderController::class, 'edit']
    );
    Route::post(
        '/products/{id}/preorder',
        [PreorderController::class, 'update']
    );
    Route::get(
        '/products/{product}/units',
        [ProductController::class, 'units']
    );



    Route::get('/stocks', [StockController::class, 'index'])->name('inventory.stocks.index');
    Route::get('/stocks/{product}/orders',    [StockController::class, 'orders'])->name('inventory.stocks.api.orders');
    Route::get('/stocks/{product}/shipments', [StockController::class, 'shipments'])->name('inventory.stocks.api.shipments');
    Route::patch('/products/{id}/min-stock', [\App\Http\Controllers\Inventory\ProductMinStockController::class, 'update'])->name('inventory.products.minstock');
    Route::get('/product-stock-adjustment', [StockController::class, 'getStock'])->name('inventory.product-stock');
    // Dipakai HANYA oleh halaman Transfer Stok (create/edit) utk cek stok gudang asal.
    // Namanya harus di bawah `inventory.transfers.*` supaya EnsureMenuAccess mengizinkan
    // pemegang menu Transfer Stok (mis. divisi Packing) — sebelumnya bernama 'product-stock'
    // yang terpetakan ke menu 'inventory.warehouses' sehingga kena 403 & stok kebaca 0.
    Route::get('/product-stock/{product}/{warehouse}', [InventoryTransferController::class, 'productStock'])->name('inventory.transfers.product-stock');

    Route::prefix('transfers')->name('inventory.transfers.')->group(function () {
        Route::get('/', [InventoryTransferController::class, 'index'])->name('index');
        Route::get('/create', [InventoryTransferController::class, 'create'])->name('create');
        Route::post('/', [InventoryTransferController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [InventoryTransferController::class, 'edit'])->name('edit');
        Route::get('/{id}', [InventoryTransferController::class, 'show'])->name('show');
        Route::put('/{id}', [InventoryTransferController::class, 'update'])->name('update');
        Route::delete('/{id}', [InventoryTransferController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/post', [InventoryTransferController::class, 'post'])->name('post');
        Route::post('/{id}/void', [InventoryTransferController::class, 'void'])->name('void');
    });

    Route::get('/adjustments', [InventoryAdjustmentController::class, 'index'])
        ->name('inventory.adjustments.index');
    Route::get('/adjustments/create', [InventoryAdjustmentController::class, 'create'])
        ->name('inventory.adjustments.create');
    Route::post('/adjustments', [InventoryAdjustmentController::class, 'store'])
        ->name('inventory.adjustments.store');
    Route::post('/adjustments/{id}/post', [InventoryAdjustmentController::class, 'post'])
        ->name('inventory.adjustments.post');
    Route::post('/adjustments/{id}/void', [InventoryAdjustmentController::class, 'void'])
        ->name('inventory.adjustments.void');
    Route::get('/adjustments/{id}/edit', [InventoryAdjustmentController::class, 'edit'])
        ->name('inventory.adjustments.edit');
    Route::put('/adjustments/{id}', [InventoryAdjustmentController::class, 'update'])
        ->name('inventory.adjustments.update');
    Route::delete('/adjustments/{id}', [InventoryAdjustmentController::class, 'destroy'])
        ->name('inventory.adjustments.destroy');

    Route::get('/reports/valuation', [\App\Http\Controllers\Inventory\InventoryReportController::class, 'valuation'])
        ->name('inventory.reports.valuation');
    Route::get('/reports/stock-card/{productId}', [\App\Http\Controllers\Inventory\InventoryReportController::class, 'stockCard'])
        ->name('inventory.reports.stock-card');

    Route::post('/products/{id}/opening-balance', [\App\Http\Controllers\Inventory\ProductController::class, 'postOpeningBalance'])
        ->name('products.opening.post');
});

// use App\Http\Controllers\Sales\QuotationController; // Moved to top
use App\Http\Controllers\Integration\MarketplaceController;

Route::prefix('erp/integrations')->group(function () {
    Route::get('/marketplace', [MarketplaceController::class, 'index'])
        ->name('integrations.marketplace.index');

    Route::get('/marketplace/create', [MarketplaceController::class, 'create'])
        ->name('integrations.marketplace.create');

    Route::post('/marketplace', [MarketplaceController::class, 'store'])
        ->name('integrations.marketplace.store');

    Route::get('/marketplace/{id}/edit', [MarketplaceController::class, 'edit'])
        ->name('integrations.marketplace.edit');

    Route::post('/marketplace/{id}', [MarketplaceController::class, 'update'])
        ->name('integrations.marketplace.update');
});

Route::prefix('erp/sales')->name('sales.')->group(function () {

    // Cek Ongkir (standalone)
    Route::get('/cek-ongkir', [\App\Http\Controllers\Sales\CekOngkirController::class, 'index'])->name('cek-ongkir');
    Route::get('/cek-ongkir/areas', \App\Http\Controllers\Shipping\AreaSearchController::class)->name('cek-ongkir.areas');
    Route::get('/cek-ongkir/resolve', \App\Http\Controllers\Shipping\ReverseGeocodeController::class)->name('cek-ongkir.resolve');
    Route::post('/cek-ongkir', [\App\Http\Controllers\Sales\CekOngkirController::class, 'check'])->name('cek-ongkir.check');

    // Promosi (CRUD + resolve AJAX untuk Kasir/form). resolve harus sebelum resource/{id}.
    Route::get('/promosi/resolve', [\App\Http\Controllers\Sales\PromosiController::class, 'resolveApi'])->name('promosi.resolve');
    Route::resource('promosi', \App\Http\Controllers\Sales\PromosiController::class)->parameters(['promosi' => 'promosi'])->except(['show']);

    // Cetak gabungan (harus sebelum resource/{id} agar tidak tertangkap wildcard)
    Route::get('/orders/print-bulk', [SalesOrderController::class, 'printBulk'])->name('orders.print-bulk');
    Route::get('/deliveries/print-bulk', [SalesDeliveryController::class, 'printBulk'])->name('deliveries.print-bulk');
    Route::get('/deliveries/print-resi-bulk', [SalesDeliveryController::class, 'printResiBulk'])->name('deliveries.print-resi-bulk');
    Route::get('/deliveries/print-label-bulk', [SalesDeliveryController::class, 'printLabelBulk'])->name('deliveries.print-label-bulk');

    Route::resource('orders', SalesOrderController::class);
    Route::get('/orders/from-quotation/{quotation}', [SalesOrderController::class, 'createFromQuotation'])->name('orders.createFromQuotation');
    Route::post('/orders/{id}/confirm', [SalesOrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{id}/post', [SalesOrderController::class, 'confirm'])->name('orders.post');
    Route::post('/orders/{id}/pickup', [SalesOrderController::class, 'pickup'])->name('orders.pickup');
    Route::post('/orders/{id}/pickup-date', [SalesOrderController::class, 'updatePickupDate'])->name('orders.pickup-date');
    Route::post('/orders/{id}/update-shipping', [SalesOrderController::class, 'updateShipping'])->name('orders.update-shipping');
    Route::post('/orders/{id}/void', [SalesOrderController::class, 'void'])->name('orders.void');
    Route::get('/orders/{id}/print', [SalesOrderController::class, 'print'])->name('orders.print');
    Route::get('/orders/{id}/label', [SalesOrderController::class, 'printLabel'])->name('orders.label');
    Route::get('/orders/{id}/pdf', [SalesOrderController::class, 'downloadPdf'])->name('orders.pdf');
    Route::post('/orders/{id}/cancel', [SalesOrderController::class, 'destroy'])->name('orders.cancel');

    Route::resource('quotations', QuotationController::class);
    Route::post('/quotations/{id}/convert', [QuotationController::class, 'convertToSO'])->name('quotations.convert');
    Route::post('/quotations/{id}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
    Route::get('/quotations/{id}/print', [QuotationController::class, 'print'])->name('quotations.print');
    Route::get('/quotations/{id}/pdf', [QuotationController::class, 'downloadPdf'])->name('quotations.pdf');
    Route::get('/api/quotation/{id}', [QuotationController::class, 'apiShow'])->name('quotations.apiShow');

    Route::get('deliveries', [SalesDeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('deliveries/create', [SalesDeliveryController::class, 'create'])->name('deliveries.create');
    Route::get('deliveries/create/{soId}', [SalesDeliveryController::class, 'create'])->name('deliveries.createFromSO');
    Route::post('deliveries', [SalesDeliveryController::class, 'store'])->name('deliveries.store');
    Route::get('deliveries/{delivery}', [SalesDeliveryController::class, 'show'])->name('deliveries.show');
    Route::get('deliveries/{delivery}/edit', [SalesDeliveryController::class, 'edit'])->name('deliveries.edit');
    Route::put('deliveries/{delivery}', [SalesDeliveryController::class, 'update'])->name('deliveries.update');
    Route::delete('deliveries/{delivery}', [SalesDeliveryController::class, 'destroy'])->name('deliveries.destroy');
    Route::post('deliveries/{delivery}/post', [SalesDeliveryController::class, 'post'])->name('deliveries.post');
    Route::get('deliveries/{id}/ship-info', [SalesDeliveryController::class, 'shipInfo'])->name('deliveries.ship-info');
    Route::post('deliveries/{id}/book', [SalesDeliveryController::class, 'bookShipment'])->name('deliveries.book');
    Route::get('deliveries/{id}/resi', [SalesDeliveryController::class, 'printResi'])->name('deliveries.resi');
    Route::get('deliveries/{id}/label', [SalesDeliveryController::class, 'printLabel'])->name('deliveries.label');
    Route::get('deliveries/{id}/track', [SalesDeliveryController::class, 'trackShipment'])->name('deliveries.track');
    Route::post('deliveries/{delivery}/void', [SalesDeliveryController::class, 'void'])->name('deliveries.void');
    Route::get('deliveries/{delivery}/print', [SalesDeliveryController::class, 'print'])->name('deliveries.print');
    Route::get('deliveries/{delivery}/pdf', [SalesDeliveryController::class, 'downloadPdf'])->name('deliveries.pdf');

    Route::get(
        '/invoices',
        [\App\Http\Controllers\DebugInvoiceController::class, 'index']
    )->name('invoices.index');

    Route::get(
        '/invoices/excel-import',
        [\App\Http\Controllers\DebugInvoiceController::class, 'excelImportForm']
    )->name('invoices.excel-import.form');

    Route::post(
        '/invoices/excel-import',
        [\App\Http\Controllers\DebugInvoiceController::class, 'excelImport']
    )->name('invoices.excel-import.store');

    Route::get(
        '/invoices/print-bulk',
        [\App\Http\Controllers\DebugInvoiceController::class, 'printBulk']
    )->name('invoices.print-bulk');

    Route::get(
        '/invoices/{id}',
        [\App\Http\Controllers\DebugInvoiceController::class, 'show']
    )->whereNumber('id')->name('invoices.show');

    Route::get(
        '/invoices/create',
        [\App\Http\Controllers\DebugInvoiceController::class, 'create']
    )->name('invoices.create');

    Route::get(
        '/invoices/create/{soId}',
        [\App\Http\Controllers\DebugInvoiceController::class, 'createFromSO']
    )->name('invoices.create.from.so');

    Route::post(
        '/invoices/store',
        [\App\Http\Controllers\DebugInvoiceController::class, 'store']
    )->name('invoices.store');

    Route::get(
        '/invoices/{id}/print',
        [\App\Http\Controllers\DebugInvoiceController::class, 'print']
    )->name('invoices.print');

    Route::get(
        '/invoices/{id}/pdf',
        [\App\Http\Controllers\DebugInvoiceController::class, 'downloadPdf']
    )->name('invoices.pdf');

    Route::post(
        '/invoices/{id}/post',
        [\App\Http\Controllers\DebugInvoiceController::class, 'post']
    )->name('invoices.post');

    Route::post(
        '/invoices/{id}/cancel',
        [\App\Http\Controllers\DebugInvoiceController::class, 'cancel']
    )->name('invoices.cancel');

    Route::post(
        '/invoices/{id}/void',
        [\App\Http\Controllers\DebugInvoiceController::class, 'void']
    )->name('invoices.void');

    Route::delete(
        '/invoices/{id}',
        [\App\Http\Controllers\DebugInvoiceController::class, 'destroy']
    )->name('invoices.destroy');

    Route::get(
        '/invoices/{id}/edit',
        [\App\Http\Controllers\DebugInvoiceController::class, 'edit']
    )->name('invoices.edit');

    Route::put(
        '/invoices/{id}',
        [\App\Http\Controllers\DebugInvoiceController::class, 'update']
    )->whereNumber('id')->name('invoices.update');

    // BILLING
    Route::get('/billing', [\App\Modules\Sales\Controllers\BillingController::class, 'index'])->name('billing.index');
    Route::get('/billing/create', [\App\Modules\Sales\Controllers\BillingController::class, 'create'])->name('billing.create');
    Route::get('/billing/{id}', [\App\Modules\Sales\Controllers\BillingController::class, 'show'])->name('billing.show');
    Route::get('/billing/{id}/edit', [\App\Modules\Sales\Controllers\BillingController::class, 'edit'])->name('billing.edit');
    Route::put('/billing/{id}', [\App\Modules\Sales\Controllers\BillingController::class, 'update'])->name('billing.update');
    Route::post('/billing/store', [\App\Modules\Sales\Controllers\BillingController::class, 'store'])->name('billing.store');
    Route::delete('/billing/{id}', [\App\Modules\Sales\Controllers\BillingController::class, 'destroy'])->name('billing.destroy');
    Route::post('/billing/{id}/void', [\App\Modules\Sales\Controllers\BillingController::class, 'void'])->name('billing.void');
    Route::get('/ajax/billing-open-documents/{customerId}', [\App\Modules\Sales\Controllers\BillingController::class, 'getOpenDocuments'])->name('ajax.billing_open_documents');

    // PAYMENT
    Route::get('/payment', [\App\Modules\Sales\Controllers\PaymentController::class, 'index'])->name('payment.index');
    Route::get('/payment/create', [\App\Modules\Sales\Controllers\PaymentController::class, 'create'])->name('payment.create');
    Route::post('/payment/store', [\App\Modules\Sales\Controllers\PaymentController::class, 'store'])->name('payment.store');
    Route::get('/payment/{id}', [\App\Modules\Sales\Controllers\PaymentController::class, 'show'])->whereNumber('id')->name('payment.show');
    Route::delete('/payment/{id}', [\App\Modules\Sales\Controllers\PaymentController::class, 'destroy'])->whereNumber('id')->name('payment.destroy');
    Route::post('/payment/{id}/post', [\App\Modules\Sales\Controllers\PaymentController::class, 'post'])->whereNumber('id')->name('payment.post');
    Route::post('/payment/{id}/void', [\App\Modules\Sales\Controllers\PaymentController::class, 'void'])->whereNumber('id')->name('payment.void');
    Route::get('/payment/{id}/print', [\App\Modules\Sales\Controllers\PaymentController::class, 'print'])->whereNumber('id')->name('payment.print');
    Route::get('/payment/{id}/pdf', [\App\Modules\Sales\Controllers\PaymentController::class, 'downloadPdf'])->whereNumber('id')->name('payment.pdf');
    Route::get('/ajax/customer-summary/{id}', [\App\Modules\Sales\Controllers\PaymentController::class, 'getCustomerSummary'])->name('ajax.customer_summary');
    Route::get('/ajax/customer-open-items/{id}', [\App\Modules\Sales\Controllers\PaymentController::class, 'getOpenItems'])->name('ajax.customer_open_items');
    Route::get('/ajax/search-customers', [\App\Modules\Sales\Controllers\PaymentController::class, 'searchCustomers'])->name('ajax.search_customers');
    Route::get('/ajax/calculate-fee', [\App\Modules\Sales\Controllers\PaymentController::class, 'calculateFee'])->name('ajax.calculate_fee');
    Route::get('/ajax/check-fee', [\App\Modules\Sales\Controllers\PaymentController::class, 'checkFee'])->name('ajax.check_fee');

    // MIDTRANS — admin actions (di dalam prefix erp/sales, masih ter-auth)
    Route::get('/payment/invoice/{invoice}/midtrans-link', [\App\Modules\Payment\Controllers\MidtransAdminController::class, 'generateLink'])
        ->whereNumber('invoice')->name('midtrans.admin.link');
    Route::get('/payment/sales-order/{so}/midtrans-link', [\App\Modules\Payment\Controllers\MidtransAdminController::class, 'generateSoLink'])
        ->whereNumber('so')->name('midtrans.admin.so-link');
    Route::get('/payment/invoice/{invoice}/midtrans-qris', [\App\Modules\Payment\Controllers\MidtransAdminController::class, 'showQris'])
        ->whereNumber('invoice')->name('midtrans.admin.qris');
    Route::get('/payment/sales-order/{so}/midtrans-qris', [\App\Modules\Payment\Controllers\MidtransAdminController::class, 'showSoQris'])
        ->whereNumber('so')->name('midtrans.admin.so-qris');
    Route::get('/payment/midtrans/{trx}/status', [\App\Modules\Payment\Controllers\MidtransAdminController::class, 'pollStatus'])
        ->whereNumber('trx')->name('midtrans.admin.status');

    // RETUR UANG
    Route::get('/returns', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/create', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'create'])->name('returns.create');
    Route::post('/returns', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'store'])->name('returns.store');
    Route::get('/returns/{id}', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'show'])->name('returns.show');
    Route::get('/returns/{id}/edit', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'edit'])->name('returns.edit');
    Route::delete('/returns/{id}', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'destroy'])->name('returns.destroy');
    Route::post('/returns/{id}/post', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'post'])->name('returns.post');
    Route::post('/returns/{id}/void', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'void'])->name('returns.void');
    Route::get('/returns/{id}/print', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'print'])->name('returns.print');
    Route::get('/ajax/returns/invoices', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'getInvoices'])->name('ajax.returns.invoices');
    Route::get('/ajax/returns/orders', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'getSalesOrders'])->name('ajax.returns.orders');
    Route::get('/ajax/returns/customer-balance', [\App\Modules\Sales\Controllers\SalesReturnController::class, 'getCustomerBalance'])->name('ajax.returns.customer_balance');

    // GARANSI
    Route::get('/warranty', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'index'])->name('warranty.index');
    Route::get('/warranty/create', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'create'])->name('warranty.create');
    Route::post('/warranty', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'store'])->name('warranty.store');
    Route::get('/warranty/{id}', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'show'])->name('warranty.show');
    Route::get('/warranty/{id}/edit', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'edit'])->name('warranty.edit');
    Route::put('/warranty/{id}', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'update'])->name('warranty.update');
    Route::delete('/warranty/{id}', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'destroy'])->name('warranty.destroy');
    Route::post('/warranty/{id}/receive', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'receive'])->name('warranty.receive');
    Route::post('/warranty/{id}/mark-repaired', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'markRepaired'])->name('warranty.mark-repaired');
    Route::post('/warranty/{id}/delivery', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'createDelivery'])->name('warranty.delivery.create');
    Route::post('/warranty/{id}/delivery/{deliveryId}/post', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'postDelivery'])->name('warranty.delivery.post');
    Route::post('/warranty/{id}/void', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'void'])->name('warranty.void');
    Route::get('/ajax/warranty/invoices', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'getInvoices'])->name('ajax.warranty.invoices');
    Route::get('/ajax/warranty/orders', [\App\Modules\Sales\Controllers\WarrantyOrderController::class, 'getSalesOrders'])->name('ajax.warranty.orders');

    // ── Update Notes (works on any status) ──────────────────────────────
    Route::post('/orders/{id}/update-notes', [SalesOrderController::class, 'updateNotes'])->name('orders.update-notes');
    Route::post('/quotations/{id}/update-notes', [QuotationController::class, 'updateNotes'])->name('quotations.update-notes');
    Route::post('/invoices/{id}/update-notes', [\App\Http\Controllers\DebugInvoiceController::class, 'updateNotes'])->name('invoices.update-notes');

    // ── Laporan Penjualan ─────────────────────────────────────────────────
    Route::get('/reports/product-sales', [\App\Http\Controllers\Sales\SalesReportController::class, 'productSales'])->name('reports.product-sales');
    Route::get('/reports/manual-sales', [\App\Http\Controllers\Sales\ProductSalesManualController::class, 'index'])->name('reports.manual-sales');
    Route::post('/reports/manual-sales', [\App\Http\Controllers\Sales\ProductSalesManualController::class, 'store'])->name('reports.manual-sales.store');
    Route::get('/reports/manual-sales/template', [\App\Http\Controllers\Sales\ProductSalesManualController::class, 'importTemplate'])->name('reports.manual-sales.template');
    Route::post('/reports/manual-sales/import', [\App\Http\Controllers\Sales\ProductSalesManualController::class, 'import'])->name('reports.manual-sales.import');
    Route::delete('/reports/manual-sales/{id}', [\App\Http\Controllers\Sales\ProductSalesManualController::class, 'destroy'])->name('reports.manual-sales.destroy');
});

// ═══════════════════════════════════════════════════════════════
// POS MODULE — Pemrosesan Pesanan (fulfillment dashboard tim packing)
// ═══════════════════════════════════════════════════════════════
Route::prefix('erp/pos')->name('pos.')->group(function () {
    // Kasir POS — buat transaksi langsung (Invoice + Bayar, tanpa SO)
    Route::get('/kasir',          [\App\Modules\POS\Controllers\PosOrderController::class, 'kasir'])->name('kasir');
    Route::get('/kasir/search',   [\App\Modules\POS\Controllers\PosOrderController::class, 'search'])->name('kasir.search');
    Route::post('/kasir/checkout', [\App\Modules\POS\Controllers\PosOrderController::class, 'checkout'])->name('kasir.checkout');
    // Resolve promo untuk Kasir (akses lewat menu pos, supaya kasir non-admin tetap bisa).
    Route::get('/kasir/promo-resolve', [\App\Http\Controllers\Sales\PromosiController::class, 'resolveApi'])->name('kasir.promo-resolve');

    Route::get('/fulfillment/belum-siap',     [\App\Modules\POS\Controllers\FulfillmentController::class, 'belumSiap'])->name('fulfillment.belum-siap');
    Route::get('/fulfillment/perlu-diproses', [\App\Modules\POS\Controllers\FulfillmentController::class, 'perluDiproses'])->name('fulfillment.perlu-diproses');
    Route::get('/fulfillment/telah-diproses', [\App\Modules\POS\Controllers\FulfillmentController::class, 'telahDiproses'])->name('fulfillment.telah-diproses');
    Route::get('/fulfillment/dikirim',        [\App\Modules\POS\Controllers\FulfillmentController::class, 'dikirim'])->name('fulfillment.dikirim');
    Route::get('/fulfillment/selesai',        [\App\Modules\POS\Controllers\FulfillmentController::class, 'selesai'])->name('fulfillment.selesai');
    Route::post('/fulfillment/sync-marketplace', [\App\Modules\POS\Controllers\FulfillmentController::class, 'syncMarketplace'])->name('fulfillment.sync-marketplace');
    Route::post('/fulfillment/so/{so}/toggle-printed', [\App\Modules\POS\Controllers\FulfillmentController::class, 'togglePrinted'])->whereNumber('so')->name('fulfillment.toggle-printed');
    Route::get('/fulfillment/pembatalan',     [\App\Modules\POS\Controllers\FulfillmentController::class, 'pembatalan'])->name('fulfillment.pembatalan');
    Route::post('/fulfillment/sync-cancel',   [\App\Modules\POS\Controllers\FulfillmentController::class, 'syncCancel'])->name('fulfillment.sync-cancel');
    Route::post('/fulfillment/so/{so}/proses', [\App\Modules\POS\Controllers\FulfillmentController::class, 'prosesPesanan'])->whereNumber('so')->name('fulfillment.proses');
    Route::post('/fulfillment/proses-bulk', [\App\Modules\POS\Controllers\FulfillmentController::class, 'prosesBulk'])->name('fulfillment.proses-bulk');
    Route::post('/fulfillment/book-bulk', [\App\Modules\POS\Controllers\FulfillmentController::class, 'bookBulk'])->name('fulfillment.book-bulk');
    Route::post('/fulfillment/so/{so}/seller-notes', [\App\Modules\POS\Controllers\FulfillmentController::class, 'updateSellerNotes'])->whereNumber('so')->name('fulfillment.seller-notes');
    // Cetak resi/faktur marketplace (label resmi Jubelio) — proxy URL report, same-tab.
    Route::get('/fulfillment/jubelio-resi-bulk', [\App\Modules\POS\Controllers\FulfillmentController::class, 'cetakResiJubelioBulk'])->name('fulfillment.jubelio-resi-bulk');
    Route::get('/fulfillment/so/{so}/jubelio-resi', [\App\Modules\POS\Controllers\FulfillmentController::class, 'cetakResiJubelio'])->whereNumber('so')->name('fulfillment.jubelio-resi');
    Route::get('/fulfillment/so/{so}/jubelio-faktur', [\App\Modules\POS\Controllers\FulfillmentController::class, 'cetakFakturJubelio'])->whereNumber('so')->name('fulfillment.jubelio-faktur');
});

// ═══════════════════════════════════════════════════════════════
// PURCHASING MODULE
// ═══════════════════════════════════════════════════════════════
Route::prefix('erp/purchasing')->name('purchasing.')->group(function () {

    // Suppliers
    Route::get('suppliers', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/create', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('suppliers', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{id}', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('suppliers/{id}/edit', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('suppliers/{id}', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('suppliers/{id}', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'destroy'])->name('suppliers.destroy');
    Route::post('suppliers/{id}/archive', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'archive'])->name('suppliers.archive');
    Route::post('suppliers/{id}/restore', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'restore'])->name('suppliers.restore');

    // Purchase Orders
    Route::get('orders', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/create', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{id}', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'show'])->whereNumber('id')->name('orders.show');
    Route::get('orders/{id}/edit', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'edit'])->whereNumber('id')->name('orders.edit');
    Route::put('orders/{id}', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'update'])->whereNumber('id')->name('orders.update');
    Route::delete('orders/{id}', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'destroy'])->whereNumber('id')->name('orders.destroy');
    Route::get('orders/{id}/print', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'print'])->whereNumber('id')->name('orders.print');
    Route::get('orders/{id}/pdf', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'downloadPdf'])->whereNumber('id')->name('orders.pdf');

    // Purchase Invoices
    Route::get('invoices', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/create', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'create'])->name('invoices.create');
    Route::post('invoices', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{id}', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'show'])->whereNumber('id')->name('invoices.show');
    Route::get('invoices/{id}/edit', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'edit'])->whereNumber('id')->name('invoices.edit');
    Route::put('invoices/{id}', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'update'])->whereNumber('id')->name('invoices.update');
    Route::delete('invoices/{id}', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'destroy'])->whereNumber('id')->name('invoices.destroy');
    Route::post('invoices/{id}/post', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'post'])->whereNumber('id')->name('invoices.post');
    Route::post('invoices/{id}/void', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'void'])->whereNumber('id')->name('invoices.void');
    Route::get('invoices/{id}/print', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'print'])->whereNumber('id')->name('invoices.print');
    Route::get('invoices/{id}/pdf', [\App\Modules\Purchasing\Controllers\PurchaseInvoiceController::class, 'downloadPdf'])->whereNumber('id')->name('invoices.pdf');

    // Supplier Payments (DP + pelunasan, 1 menu)
    Route::get('payments', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{id}', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'show'])->whereNumber('id')->name('payments.show');
    Route::get('payments/{id}/edit', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'edit'])->whereNumber('id')->name('payments.edit');
    Route::put('payments/{id}', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'update'])->whereNumber('id')->name('payments.update');
    Route::delete('payments/{id}', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'destroy'])->whereNumber('id')->name('payments.destroy');
    Route::post('payments/{id}/post', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'post'])->whereNumber('id')->name('payments.post');
    Route::post('payments/{id}/void', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'void'])->whereNumber('id')->name('payments.void');
    Route::get('payments/{id}/print', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'print'])->whereNumber('id')->name('payments.print');
    Route::get('payments/{id}/pdf', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'downloadPdf'])->whereNumber('id')->name('payments.pdf');

    // Returns
    Route::get('returns', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/create', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'create'])->name('returns.create');
    Route::post('returns', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'store'])->name('returns.store');
    Route::get('returns/{id}', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'show'])->whereNumber('id')->name('returns.show');
    Route::get('returns/{id}/edit', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'edit'])->whereNumber('id')->name('returns.edit');
    Route::put('returns/{id}', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'update'])->whereNumber('id')->name('returns.update');
    Route::delete('returns/{id}', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'destroy'])->whereNumber('id')->name('returns.destroy');
    Route::post('returns/{id}/post', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'post'])->whereNumber('id')->name('returns.post');
    Route::post('returns/{id}/void', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'void'])->whereNumber('id')->name('returns.void');

    // Ajax helpers
    Route::get('api/suppliers/search', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'search'])->name('api.suppliers.search');
    Route::post('api/suppliers', [\App\Modules\Purchasing\Controllers\SupplierController::class, 'storeAjax'])->name('api.suppliers.store');
    Route::get('api/po/{id}/items', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'poItems'])->whereNumber('id')->name('api.po.items');
    Route::get('api/expense-accounts/search', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'searchExpenseAccounts'])->name('api.expense-accounts.search');
    Route::get('api/expense-accounts/frequent', [\App\Modules\Purchasing\Controllers\PurchaseOrderController::class, 'frequentExpenseAccounts'])->name('api.expense-accounts.frequent');
    Route::get('api/suppliers/{id}/open-invoices', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'openInvoices'])->whereNumber('id')->name('api.suppliers.open-invoices');
    Route::get('api/suppliers/{id}/open-dp', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'openDp'])->whereNumber('id')->name('api.suppliers.open-dp');
    Route::get('api/suppliers/{id}/open-pos', [\App\Modules\Purchasing\Controllers\SupplierPaymentController::class, 'openPos'])->whereNumber('id')->name('api.suppliers.open-pos');

    // Return ajax endpoints
    Route::get('api/returns/invoices', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'getInvoices'])->name('api.returns.invoices');
    Route::get('api/returns/orders', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'getOrders'])->name('api.returns.orders');
    Route::get('api/returns/dp-balance', [\App\Modules\Purchasing\Controllers\PurchaseReturnController::class, 'getDpBalance'])->name('api.returns.dp-balance');
});

// ═══════════════════════════════════════════════════════════════
// FIXED ASSETS MODULE
// ═══════════════════════════════════════════════════════════════
Route::prefix('erp/fixed-assets')->name('fixed-assets.')->group(function () {

    // Daftar Aset
    Route::get('/', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'index'])->name('index');
    Route::get('create', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'create'])->name('create');
    Route::post('/', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'store'])->name('store');
    Route::get('{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'show'])->whereNumber('id')->name('show');
    Route::get('{id}/edit', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'destroy'])->whereNumber('id')->name('destroy');
    Route::post('{id}/post', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'post'])->whereNumber('id')->name('post');
    Route::post('{id}/void', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'void'])->whereNumber('id')->name('void');

    // Categories
    Route::get('categories', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/create', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'create'])->name('categories.create');
    Route::post('categories', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{id}/edit', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'edit'])->whereNumber('id')->name('categories.edit');
    Route::put('categories/{id}', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'update'])->whereNumber('id')->name('categories.update');
    Route::delete('categories/{id}', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'destroy'])->whereNumber('id')->name('categories.destroy');

    // Transfers
    Route::get('transfers', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/create', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'create'])->name('transfers.create');
    Route::post('transfers', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'store'])->name('transfers.store');
    Route::get('transfers/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'show'])->whereNumber('id')->name('transfers.show');
    Route::get('transfers/{id}/edit', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'edit'])->whereNumber('id')->name('transfers.edit');
    Route::put('transfers/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'update'])->whereNumber('id')->name('transfers.update');
    Route::delete('transfers/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'destroy'])->whereNumber('id')->name('transfers.destroy');
    Route::post('transfers/{id}/post', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'post'])->whereNumber('id')->name('transfers.post');
    Route::post('transfers/{id}/void', [\App\Modules\FixedAsset\Controllers\FixedAssetTransferController::class, 'void'])->whereNumber('id')->name('transfers.void');

    // Disposals
    Route::get('disposals', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'index'])->name('disposals.index');
    Route::get('disposals/create', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'create'])->name('disposals.create');
    Route::post('disposals', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'store'])->name('disposals.store');
    Route::get('disposals/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'show'])->whereNumber('id')->name('disposals.show');
    Route::get('disposals/{id}/edit', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'edit'])->whereNumber('id')->name('disposals.edit');
    Route::put('disposals/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'update'])->whereNumber('id')->name('disposals.update');
    Route::delete('disposals/{id}', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'destroy'])->whereNumber('id')->name('disposals.destroy');
    Route::post('disposals/{id}/post', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'post'])->whereNumber('id')->name('disposals.post');
    Route::post('disposals/{id}/void', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'void'])->whereNumber('id')->name('disposals.void');
    Route::get('disposals/{id}/print', [\App\Modules\FixedAsset\Controllers\FixedAssetDisposalController::class, 'print'])->whereNumber('id')->name('disposals.print');

    // Settings
    Route::get('settings', [\App\Modules\FixedAsset\Controllers\SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [\App\Modules\FixedAsset\Controllers\SettingsController::class, 'update'])->name('settings.update');

    // AJAX
    Route::get('api/assets/active', [\App\Modules\FixedAsset\Controllers\FixedAssetController::class, 'searchActive'])->name('api.assets.active');
    Route::get('api/categories/{id}/defaults', [\App\Modules\FixedAsset\Controllers\AssetCategoryController::class, 'getDefaults'])->whereNumber('id')->name('api.categories.defaults');
});

// ═══════════════════════════════════════════════════════════════
// PRODUCTION MODULE
// ═══════════════════════════════════════════════════════════════
Route::prefix('erp/production')->name('production.')->group(function () {
    // Departemen
    Route::get('departments', [\App\Modules\Production\Controllers\DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [\App\Modules\Production\Controllers\DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departments/{id}', [\App\Modules\Production\Controllers\DepartmentController::class, 'update'])->name('departments.update');
    Route::delete('departments/{id}', [\App\Modules\Production\Controllers\DepartmentController::class, 'destroy'])->name('departments.destroy');
    Route::post('departments/{id}/activate', [\App\Modules\Production\Controllers\DepartmentController::class, 'activate'])->name('departments.activate');
    Route::post('departments/{id}/executors', [\App\Modules\Production\Controllers\DepartmentController::class, 'storeExecutor'])->name('departments.executors.store');
    Route::delete('departments/{id}/executors/{execId}', [\App\Modules\Production\Controllers\DepartmentController::class, 'destroyExecutor'])->name('departments.executors.destroy');
    Route::post('departments/{id}/members', [\App\Modules\Production\Controllers\DepartmentController::class, 'addMember'])->name('departments.members.add');
    Route::delete('departments/{id}/members/{karyawanId}', [\App\Modules\Production\Controllers\DepartmentController::class, 'removeMember'])->name('departments.members.remove');

    // Eksekutor (menu tersendiri, lintas divisi)
    Route::get   ('executors',         [\App\Modules\Production\Controllers\ExecutorController::class, 'index'])  ->name('executors.index');
    Route::post  ('executors',         [\App\Modules\Production\Controllers\ExecutorController::class, 'store'])  ->name('executors.store');
    Route::put   ('executors/{id}',    [\App\Modules\Production\Controllers\ExecutorController::class, 'update']) ->name('executors.update');
    Route::delete('executors/{id}',    [\App\Modules\Production\Controllers\ExecutorController::class, 'destroy'])->name('executors.destroy');

    // BOM
    Route::get('boms', [\App\Modules\Production\Controllers\BomController::class, 'index'])->name('boms.index');
    Route::get('boms/create', [\App\Modules\Production\Controllers\BomController::class, 'create'])->name('boms.create');
    Route::post('boms', [\App\Modules\Production\Controllers\BomController::class, 'store'])->name('boms.store');
    Route::get('boms/{id}/edit', [\App\Modules\Production\Controllers\BomController::class, 'edit'])->name('boms.edit');
    Route::get('boms/{id}', fn(int $id) => redirect()->route('production.boms.edit', $id));
    Route::put('boms/{id}', [\App\Modules\Production\Controllers\BomController::class, 'update'])->name('boms.update');
    Route::delete('boms/{id}', [\App\Modules\Production\Controllers\BomController::class, 'destroy'])->name('boms.destroy');
    Route::post('boms/{id}/clone', [\App\Modules\Production\Controllers\BomController::class, 'clone'])->name('boms.clone');
    Route::post('boms/{id}/toggle-auto', [\App\Modules\Production\Controllers\BomController::class, 'toggleAuto'])->name('boms.toggle-auto');
    Route::post('boms/{id}/update-cycles', [\App\Modules\Production\Controllers\BomController::class, 'updateCycles'])->name('boms.update-cycles');
    Route::post('boms/run-auto', [\App\Modules\Production\Controllers\BomController::class, 'runAuto'])->name('boms.run-auto');
    Route::post('boms/recalculate', [\App\Modules\Production\Controllers\BomController::class, 'recalculate'])->name('boms.recalculate');
    Route::get('ajax/bom-calculate', [\App\Modules\Production\Controllers\BomController::class, 'calculate'])->name('ajax.bom.calculate');
    Route::post('boms/preview-score', [\App\Modules\Production\Controllers\BomController::class, 'previewScore'])->name('boms.preview-score');
    Route::get('settings', [\App\Modules\Production\Controllers\BomController::class, 'settings'])->name('settings');
    Route::post('settings', [\App\Modules\Production\Controllers\BomController::class, 'updateSettings'])->name('settings.update');
    Route::post('settings/byproducts', [\App\Modules\Production\Controllers\BomController::class, 'updateByproductSettings'])->name('settings.byproducts.update');
    Route::post('settings/raw-materials', [\App\Modules\Production\Controllers\BomController::class, 'updateRawMaterialSettings'])->name('settings.raw-materials.update');
    Route::post('settings/testing-mode', [\App\Modules\Production\Controllers\BomController::class, 'updateTestingMode'])->name('settings.testing.update');

    // Production Orders
    Route::get('ajax/repair-sources', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'getRepairSources'])->name('ajax.repair-sources');
    Route::get('ajax/sales-orders',   [\App\Modules\Production\Controllers\ProductionOrderController::class, 'searchSalesOrders'])->name('ajax.sales-orders');
    Route::get('orders', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/create', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{id}', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{id}/confirm', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('orders/{id}/cancel', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('orders/{id}/finalize-confirm', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'finalizeConfirm'])->name('orders.finalize-confirm');
    Route::post('orders/{id}/finalize', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'finalize'])->name('orders.finalize');
    Route::post('orders/{id}/void', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'void'])->name('orders.void');
    Route::post('orders/{id}/edit-finalize', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'editFinalize'])->name('orders.edit-finalize');
    Route::post('orders/{id}/steps', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'storeStep'])->name('orders.steps.store');
    Route::post('orders/{id}/steps/add', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'addStep'])->name('orders.steps.add');
    Route::delete('orders/{id}/steps/{stepId}', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'deleteStep'])->name('orders.steps.delete');
    Route::post('orders/{id}/images', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'updateImages'])->name('orders.images.update');
    Route::post('orders/{id}/priority', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'updatePriority'])->name('orders.priority.update');

    // Penambahan bahan di tengah produksi
    Route::get('material-additions', [\App\Modules\Production\Controllers\ProductionMaterialAdditionController::class, 'index'])->name('material-additions.index');
    Route::get('material-additions/create', [\App\Modules\Production\Controllers\ProductionMaterialAdditionController::class, 'create'])->name('material-additions.create');
    Route::post('material-additions', [\App\Modules\Production\Controllers\ProductionMaterialAdditionController::class, 'store'])->name('material-additions.store');
    Route::post('material-additions/{id}/void', [\App\Modules\Production\Controllers\ProductionMaterialAdditionController::class, 'void'])->name('material-additions.void');

    // Proses produksi (eksekusi per divisi)
    Route::get('process',[\App\Modules\Production\Controllers\ProductionProcessController::class, 'index'])->name('process.index');
    Route::get('process/queue-scores', [\App\Modules\Production\Controllers\ProductionProcessController::class, 'queueScores'])->name('process.queue-scores');
    Route::get('process/step-timers',  [\App\Modules\Production\Controllers\ProductionProcessController::class, 'stepTimers'])->name('process.step-timers');
    Route::post('process/steps/{id}/start',    [\App\Modules\Production\Controllers\ProductionProcessController::class, 'startStep'])->name('process.steps.start');
    Route::post('process/steps/{id}/revert',   [\App\Modules\Production\Controllers\ProductionProcessController::class, 'revertStep'])->name('process.steps.revert');
    Route::post('process/steps/{id}/pause',    [\App\Modules\Production\Controllers\ProductionProcessController::class, 'pauseStep'])->name('process.steps.pause');
    Route::post('process/steps/{id}/resume',   [\App\Modules\Production\Controllers\ProductionProcessController::class, 'resumeStep'])->name('process.steps.resume');
    Route::post('process/steps/{id}/complete', [\App\Modules\Production\Controllers\ProductionProcessController::class, 'completeStep'])->name('process.steps.complete');
    Route::post('process/orders/{id}/notes',   [\App\Modules\Production\Controllers\ProductionProcessController::class, 'updateNotes'])->name('process.orders.notes');
    Route::post('process/orders/merge',        [\App\Modules\Production\Controllers\ProductionProcessController::class, 'mergeOrders'])->name('process.orders.merge');

    // Produksi selesai
    Route::get('completed', [\App\Modules\Production\Controllers\ProductionOrderController::class, 'completed'])->name('completed.index');
});

Route::get('/erp/journal/test', function () {

    $service = app(JournalPostingService::class);

    $dto = new JournalEntryDTO(
        date: '2026-02-15',
        reference_type: 'manual_test',
        reference_id: 1,
        description: 'Test Journal',
        lines: [
            new JournalLineDTO(account_id: 1, debit: 1000, credit: 0),
            new JournalLineDTO(account_id: 4, debit: 0, credit: 1000),
        ]
    );

    $service->post($dto);

    return "Journal posted.";
});

Route::get('/debug/inventory', function () {

    $engine = app(\App\Core\Inventory\InventoryEngine::class);

    $engine->purchase(
        product: 1,
        warehouse: 1,
        qty: 50,
        cost: 2000,
        reference: 'TEST'
    );

    return "Purchase transaction posted to Ledger, Stock, and FIFO.";
});

Route::get('/debug/stocks', function () {
    return \App\Core\Inventory\ProductStock::all();
});

Route::get('/debug/layers', function () {
    return \App\Core\Inventory\StockLayer::all();
});

Route::get('/debug/ledger', function () {
    return \App\Core\Inventory\StockLedger::all();
});

Route::get('/debug/customer-credit', function () {
    $service = app(\App\Modules\Sales\Services\CustomerCreditPaymentService::class);
    $customers = \App\Models\Customer::all();
    
    $results = $customers->map(function($c) use ($service) {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'credit_balance' => $service->getCustomerCreditBalance($c->id)
        ];
    });

    return response()->json($results);
});

Route::get('/debug/journal', function () {
    $refId = request('sales_return_id');
    $query = \App\Core\Journal\Journal::with('lines.account');
    
    if ($refId) {
        $query->where('reference_type', 'sales_return')->where('reference_id', $refId);
    }

    return response()->json($query->get());
});



// =============== FINANCE / KAS & BANK ===============
require __DIR__ . '/finance.php';

// =============== TASK MANAGER ===============
Route::prefix('erp/tasks')->name('tasks.')->group(function () {
    // Kategori (lebih dulu — supaya tidak ke-shadow oleh {task} param)
    Route::patch('categories/reorder', [\App\Modules\Tasks\Controllers\TaskCategoryController::class, 'reorder'])->name('categories.reorder');
    Route::resource('categories', \App\Modules\Tasks\Controllers\TaskCategoryController::class)->except(['show'])->parameters(['categories' => 'category']);

    // Scheduled (Otomasi → Jadwal)
    Route::post('schedules/{schedule}/run-now', [\App\Modules\Tasks\Controllers\TaskScheduleController::class, 'runNow'])->name('schedules.run');
    Route::resource('schedules', \App\Modules\Tasks\Controllers\TaskScheduleController::class)->parameters(['schedules' => 'schedule']);

    // Otomasi Stok & Pesanan (rule-based)
    Route::prefix('automation/{automationType}')->name('automation.rules.')->where(['automationType' => 'stock|order'])->group(function () {
        Route::post('check-stock', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'checkStockNow'])->name('check-stock');
        Route::get('/', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'index'])->name('index');
        Route::get('create', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'create'])->name('create');
        Route::post('/', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'store'])->name('store');
        Route::get('{rule}/edit', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'edit'])->name('edit');
        Route::patch('{rule}', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'update'])->name('update');
        Route::delete('{rule}', [\App\Modules\Tasks\Controllers\TaskAutomationRuleController::class, 'destroy'])->name('destroy');
    });

    // Daftar (table view)
    Route::get('list', [\App\Modules\Tasks\Controllers\TaskController::class, 'list'])->name('list');

    // Subtasks
    Route::post('{task}/subtasks', [\App\Modules\Tasks\Controllers\TaskSubtaskController::class, 'store'])->name('subtasks.store');
    Route::patch('subtasks/{subtask}/toggle', [\App\Modules\Tasks\Controllers\TaskSubtaskController::class, 'toggle'])->name('subtasks.toggle');
    Route::delete('subtasks/{subtask}', [\App\Modules\Tasks\Controllers\TaskSubtaskController::class, 'destroy'])->name('subtasks.destroy');

    // Preferensi kategori per-user (Board)
    Route::post('visible-categories', [\App\Modules\Tasks\Controllers\TaskController::class, 'saveVisibleCategories'])->name('visible_categories.save');

    // Task actions
    Route::patch('{task}/move', [\App\Modules\Tasks\Controllers\TaskController::class, 'move'])->name('move');
    Route::patch('{task}/status', [\App\Modules\Tasks\Controllers\TaskController::class, 'toggleStatus'])->name('status');
    Route::patch('{task}/assignee', [\App\Modules\Tasks\Controllers\TaskController::class, 'updateAssignee'])->name('assignee');
    Route::patch('{task}/priority', [\App\Modules\Tasks\Controllers\TaskController::class, 'updatePriority'])->name('priority');
    Route::patch('{task}/description', [\App\Modules\Tasks\Controllers\TaskController::class, 'updateDescription'])->name('description');
    Route::post('{task}/hide', [\App\Modules\Tasks\Controllers\TaskController::class, 'hide'])->name('hide');
    Route::post('{task}/unhide', [\App\Modules\Tasks\Controllers\TaskController::class, 'unhide'])->name('unhide');
    Route::get('{task}/json', [\App\Modules\Tasks\Controllers\TaskController::class, 'showJson'])->name('json');

    // Task links (dokumen terkait, multi)
    Route::post('{task}/links', [\App\Modules\Tasks\Controllers\TaskLinkController::class, 'store'])->name('links.store');
    Route::delete('links/{link}', [\App\Modules\Tasks\Controllers\TaskLinkController::class, 'destroy'])->name('links.destroy');

    // CRUD Task (resource paling akhir)
    Route::get('/', [\App\Modules\Tasks\Controllers\TaskController::class, 'index'])->name('index');
    Route::get('create', [\App\Modules\Tasks\Controllers\TaskController::class, 'create'])->name('create');
    Route::post('/', [\App\Modules\Tasks\Controllers\TaskController::class, 'store'])->name('store');
    Route::get('{task}', [\App\Modules\Tasks\Controllers\TaskController::class, 'show'])->name('show');
    Route::get('{task}/edit', [\App\Modules\Tasks\Controllers\TaskController::class, 'edit'])->name('edit');
    Route::match(['patch', 'put'], '{task}', [\App\Modules\Tasks\Controllers\TaskController::class, 'update'])->name('update');
    Route::delete('{task}', [\App\Modules\Tasks\Controllers\TaskController::class, 'destroy'])->name('destroy');
});

// ── PWA Karyawan (`/me/*`) — aplikasi mobile karyawan ────────────────
// Tidak lewat EnsureMenuAccess (hanya cek path erp/*). Auth + karyawan via middleware 'karyawan'.
Route::prefix('me')->name('me.')->group(function () {
    // Publik (belum login): self-register + halaman offline (fallback service worker)
    Route::get ('/register',       [\App\Http\Controllers\Me\RegisterController::class, 'show'])->name('register');
    Route::post('/register/check', [\App\Http\Controllers\Me\RegisterController::class, 'check'])->name('register.check');
    Route::post('/register',       [\App\Http\Controllers\Me\RegisterController::class, 'register'])->name('register.submit');
    Route::view('/offline', 'me.offline')->name('offline');

    // Service worker dilayani via route (bukan file fisik) agar scope = /me/ tanpa
    // direktori public/me yang akan membayangi route ini.
    Route::get('/sw.js', function () {
        return response(file_get_contents(resource_path('pwa/sw.js')), 200, [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/me/',
            'Cache-Control'          => 'no-cache, must-revalidate',
        ]);
    })->name('sw');

    // Area karyawan (login + role user + karyawan_id)
    Route::middleware('karyawan')->group(function () {
        Route::get('/',        [\App\Http\Controllers\Me\HomeController::class, 'index'])->name('home');
        Route::get('/absensi', [\App\Http\Controllers\Me\AbsensiController::class, 'index'])->name('absensi');
        Route::get('/cuti-sp', [\App\Http\Controllers\Me\CutiSpController::class, 'index'])->name('cuti');

        // Pengajuan izin (karyawan)
        Route::get ('/izin',        [\App\Http\Controllers\Me\IzinController::class, 'index'])->name('izin');
        Route::get ('/izin/create', [\App\Http\Controllers\Me\IzinController::class, 'create'])->name('izin.create');
        Route::post('/izin',        [\App\Http\Controllers\Me\IzinController::class, 'store'])->name('izin.store');

        // Profil (hub: Data Pribadi, Cuti & SP, Slip)
        Route::get ('/profil',      [\App\Http\Controllers\Me\ProfileController::class, 'index'])->name('profil');
        Route::get ('/profil/edit', [\App\Http\Controllers\Me\ProfileController::class, 'edit'])->name('profil.edit');
        Route::post('/profil',      [\App\Http\Controllers\Me\ProfileController::class, 'update'])->name('profil.update');

        // Web Push (notifikasi PWA)
        Route::post('/push/subscribe',   [\App\Http\Controllers\Me\PushSubscriptionController::class, 'store'])->name('push.subscribe');
        Route::post('/push/unsubscribe', [\App\Http\Controllers\Me\PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
        Route::post('/push/test',        [\App\Http\Controllers\Me\PushSubscriptionController::class, 'test'])->name('push.test');

        // Slip gaji (karyawan) — hanya periode finalized
        Route::get('/slip',      [\App\Http\Controllers\Me\SlipController::class, 'index'])->name('slip');
        Route::get('/slip/{id}', [\App\Http\Controllers\Me\SlipController::class, 'show'])->name('slip.show');
    });
});
