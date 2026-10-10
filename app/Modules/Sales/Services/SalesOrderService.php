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

            // Nomor eksternal (mis. toko online / marketplace) boleh dioper agar SO memakai
            // nomor itu — memudahkan audit (SO = INV = nomor pesanan).
            $overrideNumber = trim((string) ($dto['order_number'] ?? ''));

            $so = SalesOrder::create([
                'order_number'    => $overrideNumber !== '' ? $overrideNumber : NumberGeneratorService::forCustomer('SO', $customerId, null),
                'customer_po_number' => $dto['customer_po_number'] ?? null,
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
                // Ikut dipertahankan, kalau tidak revisi draft menurunkan SO hasil Cek
                // Ongkir jadi "kurir manual" dan resinya tak bisa dipesan lagi.
                $dto['shipping_provider']       = $so->shipping_provider;
                $dto['shipping_courier_code']   = $so->shipping_courier_code;
                $dto['shipping_service_code']   = $so->shipping_service_code;
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
        // Kode unik pembayaran transfer bank (toko online) tetap dikurangkan saat
        // total dihitung ulang, agar tidak hilang bila SO diedit setelah dibuat.
        $uniqueCode = (int) ($so->unique_code ?? 0);
        $grand    = round($dpp + $ship['net']) - $uniqueCode;

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
            'shipping_provider'       => $ship['provider'],
            'shipping_courier_code'   => $ship['courier_code'],
            'shipping_service_code'   => $ship['service_code'],
            'shipping_service_name'   => $ship['service_name'],
            'grand_total'             => $grand,
        ]);
    }

    /**
     * Resolusi ongkir (net = gross − diskon). Ambil di toko → 0.
     *
     * Dua asal ongkir dibedakan di sini:
     *  - Tarif agregator (Cek Ongkir di ERP / etalase web) — membawa provider + kode kurir
     *    + kode layanan. Ketiganya WAJIB disimpan, karena itulah yang dibaca
     *    ShipmentBookingService untuk tahu resi harus dipesan ke provider yang mana.
     *  - Ongkir diketik manual (asisten AI / operator) — tidak punya kode apa pun,
     *    ditandai courier_code 'manual' dan resi diisi tangan.
     * Sebelum ini semua ongkir dianggap manual, sehingga pesanan web berujung
     * provider NULL → booking jatuh ke fallback Biteship yang sudah dimatikan.
     */
    private function resolveShippingData(array $dto, string $deliveryMethod): array
    {
        if ($deliveryMethod === 'ambil_toko') {
            return ['gross' => 0, 'disc_type' => 'nominal', 'disc_value' => 0, 'net' => 0,
                    'provider' => null, 'courier_code' => null, 'service_code' => null, 'service_name' => null];
        }

        $gross    = clean_number($dto['shipping_gross'] ?? 0);
        $discType = ($dto['shipping_discount_type'] ?? 'nominal') === 'percent' ? 'percent' : 'nominal';
        $discVal  = clean_number($dto['shipping_discount_value'] ?? 0);
        $discAmt  = $discType === 'percent' ? $gross * ($discVal / 100) : $discVal;
        $net      = max(0, $gross - $discAmt);
        $courier  = trim((string) ($dto['courier_name'] ?? '')) ?: null;

        // Ketiganya harus lengkap baru dianggap tarif agregator. Sebagian saja justru
        // berbahaya: booking lolos cek provider lalu mentok "layanan belum lengkap".
        $provider    = trim((string) ($dto['shipping_provider'] ?? '')) ?: null;
        $courierCode = trim((string) ($dto['shipping_courier_code'] ?? '')) ?: null;
        $serviceCode = trim((string) ($dto['shipping_service_code'] ?? '')) ?: null;
        $fromAggregator = $provider && $courierCode && $serviceCode;

        return [
            'gross'        => $gross,
            'disc_type'    => $discType,
            'disc_value'   => $discVal,
            'net'          => $net,
            'provider'     => $fromAggregator ? $provider : null,
            'courier_code' => $fromAggregator ? $courierCode : ($courier ? 'manual' : null),
            'service_code' => $fromAggregator ? $serviceCode : null,
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
     * Alasan SO tidak bisa di-void (dokumen turunan masih aktif), atau null bila boleh.
     * $ignorePayments: dipakai Ganti Pesanan — DP-nya dipindah ke SO pengganti, bukan di-void.
     */
    public function voidBlocker(SalesOrder $so, bool $ignorePayments = false): ?string
    {
        if ($so->status !== SalesOrderStatus::CONFIRMED->value) {
            return 'Hanya SO confirmed yang bisa di-void';
        }

        $activeInvoice = \App\Models\SalesInvoice::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->first();
        if ($activeInvoice) {
            return "SO tidak bisa di-void: masih ada Invoice {$activeInvoice->invoice_number} aktif. Void invoice tersebut terlebih dahulu.";
        }

        $activeDelivery = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->first();
        if ($activeDelivery) {
            return "SO tidak bisa di-void: masih ada Surat Jalan {$activeDelivery->delivery_number} aktif. Void surat jalan tersebut terlebih dahulu.";
        }

        if (!$ignorePayments) {
            $activePayment = \App\Models\CustomerPaymentAllocation::where('sales_order_id', $so->id)
                ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
                ->with('payment')
                ->first();
            if ($activePayment) {
                $payNum = $activePayment->payment->payment_number ?? '#' . $activePayment->customer_payment_id;
                return "SO tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.";
            }
        }

        $activeReturn = \App\Modules\Sales\Models\SalesReturn::where('sales_order_id', $so->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeReturn) {
            return "SO tidak bisa di-void: masih ada Retur {$activeReturn->return_number} aktif. Void retur tersebut terlebih dahulu.";
        }

        // Biaya pesanan yang sudah dibayar akan menggantung di 1204 selamanya kalau SO-nya batal.
        $activeCost = app(SalesOrderCostService::class)->linesForOrder($so->id)->first();
        if ($activeCost) {
            $cdNum = $activeCost->disbursement->number ?? '#' . $activeCost->cash_disbursement_id;
            return "SO tidak bisa di-void: masih ada Biaya Pesanan di Pengeluaran {$cdNum}. Void/hapus pengeluaran tersebut atau lepaskan tautan SO-nya terlebih dahulu.";
        }

        return null;
    }

    /**
     * Void SO confirmed yang sudah lolos voidBlocker(): lepas reservasi stok, batalkan OP
     * auto-preorder, set status void (PaymentLinkDocumentObserver ikut mematikan tautan
     * Midtrans-nya), dan kembalikan Penawaran sumber ke draft bila tak dirujuk SO lain.
     *
     * @return string[] nomor OP yang TIDAK bisa dibatalkan (sudah dikerjakan).
     */
    public function voidConfirmed(SalesOrder $so): array
    {
        // Release stock reservations
        StockReservation::where('sales_order_id', $so->id)
            ->update(['status' => 'cancelled']);
        // Mass-update tidak memicu observer reservasi → tandai manual untuk push ke Jubelio.
        $this->flagJubelioStockPending($so->id);

        $gagalBatal = $this->cancelAutoPreorderProductions($so);

        $so->status = 'void';
        $so->save();

        // Kalau SO ini berasal dari Quotation, kembalikan status Quotation ke
        // 'draft' agar bisa di-convert ulang. Skip kalau masih ada SO lain
        // yang aktif merujuk ke Quotation yang sama.
        if ($so->quotation_id) {
            $stillReferenced = SalesOrder::where('quotation_id', $so->quotation_id)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->where('id', '!=', $so->id)
                ->exists();
            if (!$stillReferenced) {
                \App\Models\SalesQuotation::where('id', $so->quotation_id)
                    ->where('status', 'converted')
                    ->update(['status' => 'draft']);
            }
        }

        return $gagalBatal;
    }

    /**
     * Tandai produk yang reservasinya berubah karena void/hapus SO agar didorong ulang ke
     * Jubelio. Dipakai pada jalur mass-update reservasi (yang melewati StockReservationObserver).
     * Produk komponen bundle ikut dipanggil lewat reservasinya; bundle induk ditandai juga.
     */
    public function flagJubelioStockPending(int $salesOrderId): void
    {
        $productIds = StockReservation::where('sales_order_id', $salesOrderId)
            ->pluck('product_id')->unique()->all();
        if (empty($productIds)) {
            return;
        }

        Product::whereIn('id', $productIds)
            ->where('sync_to_jubelio', true)
            ->update(['jubelio_sync_pending' => true]);

        $bundleIds = \App\Core\Inventory\BundleComponent::whereIn('component_product_id', $productIds)
            ->pluck('bundle_product_id');
        if ($bundleIds->isEmpty()) {
            $bundleIds = \App\Core\Inventory\ProductBundle::whereIn('component_product_id', $productIds)
                ->pluck('bundle_product_id');
        }
        if ($bundleIds->isNotEmpty()) {
            Product::whereIn('id', $bundleIds)
                ->where('sync_to_jubelio', true)
                ->update(['jubelio_sync_pending' => true]);
        }
    }

    /**
     * Batalkan order produksi auto-preorder milik SO yang di-void.
     *
     * @return string[] nomor OP yang TIDAK bisa dibatalkan (sudah dikerjakan / terlibat merge).
     */
    public function cancelAutoPreorderProductions(SalesOrder $so): array
    {
        $gagal = [];

        $pos = \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $so->id)
            ->where('created_via', 'auto_preorder')
            ->whereNotIn('status', ['cancelled', 'finalized'])
            ->get();

        foreach ($pos as $po) {
            // Dulu di sini hanya `status === 'draft'` yang dibatalkan. Padahal OP preorder
            // LAHIR langsung 'confirmed' (soft-confirm di PreorderAutoProductionService),
            // jadi cabang itu praktis tak pernah jalan: semua OP jatuh ke else dan cuma
            // ditulis ke log. Akibatnya OP yang masih antre tetap dikerjakan setelah
            // pesanannya batal, dan barangnya menumpuk jadi deadstock.
            //
            // ProductionOrderService::cancel() sudah punya guard yang benar — menolak OP
            // yang sudah mulai dikerjakan atau yang terlibat penggabungan, dan mengembalikan
            // material yang terlanjur dikonsumsi. Yang gagal dibatalkan tetap dicatat, bukan
            // digagalkan: void SO tidak boleh batal hanya karena produksinya sudah jalan.
            try {
                app(\App\Modules\Production\Services\ProductionOrderService::class)->cancel($po->id);
                $po->forceFill([
                    'notes' => trim(($po->notes ?? '') . "\n[Auto-cancel: SO {$so->order_number} di-void]"),
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('PO auto_preorder tidak bisa dibatalkan saat SO di-void', [
                    'production_order_id' => $po->id,
                    'order_number'        => $po->order_number,
                    'status'              => $po->status,
                    'sales_order_id'      => $so->id,
                    'message'             => $e->getMessage(),
                ]);
                $gagal[] = $po->order_number;
            }
        }

        return $gagal;
    }

    /**
     * Booking code Ambil di Toko: 4 angka acak (0000–9999) agar mudah diinput.
     * Retensi 1 tahun — kode hanya dianggap "terpakai" bila ada SO dengan kode
     * sama yang dibuat dalam 12 bulan terakhir; kode lebih lama bebas dipakai lagi.
     */
    public function generatePickupCode(): string
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
