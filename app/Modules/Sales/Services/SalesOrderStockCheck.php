<?php

namespace App\Modules\Sales\Services;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Apakah stok masih cukup untuk memenuhi sebuah Sales Order?
 *
 * Tautan pembayaran boleh disebar saat SO masih draft, dan draft BELUM menahan stok.
 * Dua pembeli bisa memegang tautan atas barang yang sama: sisa 2, satu memesan 2 dan
 * satu memesan 1 — siapa pun yang membayar belakangan membuat stok minus. Pengecekan
 * ini dipakai tepat sebelum pembayaran diterima, bukan saat SO dibuat.
 *
 * Rumus ketersediaannya sengaja disamakan dengan angka "Stok" yang dilihat admin di
 * form SO (ProductController::stock): on-hand gudang SO − reservasi aktif SO LAIN +
 * preorder_stock. Reservasi milik SO ini sendiri tidak dikurangkan — kalau SO sudah
 * dikonfirmasi, barangnya memang sudah dipesan untuk dia.
 */
class SalesOrderStockCheck
{
    /** Jenis produk yang memang tidak punya stok fisik untuk dicek. */
    private const TANPA_STOK = ['service', 'non_stock', 'preorder'];

    /**
     * Kekurangan stok per produk. Kosong berarti aman.
     *
     * @return array<int, array{sku: string, name: string, needed: float, available: float, short: float}>
     */
    public function shortages(SalesOrder $so): array
    {
        $needs = $this->kebutuhanPerProduk($so);
        if (empty($needs)) {
            return [];
        }

        $produk = Product::whereIn('id', array_keys($needs))->get()->keyBy('id');
        $hasil = [];

        foreach ($needs as $productId => $needed) {
            $p = $produk->get($productId);
            if (! $p || in_array($p->sale_type, self::TANPA_STOK, true)) {
                continue;
            }

            $available = $this->tersediaUntuk($productId, $so);
            if ($needed > $available) {
                $hasil[] = [
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'needed' => (float) $needed,
                    'available' => (float) max(0, $available),
                    'short' => (float) ($needed - max(0, $available)),
                ];
            }
        }

        return $hasil;
    }

    /** SO ini boleh dibayar dari sisi stok? Kesepakatan keep stock membebaskan cek. */
    public function boleh(SalesOrder $so): bool
    {
        if ($so->allow_backorder) {
            return true;
        }

        return empty($this->shortages($so));
    }

    /**
     * Kebutuhan stok per produk dalam satuan dasar. Bundle dipecah ke komponennya —
     * cerminan persis dari yang direservasi SalesOrderService::confirm().
     *
     * @return array<int, float>
     */
    private function kebutuhanPerProduk(SalesOrder $so): array
    {
        $needs = [];

        foreach ($so->items()->with('product')->get() as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $baseQty = (float) $item->qty * (float) ($item->conversion_to_base ?? 1);
            $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');

            if (! $isBundle) {
                $needs[$product->id] = ($needs[$product->id] ?? 0) + $baseQty;
                continue;
            }

            $components = $product->bundleItems;
            if ($components->isEmpty()) {
                $components = $product->bundleComponents;
            }

            foreach ($components as $component) {
                $qty = $component->qty_required ?? $component->qty ?? 1;
                $pid = $component->component_product_id;
                $needs[$pid] = ($needs[$pid] ?? 0) + ($baseQty * (float) $qty);
            }
        }

        return $needs;
    }

    /** Stok yang benar-benar bisa dipakai SO ini. */
    private function tersediaUntuk(int $productId, SalesOrder $so): float
    {
        $stok = ProductStock::where('product_id', $productId);
        $reservasi = StockReservation::where('product_id', $productId)->where('status', 'active');

        if ($so->warehouse_id) {
            $stok->where('warehouse_id', $so->warehouse_id);
            $reservasi->where('warehouse_id', $so->warehouse_id);
        } else {
            $stok->whereHas('warehouse', fn ($q) => $q->where('is_sellable', true));
        }

        // Reservasi milik SO ini sendiri bukan saingan — itu jatah dia.
        $reservasi->where(fn ($q) => $q->whereNull('sales_order_id')->orWhere('sales_order_id', '!=', $so->id));

        $onHand = (float) $stok->sum('qty_on_hand');
        $dipesanOrangLain = (float) $reservasi->sum('qty');
        $preorder = (float) (Product::where('id', $productId)->value('preorder_stock') ?? 0);

        return $onHand - $dipesanOrangLain + $preorder;
    }
}
