<?php

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\CrmStockWatch;
use App\Modules\CRM\Services\StockWatchService;
use Illuminate\Support\Facades\Log;

/**
 * Menyalakan pemeriksaan titipan "kabari kalau stoknya ada" begitu stok
 * sebuah SKU bergerak.
 *
 * Dipasang pada DUA model sekaligus, dan keduanya perlu:
 *  - InventoryLedger  → stok fisik berubah (pembelian, produksi selesai,
 *                       penyesuaian, transfer, surat jalan);
 *  - StockReservation → stok fisik tidak bergerak, tapi stok yang bisa
 *                       DIJANJIKAN berubah. SO yang batal melepas reservasi,
 *                       dan barang yang tadinya "habis" mendadak ada lagi.
 * Menyalakan hanya pada yang pertama berarti pelanggan tak pernah dikabari
 * saat barangnya bebas justru karena pesanan orang lain gugur.
 *
 * $afterCommit WAJIB. Observer stok berjalan di dalam transaksi posting inti,
 * dan di dalamnya stok yang terbaca masih setengah jadi — kabar bisa berangkat
 * atas stok yang semenit kemudian di-rollback. Setelah commit, yang dibaca
 * adalah keadaan yang benar-benar terjadi.
 */
class StokTitipanObserver
{
    public bool $afterCommit = true;

    public function created($model): void
    {
        $this->periksa($model);
    }

    public function updated($model): void
    {
        $this->periksa($model);
    }

    public function deleted($model): void
    {
        $this->periksa($model);
    }

    private function periksa($model): void
    {
        $productId = (int) ($model->product_id ?? 0);

        if (! $productId) {
            return;
        }

        /*
         * Penyaring termurah lebih dulu: satu EXISTS. Ledger stok adalah jalur
         * terpanas di ERP — tiap surat jalan, pembelian, dan penyesuaian lewat
         * sini — sedangkan titipan biasanya cuma segelintir. Tanpa penyaring
         * ini, setiap baris ledger membayar sebuah pemeriksaan penuh.
         */
        if (! CrmStockWatch::aktif()->where('product_id', $productId)->exists()) {
            return;
        }

        try {
            app(StockWatchService::class)->periksa([$productId]);
        } catch (\Throwable $e) {
            // Tidak boleh menjatuhkan posting stok. Kabar yang telat masih bisa
            // dikejar cron; surat jalan yang gagal terbit tidak bisa.
            Log::warning('[CRM] pemeriksaan titipan stok gagal', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
