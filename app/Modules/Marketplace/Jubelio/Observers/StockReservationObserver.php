<?php

namespace App\Modules\Marketplace\Jubelio\Observers;

use App\Core\Inventory\Product;
use App\Core\Inventory\StockReservation;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;

/**
 * "Stok Jubelio" yang didorong ERP = stok fisik − reservasi pesanan NON-marketplace
 * (lihat JubelioStockSyncService::pushProduct). Reservasi dibuat saat SO dikonfirmasi —
 * peristiwa ini TIDAK menggerakkan ledger stok, jadi InventoryLedgerObserver tidak menyala.
 * Observer ini menutup celah itu: setiap reservasi non-marketplace dibuat/diubah/dihapus,
 * tandai produk (+ bundle induk) agar didorong ulang ke Jubelio oleh cron.
 *
 * Loop prevention (sama seperti InventoryLedgerObserver): reservasi milik SO marketplace
 * DIABAIKAN — perubahannya (mis. berkurang saat Surat Jalan terbit) sudah tercermin di
 * Jubelio sendiri; mendorongnya balik = pengurangan ganda. Pengubahan stok fisik (saat DO)
 * tetap ditangani InventoryLedgerObserver.
 */
class StockReservationObserver
{
    /** Cache status aktif Jubelio per proses. */
    private static ?bool $active = null;

    public function created(StockReservation $res): void
    {
        $this->flag($res);
    }

    public function updated(StockReservation $res): void
    {
        $this->flag($res);
    }

    public function deleted(StockReservation $res): void
    {
        $this->flag($res);
    }

    private function flag(StockReservation $res): void
    {
        if (!$this->jubelioActive()) {
            return;
        }

        // Loop prevention: reservasi SO marketplace tidak dipantulkan balik ke Jubelio.
        if ($res->sales_order_id && JubelioOrderLink::where('sales_order_id', $res->sales_order_id)->exists()) {
            return;
        }

        // 1. Produk yang direservasi (bila disinkron). Update langsung tanpa memicu event.
        if (Product::where('id', $res->product_id)->value('sync_to_jubelio')) {
            Product::where('id', $res->product_id)->update(['jubelio_sync_pending' => true]);
        }

        // 2. Bundle yang memuat produk ini sebagai komponen — stok tersedia bundle ikut berubah.
        $bundleIds = \App\Core\Inventory\BundleComponent::where('component_product_id', $res->product_id)
            ->pluck('bundle_product_id');
        if ($bundleIds->isEmpty()) {
            $bundleIds = \App\Core\Inventory\ProductBundle::where('component_product_id', $res->product_id)
                ->pluck('bundle_product_id');
        }
        if ($bundleIds->isNotEmpty()) {
            Product::whereIn('id', $bundleIds)
                ->where('sync_to_jubelio', true)
                ->update(['jubelio_sync_pending' => true]);
        }
    }

    private function jubelioActive(): bool
    {
        if (self::$active === null) {
            self::$active = JubelioSetting::singleton()->isConfigured();
        }
        return self::$active;
    }
}
