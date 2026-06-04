<?php

namespace App\Modules\Marketplace\Jubelio\Observers;

use App\Core\Inventory\Product;
use App\Models\InventoryLedger;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Sales\Models\SalesDelivery;

/**
 * Menandai produk yang stoknya berubah agar didorong ke Jubelio oleh cron
 * (jubelio:push-stock). Tidak melakukan HTTP di sini — observer ini ikut dalam
 * transaksi posting stok inti (pembelian, SJ, penyesuaian, transfer, produksi),
 * jadi harus ringan dan tidak boleh menggagalkan operasi inti.
 *
 * Loop prevention: penjualan (SJ) yang berasal dari SO pesanan Jubelio TIDAK
 * ditandai — Jubelio sudah mengurangi stoknya sendiri saat menjual.
 */
class InventoryLedgerObserver
{
    /** Cache status aktif Jubelio per proses (hindari query singleton tiap baris ledger). */
    private static ?bool $active = null;

    public function created(InventoryLedger $ledger): void
    {
        if (!$this->jubelioActive()) {
            return;
        }

        // Hanya produk yang ditandai sinkron ke Jubelio.
        $syncFlag = Product::where('id', $ledger->product_id)->value('sync_to_jubelio');
        if (!$syncFlag) {
            return;
        }

        // Loop prevention: penjualan dari SO Jubelio jangan dipantulkan balik.
        if ($ledger->transaction_type === 'sale' && $this->isJubelioSale((int) $ledger->transaction_id)) {
            return;
        }

        // Tandai pending (update langsung tanpa memicu event model → tidak rekursif).
        Product::where('id', $ledger->product_id)->update(['jubelio_sync_pending' => true]);
    }

    private function isJubelioSale(int $deliveryId): bool
    {
        $soId = SalesDelivery::where('id', $deliveryId)->value('sales_order_id');
        if (!$soId) {
            return false;
        }
        return JubelioOrderLink::where('sales_order_id', $soId)->exists();
    }

    private function jubelioActive(): bool
    {
        if (self::$active === null) {
            self::$active = JubelioSetting::singleton()->isConfigured();
        }
        return self::$active;
    }
}
