<?php

namespace App\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\InventoryEngine;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;

class BundleService
{
    /**
     * Hitung stok bundle yang tersedia berdasarkan komponen.
     * Mempertimbangkan reservasi aktif per warehouse.
     *
     * Rumus: floor( (stok_komponen - reserved_komponen) / qty_per_bundle )
     *        ambil yang terkecil dari semua komponen.
     *
     * @param bool $physical true = pakai ON-HAND komponen murni (tanpa kurangi reservasi).
     * @param bool $excludeMarketplaceReserved (hanya berlaku bila $physical=false) true =
     *                       jangan kurangi bagian reservasi komponen yang pasangannya SUDAH
     *                       ditahan Jubelio atas item BUNDLE INI (lihat
     *                       JubelioOrderLink::coveredReservationQty). Dipakai saat mendorong
     *                       "stok Jubelio" agar pesanan bundle tidak terpotong dua kali.
     *
     *                       Penting: yang dikecualikan HANYA pesanan marketplace atas bundle
     *                       ini sendiri. Pesanan marketplace atas KOMPONEN-nya (dijual satuan)
     *                       tetap dikurangkan — Jubelio menahan item komponen, bukan item
     *                       bundle, jadi tanpa pengurangan ini bundle masih ditawarkan penuh
     *                       padahal bahannya sudah terjual.
     */
    public function getBundleStock(int $bundleId, ?int $warehouseId = null, bool $physical = false, bool $excludeMarketplaceReserved = false): int
    {
        // 1. Prioritaskan tabel bundle_components (model BundleComponent, field qty)
        $components = BundleComponent::where('bundle_product_id', $bundleId)->get();
        $qtyField = 'qty';

        if ($components->isEmpty()) {
            // Fallback ke product_bundles (model ProductBundle, field qty_required)
            $components = ProductBundle::where('bundle_product_id', $bundleId)->get();
            $qtyField = 'qty_required';
        }

        if ($components->isEmpty()) {
            return 0;
        }

        $availableBundle = PHP_INT_MAX;

        foreach ($components as $component) {
            $stockQuery = ProductStock::where('product_id', $component->component_product_id);
            if ($warehouseId) {
                $stockQuery->where('warehouse_id', $warehouseId);
            }
            $stockQty = $stockQuery->sum('qty_on_hand');

            $reservedQuery = StockReservation::where('product_id', $component->component_product_id)
                ->where('status', 'active');
            if ($warehouseId) {
                $reservedQuery->where('warehouse_id', $warehouseId);
            }
            $reservedQty = (float) $reservedQuery->sum('qty');

            $qtyRequired = $component->{$qtyField} ?? 1;

            if ($excludeMarketplaceReserved && $qtyRequired > 0) {
                // Pesanan marketplace atas bundle INI sudah ditahan Jubelio di item bundle-nya;
                // kalau ikut dikurangkan di sini, satu pesanan terpotong dua kali. Dikonversi
                // ke satuan komponen: 1 bundle memakai $qtyRequired komponen.
                $covered = JubelioOrderLink::coveredReservationQty(
                    $bundleId,
                    $component->component_product_id,
                    $warehouseId
                ) * $qtyRequired;

                $reservedQty = max(0.0, $reservedQty - $covered);
            }

            $availableComponent = max(0, $stockQty - ($physical ? 0 : $reservedQty));

            if ($qtyRequired <= 0) {
                $bundlePossible = 0;
            } else {
                $bundlePossible = (int) floor($availableComponent / $qtyRequired);
            }

            $availableBundle = min($availableBundle, $bundlePossible);
        }

        return $availableBundle === PHP_INT_MAX ? 0 : $availableBundle;
    }

    /**
     * Saat bundle dijual: kurangi stok semua komponen
     */
    public function consumeBundle(int $bundleId, float $qty, int $warehouseId, string $reference = 'bundle', int $deliveryId = 0, int $soId = 0): void
    {
        // 1. Prioritaskan tabel bundle_components
        $components = BundleComponent::where('bundle_product_id', $bundleId)->get();
        $qtyField = 'qty';

        // Fallback ke product_bundles
        if ($components->isEmpty()) {
            $components = ProductBundle::where('bundle_product_id', $bundleId)->get();
            $qtyField = 'qty_required';
        }

        // Guard: bundle tanpa komponen tidak boleh diproses — kalau dibiarkan, akan
        // "mengirim" tanpa mengurangi stok komponen apa pun & tanpa HPP (diam-diam salah).
        if ($components->isEmpty()) {
            throw new \RuntimeException(
                "Bundle (produk #{$bundleId}) belum punya komponen. Lengkapi komponen bundle sebelum diproses ke transaksi."
            );
        }

        $engine = app(InventoryEngine::class);

        foreach ($components as $c) {
            $consumeQty = ($c->{$qtyField} ?? 1) * $qty;

            $engine->ship(
                $c->component_product_id,
                $warehouseId,
                $consumeQty,
                $reference,
                $deliveryId,
                $soId
            );
        }
    }
}
