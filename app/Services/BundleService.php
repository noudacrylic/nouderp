<?php

namespace App\Services;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\InventoryEngine;

class BundleService
{
    /**
     * Hitung stok bundle yang tersedia berdasarkan komponen.
     * Mempertimbangkan reservasi aktif per warehouse.
     *
     * Rumus: floor( (stok_komponen - reserved_komponen) / qty_per_bundle )
     *        ambil yang terkecil dari semua komponen.
     */
    public function getBundleStock(int $bundleId, ?int $warehouseId = null): int
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
            $reservedQty = $reservedQuery->sum('qty');

            $availableComponent = max(0, $stockQty - $reservedQty);
            $qtyRequired = $component->{$qtyField} ?? 1;

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
