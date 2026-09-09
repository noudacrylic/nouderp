<?php

namespace App\Core\Inventory\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;

/**
 * Stok yang benar-benar bisa dijanjikan ke pembeli, per SKU.
 *
 * Rumusnya = on_hand gudang JUAL − reservasi aktif + preorder_stock; SKU bundle
 * dihitung dari komponennya, bukan dari on_hand-nya sendiri.
 *
 * Dijadikan satu tempat karena angka ini muncul di DUA layar yang harus setuju:
 * etalase (yang dilihat pembeli) dan panel Produk di CRM (yang dibacakan admin
 * saat pembeli bertanya "stoknya ada berapa?"). Dua rumus terpisah berarti
 * cepat atau lambat admin menjanjikan barang yang di web sudah habis.
 */
class StokTersediaService
{
    /**
     * @param  iterable<int>  $productIds
     * @return array<int,float>  product_id => qty tersedia (tak pernah minus)
     */
    public function untuk(iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn ($v) => (int) $v)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $products = Product::whereIn('id', $ids)
            ->with(['bundleItems', 'bundleComponents'])
            ->get()->keyBy('id');

        // Komponen bundle ikut ditarik: stok bundle lahir dari stok komponennya.
        $semuaId = collect($ids);
        foreach ($products as $p) {
            if ($p->sale_type === 'bundle') {
                $semuaId = $semuaId->merge($this->komponen($p)->pluck('component_product_id'));
            }
        }
        $semuaId = $semuaId->filter()->unique()->values();

        $onHand = ProductStock::whereIn('product_id', $semuaId)
            ->whereHas('warehouse', fn ($q) => $q->where('is_sellable', true))
            ->selectRaw('product_id, SUM(qty_on_hand) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $reservasi = StockReservation::whereIn('product_id', $semuaId)
            ->where('status', 'active')
            ->selectRaw('product_id, SUM(qty) as qty')
            ->groupBy('product_id')->pluck('qty', 'product_id');

        $hasil = [];

        foreach ($ids as $id) {
            $p = $products->get($id);

            if ($p && $p->sale_type === 'bundle') {
                $tersedia = null;
                foreach ($this->komponen($p) as $c) {
                    $butuh = (float) ($c->qty_required ?? $c->qty ?? 1);
                    if ($butuh <= 0) {
                        continue;
                    }
                    $bebas   = (float) ($onHand[$c->component_product_id] ?? 0)
                             - (float) ($reservasi[$c->component_product_id] ?? 0);
                    $perKomp = (int) floor(max(0, $bebas) / $butuh);
                    $tersedia = is_null($tersedia) ? $perKomp : min($tersedia, $perKomp);
                }

                $hasil[$id] = (float) max(0, $tersedia ?? 0);
                continue;
            }

            $hasil[$id] = max(0, (float) ($onHand[$id] ?? 0)
                                - (float) ($reservasi[$id] ?? 0)
                                + (float) ($p->preorder_stock ?? 0));
        }

        return $hasil;
    }

    /** Stok tersedia satu SKU. */
    public function satu(int $productId): float
    {
        return $this->untuk([$productId])[$productId] ?? 0.0;
    }

    /** Baris komponen bundle — dua relasi warisan, yang terisi dipakai. */
    private function komponen(Product $p)
    {
        return $p->bundleItems->isNotEmpty() ? $p->bundleItems : $p->bundleComponents;
    }
}
