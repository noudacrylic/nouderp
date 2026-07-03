<?php

namespace App\Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Services\NumberGeneratorService;
use Exception;

use App\Enums\SalesOrderStatus;

class SalesOrderService
{
    /**
     * Buat Sales Order DRAFT dari data terstruktur (dipakai oleh asisten AI Telegram).
     *
     * Meniru math per-baris & total di SalesOrderController::store, tapi hanya untuk
     * kasus umum (tanpa quotation/marketplace/PPN-PPh/instant). $dto:
     *   customer_id (wajib), warehouse_id (opsional → gudang pertama), order_date,
     *   notes, delivery_method ('kurir'|'ambil_toko'), courier_name, shipping_gross,
     *   shipping_discount_type, shipping_discount_value, global_discount_type,
     *   global_discount_value, items[] { product_id, qty, unit_price,
     *   discount_type, discount_value, description }.
     */
    public function createDraftFromData(array $dto): SalesOrder
    {
        return DB::transaction(function () use ($dto) {
            $customerId  = (int) ($dto['customer_id'] ?? 0);
            $warehouseId = (int) ($dto['warehouse_id'] ?? 0) ?: (int) (Warehouse::orderBy('id')->value('id') ?? 0);

            if (! $customerId) {
                throw new Exception('Pelanggan wajib dipilih.');
            }
            if (! $warehouseId) {
                throw new Exception('Belum ada gudang di sistem.');
            }

            $deliveryMethod = $this->normalizeDeliveryMethod($dto['delivery_method'] ?? 'kurir');

            $so = SalesOrder::create([
                'order_number'    => NumberGeneratorService::forCustomer('SO', $customerId, null),
                'customer_id'     => $customerId,
                'warehouse_id'    => $warehouseId,
                'delivery_method' => $deliveryMethod,
                'order_date'      => $dto['order_date'] ?? now()->toDateString(),
                'notes'           => $dto['notes'] ?? null,
                'status'          => SalesOrderStatus::DRAFT->value,
                'grand_total'     => 0,
            ]);

            $this->applyItemsAndTotals($so, $dto, $deliveryMethod);

            return $so->fresh('items');
        });
    }

    /**
     * Perbarui Sales Order DRAFT dari data terstruktur (revisi lewat asisten AI).
     * Hanya boleh saat status draft; item lama dihapus & dibangun ulang.
     */
    public function updateDraftFromData(int $salesOrderId, array $dto): SalesOrder
    {
        return DB::transaction(function () use ($salesOrderId, $dto) {
            $so = SalesOrder::lockForUpdate()->findOrFail($salesOrderId);

            if ($so->status !== SalesOrderStatus::DRAFT->value) {
                throw new Exception('Hanya SO berstatus draft yang bisa diedit.');
            }

            $deliveryMethod = $this->normalizeDeliveryMethod($dto['delivery_method'] ?? $so->delivery_method ?? 'kurir');

            $header = ['delivery_method' => $deliveryMethod];
            if (array_key_exists('notes', $dto))        $header['notes']        = $dto['notes'];
            if (! empty($dto['customer_id']))           $header['customer_id']  = (int) $dto['customer_id'];
            if (! empty($dto['warehouse_id']))          $header['warehouse_id'] = (int) $dto['warehouse_id'];
            if (! empty($dto['order_date']))            $header['order_date']   = $dto['order_date'];
            $so->update($header);

            // Item baru dibangun ulang hanya bila dikirim; kalau tidak, pertahankan yang ada.
            if (array_key_exists('items', $dto)) {
                $so->items()->delete();
            }

            // Pertahankan ongkir & diskon total lama bila revisi tidak menyentuhnya
            // (kalau tidak, hitung ulang akan menganggapnya 0 dan menghapusnya).
            if (! array_key_exists('shipping_gross', $dto)) {
                $dto['shipping_gross']          = (float) $so->shipping_gross;
                $dto['shipping_discount_type']  = $so->shipping_discount_type ?: 'nominal';
                $dto['shipping_discount_value'] = (float) $so->shipping_discount_value;
                $dto['courier_name']            = $so->shipping_service_name;
            }
            if (! array_key_exists('global_discount_value', $dto)) {
                $dto['global_discount_type']  = $so->global_discount_type ?: 'nominal';
                $dto['global_discount_value'] = (float) $so->global_discount_value;
            }

            $this->applyItemsAndTotals($so, $dto, $deliveryMethod);

            return $so->fresh('items');
        });
    }

    /**
     * Bangun ulang item (bila $dto['items'] ada) & hitung ulang seluruh total + ongkir.
     * Konsisten dengan SalesOrderController::store (diskon nominal = per-unit, bulat rupiah).
     */
    private function applyItemsAndTotals(SalesOrder $so, array $dto, string $deliveryMethod): void
    {
        $subtotal          = 0;
        $totalItemDiscount = 0;

        if (array_key_exists('items', $dto)) {
            foreach ((array) $dto['items'] as $item) {
                if (empty($item['product_id'])) continue;

                $qty       = (float) ($item['qty'] ?? 0);
                $unitPrice = clean_number($item['unit_price'] ?? 0);
                if ($qty <= 0) continue;

                $lineSubtotal  = round($qty * $unitPrice);
                $discountType  = ($item['discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
                $discountValue = clean_number($item['discount_value'] ?? 0);

                $lineDiscount = $discountType === 'percent'
                    ? round($lineSubtotal * ($discountValue / 100))
                    : round($discountValue * $qty); // nominal = per-unit

                $lineTotal   = $lineSubtotal - $lineDiscount;
                $perUnitDisc = $qty > 0 ? $lineDiscount / $qty : 0;

                SalesOrderItem::create([
                    'sales_order_id'     => $so->id,
                    'product_id'         => (int) $item['product_id'],
                    'description'        => trim((string) ($item['description'] ?? '')) ?: null,
                    'conversion_to_base' => 1,
                    'qty'                => $qty,
                    'unit_price'         => $unitPrice,
                    'discount_type'      => $discountType,
                    'discount_value'     => $discountValue,
                    'discount_per_unit'  => $perUnitDisc,
                    'net_unit_price'     => $unitPrice - $perUnitDisc,
                    'line_subtotal'      => $lineSubtotal,
                    'line_discount'      => $lineDiscount,
                    'line_total'         => $lineTotal,
                ]);

                $subtotal          += $lineSubtotal;
                $totalItemDiscount += $lineDiscount;
            }
        } else {
            // Tidak ada item baru → hitung ulang dari item existing (mis. hanya ubah ongkir).
            foreach ($so->items()->get() as $it) {
                $subtotal          += (float) $it->line_subtotal;
                $totalItemDiscount += (float) $it->line_discount;
            }
        }

        $dpp = $subtotal - $totalItemDiscount;

        $globalType  = ($dto['global_discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
        $globalValue = clean_number($dto['global_discount_value'] ?? 0);
        $globalAmount = $globalType === 'percent' ? round($dpp * ($globalValue / 100)) : round($globalValue);
        $dpp -= $globalAmount;

        $ship     = $this->resolveShippingData($dto, $deliveryMethod);
        $grand    = round($dpp + $ship['net']);

        $so->update([
            'subtotal'                => $subtotal,
            'discount_total'          => $totalItemDiscount,
            'global_discount_type'    => $globalType,
            'global_discount_value'   => $globalValue,
            'global_discount_amount'  => $globalAmount,
            'shipping_cost'           => $ship['net'],
            'shipping_gross'          => $ship['gross'],
            'shipping_discount_type'  => $ship['disc_type'],
            'shipping_discount_value' => $ship['disc_value'],
            'shipping_courier_code'   => $ship['courier_code'],
            'shipping_service_code'   => null,
            'shipping_service_name'   => $ship['service_name'],
            'grand_total'             => $grand,
        ]);
    }

    /** Resolusi ongkir manual (net = gross − diskon). Ambil di toko → 0. */
    private function resolveShippingData(array $dto, string $deliveryMethod): array
    {
        if ($deliveryMethod === 'ambil_toko') {
            return ['gross' => 0, 'disc_type' => 'nominal', 'disc_value' => 0, 'net' => 0,
                    'courier_code' => null, 'service_name' => null];
        }

        $gross    = clean_number($dto['shipping_gross'] ?? 0);
        $discType = ($dto['shipping_discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
        $discVal  = clean_number($dto['shipping_discount_value'] ?? 0);
        $discAmt  = $discType === 'percent' ? $gross * ($discVal / 100) : $discVal;
        $net      = max(0, $gross - $discAmt);
        $courier  = trim((string) ($dto['courier_name'] ?? '')) ?: null;

        return [
            'gross'        => $gross,
            'disc_type'    => $discType,
            'disc_value'   => $discVal,
            'net'          => $net,
            'courier_code' => $courier ? 'manual' : null,
            'service_name' => $courier,
        ];
    }

    /** 'instant' belum didukung lewat AI → dipetakan ke 'kurir'. */
    private function normalizeDeliveryMethod(string $method): string
    {
        return $method === 'ambil_toko' ? 'ambil_toko' : 'kurir';
    }

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
