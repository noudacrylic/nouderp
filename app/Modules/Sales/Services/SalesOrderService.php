<?php

namespace App\Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Modules\Sales\Models\SalesOrder;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Product;
use Exception;

use App\Enums\SalesOrderStatus;

class SalesOrderService
{
    public function confirm(int $salesOrderId): void
    {
        DB::transaction(function () use ($salesOrderId) {

            // lockForUpdate: cek-status-draft + buat-reservasi harus atomik. Tanpa lock,
            // dua confirm konkuren bisa sama-sama lolos cek draft → reservasi DOBEL.
            // (Tidak ada hard-block availability: SO mendukung preorder/stok menyusul.)
            $so = SalesOrder::with([
                'items.product.bundleItems',      // -> product_bundles (qty_required)
                'items.product.bundleComponents', // -> bundle_components (qty)
            ])->lockForUpdate()->findOrFail($salesOrderId);

            if ($so->status !== SalesOrderStatus::DRAFT->value) {
                throw new Exception("SO not in draft status.");
            }

            foreach ($so->items as $item) {

                $product = $item->product;

                if (!$product) continue;

                $baseQty = $item->qty * ($item->conversion_to_base ?? 1);

                // Cek type: pakai sale_type (kolom asli) dan fallback ke type accessor
                $isBundle = ($product->sale_type === 'bundle') || ($product->type === 'bundle');

                if ($isBundle) {

                    // Coba ambil dari bundleItems (product_bundles) dulu
                    $components = $product->bundleItems;

                    // Jika kosong, fallback ke bundleComponents (bundle_components)
                    if ($components->isEmpty()) {
                        $components = $product->bundleComponents;
                    }

                    if ($components->isEmpty()) {
                        \Log::warning("Bundle product [{$product->id}] has no components defined.");
                        continue;
                    }

                    foreach ($components as $component) {
                        // Ambil qty: qty_required (ProductBundle) atau qty (BundleComponent)
                        $componentQty = $component->qty_required ?? $component->qty ?? 1;
                        $reservationQty = $baseQty * $componentQty;

                        StockReservation::create([
                            'product_id'     => $component->component_product_id,
                            'warehouse_id'   => $so->warehouse_id,
                            'sales_order_id' => $so->id,
                            'qty'            => $reservationQty,
                            'status'         => 'active'
                        ]);
                    }

                } else {

                    StockReservation::create([
                        'product_id'     => $item->product_id,
                        'warehouse_id'   => $so->warehouse_id,
                        'sales_order_id' => $so->id,
                        'qty'            => $baseQty,
                        'status'         => 'active'
                    ]);

                }

            }

            $update = ['status' => SalesOrderStatus::CONFIRMED->value];

            // Ambil di Toko → generate booking code (sekali, idempotent) + status pending.
            if ($so->delivery_method === 'ambil_toko' && empty($so->pickup_code)) {
                $update['pickup_code']   = $this->generatePickupCode();
                $update['pickup_status'] = 'pending';
            }

            $so->update($update);
        });

        // Auto-produksi preorder TIDAK lagi dipicu saat SO dikonfirmasi.
        // Pemicunya dipindah ke saat DP/uang muka di-post (CustomerPaymentService),
        // supaya SO yang dibuat tapi customer tidak jadi bayar tidak meninggalkan OP.
    }

    /**
     * Booking code Ambil di Toko: 4 angka acak (0000–9999) agar mudah diinput.
     * Retensi 1 tahun — kode hanya dianggap "terpakai" bila ada SO dengan kode
     * sama yang dibuat dalam 12 bulan terakhir; kode lebih lama bebas dipakai lagi.
     */
    private function generatePickupCode(): string
    {
        $cutoff = now()->subYear();

        $taken = fn (string $code) => SalesOrder::where('pickup_code', $code)
            ->where('created_at', '>=', $cutoff)
            ->exists();

        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!$taken($code)) {
                return $code;
            }
        }

        // Fallback sangat jarang (hampir semua 4-digit terpakai dalam setahun) → 5 digit.
        do {
            $code = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while ($taken($code));

        return $code;
    }
}
