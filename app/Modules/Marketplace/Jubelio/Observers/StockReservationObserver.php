<?php

namespace App\Modules\Marketplace\Jubelio\Observers;

use App\Core\Inventory\Product;
use App\Core\Inventory\StockReservation;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;

/**
 * "Stok Jubelio" yang didorong ERP = stok fisik − reservasi yang belum tercermin di Jubelio
 * (lihat JubelioStockSyncService::pushProduct). Reservasi dibuat saat SO dikonfirmasi —
 * peristiwa ini TIDAK menggerakkan ledger stok, jadi InventoryLedgerObserver tidak menyala.
 * Observer ini menutup celah itu: setiap reservasi dibuat/diubah/dihapus, tandai produk
 * (+ bundle induk) agar didorong ulang ke Jubelio oleh cron.
 *
 * Reservasi milik SO marketplace TIDAK lagi diabaikan di sini. Dulu diabaikan demi loop
 * prevention, tapi itu membuat pesanan BUNDLE dari marketplace tidak menandai apa pun:
 * Jubelio menahan item bundle, ERP mereservasi KOMPONEN, dan komponen baru dikoreksi saat
 * cron rekonsiliasi 2 jam — persis jendela oversell yang mau ditutup. Menandai selalu itu
 * murah: pushProduct menyaring lebih dulu lewat cache jubelio_synced_qty, jadi produk yang
 * angkanya tak berubah berhenti tanpa satu pun panggilan HTTP. Perlindungan terhadap potong
 * dua kali sekarang ada di tempat yang benar — JubelioOrderLink::coveredReservationQty.
 * Pengubahan stok fisik (saat DO) tetap ditangani InventoryLedgerObserver.
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

        // 1. Produk yang direservasi (bila disinkron). Update langsung tanpa memicu event.
        if (Product::where('id', $res->product_id)->value('sync_to_jubelio')) {
            Product::where('id', $res->product_id)->update(['jubelio_sync_pending' => true]);
        }

        // 2. Bundle yang memuat produk ini sebagai komponen — stok tersedia bundle ikut berubah.
        // Gabungkan KEDUA tabel komponen: satu produk bisa jadi komponen bundle A lewat
        // bundle_components dan bundle B lewat product_bundles; pola "fallback bila kosong"
        // akan melewatkan B.
        $bundleIds = \App\Core\Inventory\BundleComponent::where('component_product_id', $res->product_id)
            ->pluck('bundle_product_id')
            ->merge(
                \App\Core\Inventory\ProductBundle::where('component_product_id', $res->product_id)
                    ->pluck('bundle_product_id')
            )
            ->unique();
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
