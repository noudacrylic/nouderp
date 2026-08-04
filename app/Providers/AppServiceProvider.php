<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Modules\Production\Models\Department;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Observers\FingerprintLogObserver;
use App\Core\Inventory\StockMovement;
use App\Models\InventoryLedger;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesAdvance;
use App\Modules\Tasks\Observers\SalesOrderObserver as TasksSalesOrderObserver;
use App\Modules\Tasks\Observers\StockMovementObserver as TasksStockMovementObserver;
use App\Modules\Production\Observers\SalesAdvanceObserver;
use App\Models\ProductPrice;
use App\Models\SalesInvoice;
use App\Modules\Payment\Observers\PaymentLinkDocumentObserver;
use App\Modules\Marketplace\Jubelio\Observers\InventoryLedgerObserver as JubelioInventoryLedgerObserver;
use App\Modules\Marketplace\Jubelio\Observers\ProductPriceObserver as JubelioProductPriceObserver;
use App\Modules\Marketplace\Jubelio\Observers\StockReservationObserver as JubelioStockReservationObserver;
use App\Core\Inventory\StockReservation;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Memoized per-request (soRows/warrantyRows). Singleton agar controller & partial
        // tab (badge jumlah) berbagi instance → tidak query dobel.
        $this->app->singleton(\App\Modules\POS\Services\FulfillmentReadinessService::class);
    }

    public function boot(): void
    {
        \Carbon\Carbon::setLocale('id');

        View::composer('layouts.erp', function ($view) {
            $view->with('sidebarDepartments',
                Department::where('is_active', true)
                    ->where('type', 'produksi')
                    ->orderBy('name')
                    ->get()
            );
        });

        FingerprintLog::observe(FingerprintLogObserver::class);
        SalesOrder::observe(TasksSalesOrderObserver::class);
        StockMovement::observe(TasksStockMovementObserver::class);
        // DP/uang muka di-post (channel apa pun) → auto-buat OP preorder.
        SalesAdvance::observe(SalesAdvanceObserver::class);
        // Perubahan stok (pembelian, SJ, penyesuaian, transfer, produksi) → tandai produk
        // untuk didorong ke Jubelio (push via cron, bukan HTTP di sini).
        InventoryLedger::observe(JubelioInventoryLedgerObserver::class);
        // Reservasi SO non-marketplace (dibuat saat SO dikonfirmasi, tak menggerakkan ledger)
        // → tandai produk agar "stok Jubelio" (= fisik − dipesan non-MP) didorong ulang.
        StockReservation::observe(JubelioStockReservationObserver::class);
        // Perubahan harga produk → tandai untuk push harga ke Jubelio (Fase 3).
        ProductPrice::observe(JubelioProductPriceObserver::class);
        // SO/Invoice di-void atau dihapus → tautan bayar Midtrans-nya dimatikan
        // (di ERP maupun di Midtrans, supaya VA/QRIS yang terbit tak bisa dibayar lagi).
        SalesOrder::observe(PaymentLinkDocumentObserver::class);
        SalesInvoice::observe(PaymentLinkDocumentObserver::class);
    }
}
