<?php

namespace App\Modules\Sales\Services;

use App\DTO\SalesInvoiceDTO;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class SalesInvoiceService
{
    public function createDraft(SalesInvoiceDTO $dto): SalesInvoice
    {
        return DB::transaction(function () use ($dto) {

            // =========================
            // VALIDASI QTY VS SO
            // =========================

            $hasSO = !empty($dto->sales_order_id);
            $so = null;

            if ($hasSO) {
                // 🔥 STRICT 1:1 LOCK: SO tidak boleh punya invoice AKTIF (Draft/Posted).
                //    Invoice yang sudah di-void diabaikan agar SO bisa di-invoice ulang
                //    untuk koreksi (qty_invoiced sudah dikembalikan saat void).
                $exists = \App\Models\SalesInvoice::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', '!=', 'void')
                    ->exists();
                if ($exists) {
                    throw new Exception("Sales Order ini sudah memiliki invoice.");
                }

                $so = SalesOrder::with('items')->findOrFail($dto->sales_order_id);
            }

            $hasSO = !empty($dto->sales_order_id) && $so !== null;
            $resolvedSoItemIds = [];

            foreach ($dto->items as $idx => $itemDTO) {
                $product = \App\Core\Inventory\Product::findOrFail($itemDTO->product_id);

                if ($hasSO) {
                    // 🔥 Auto-resolve sales_order_item_id jika tidak tersedia dari frontend
                    $resolvedSoItemId = $itemDTO->sales_order_item_id;
                    if (!$resolvedSoItemId) {
                        $soItemFallback = $so->items->firstWhere('product_id', $itemDTO->product_id);
                        if (!$soItemFallback) {
                            throw new Exception("Produk {$product->name} tidak ditemukan di Sales Order.");
                        }
                        $resolvedSoItemId = $soItemFallback->id;
                    }

                    // simpan untuk dipakai saat insert invoice item
                    $resolvedSoItemIds[$idx] = $resolvedSoItemId;

                    $soItem = SalesOrderItem::findOrFail($resolvedSoItemId);

                    $remaining = $soItem->qty - $soItem->qty_invoiced;

                    if ($itemDTO->qty > $remaining) {
                        throw new Exception("Qty {$product->name} melebihi sisa SO. Request QTY: {$itemDTO->qty}, Remaining: {$remaining}, SO QTY: {$soItem->qty}, Invoiced: {$soItem->qty_invoiced}");
                    }

                    // update qty invoiced
                    $soItem->qty_invoiced += $itemDTO->qty;
                    $soItem->save();
                }

                if ($product->type === 'bundle' || $product->sale_type === 'bundle') {
                    $components = $product->bundleItems;
                    if ($components->isEmpty()) {
                        $components = $product->bundleComponents;
                    }
                    if ($components->isEmpty()) {
                        throw new Exception("Produk bundle {$product->name} tidak memiliki komponen.");
                    }
                }
            }

            // =========================
            // HITUNG NILAI ITEM
            // =========================

            $subtotal = 0;
            $totalItemDiscount = 0;
            $totalPPN = 0;
            $totalPPH = 0;

            $calculatedItems = [];

            foreach ($dto->items as $idx => $itemDTO) {

                if ($itemDTO->qty <= 0) {
                    throw new Exception("Qty tidak boleh 0.");
                }

                $gross = round($itemDTO->qty * $itemDTO->unit_price);

                if ($itemDTO->discount_type === 'percent') {
                     $discountAmount = round(
                         $gross * ($itemDTO->discount_value / 100));
                } else {
                     // 🔥 FIXED: Diskon item nominal adalah PER UNIT
                     $discountAmount = round($itemDTO->qty * $itemDTO->discount_value);
                }

                $netBeforeTax = round(
                    $gross - $discountAmount);

                $ppnAmount = round(
                    $netBeforeTax * ($itemDTO->ppn_percent / 100));

                $pphAmount = round(
                    $netBeforeTax * ($itemDTO->pph_percent / 100));

                $subtotal += $netBeforeTax;
                $totalItemDiscount += $discountAmount;
                $totalPPN += $ppnAmount;
                $totalPPH += $pphAmount;

                $calculatedItems[] = [
                    'dto' => $itemDTO,
                    'resolved_so_item_id' => $resolvedSoItemIds[$idx] ?? $itemDTO->sales_order_item_id,
                    'subtotal' => $netBeforeTax,
                    'discount_amount' => $discountAmount,
                    'ppn_amount' => $ppnAmount,
                    'pph_amount' => $pphAmount,
                ];
            }

            // =========================
            // GLOBAL DISCOUNT
            // =========================

            $globalDiscountAmount = 0;

            $type = strtolower(trim((string) $dto->global_discount_type));
            $value = round((float) $dto->global_discount_value, 2);

            $globalDiscountAmount = 0;

            if ($value > 0) {
                if ($type === 'percent') {
                    $globalDiscountAmount = round(
                        $subtotal * ($value / 100));
                } else {
                    // treat as nominal by default
                    $globalDiscountAmount = round($value);
                }
            }

            $dpp = round($subtotal - $globalDiscountAmount);

            // PPN Indonesia → dari DPP
            $headerPPN = round($dpp * ($dto->ppn_percent / 100));
            $headerPPH = round($dpp * ($dto->pph_percent / 100));

            // Biaya admin marketplace mengurangi piutang/payout (bukan diskon penjualan).
            $marketplaceFee = round((float) $dto->marketplace_fee, 2);

            // Kode unik transfer toko online: ikut dari SO supaya faktur menagih persis
            // sebesar nominal yang ditransfer pembeli (lihat resolveUniqueCode()).
            $uniqueCode = $this->resolveUniqueCode($dto->sales_order_id);

            $grandTotal = round(
                $dpp
                + $headerPPN
                - $headerPPH
                + $dto->shipping_cost
                + $dto->additional_fee
                - $marketplaceFee) - $uniqueCode;

            if ($grandTotal < 0) {
                throw new Exception("Grand total tidak boleh negatif.");
            }

            $check = round(
                $subtotal
                - $globalDiscountAmount
                + $dto->shipping_cost
                + $dto->additional_fee
                - $marketplaceFee
                + $headerPPN
                - $headerPPH) - $uniqueCode;

            if ($check !== round($grandTotal)) {
                throw new Exception("Invoice draft calculation mismatch.");
            }

            $advanceApplied = 0;

            if ($dto->sales_order_id) {

                $totalAdvance = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('amount');

                // Kurangi uang muka yang SUDAH terpakai invoice lain (posted) dari SO sama,
                // supaya invoice ke-2+ tidak ikut menghitung ulang advance yang sama.
                $usedByOthers = \App\Models\SalesInvoice::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('advance_applied');

                $availableAdvance = max(0, $totalAdvance - $usedByOthers);
                $advanceApplied = min($availableAdvance, $grandTotal);
            }

            // =========================
            // SIMPAN HEADER
            // =========================

            // Marketplace: invoice_number ikut No. Pesanan (customer_po_number SO).
            $marketplaceRef = $so?->customer_po_number;

            $invoice = SalesInvoice::create([
                'invoice_number' => NumberGeneratorService::forCustomer('SI', $dto->customer_id, $marketplaceRef),
                'sales_order_id' => $dto->sales_order_id,
                'customer_id' => $dto->customer_id,
                'warehouse_id' => $dto->warehouse_id,
                'invoice_date' => $dto->invoice_date,

                'subtotal' => $subtotal,
                'global_discount_type' => $dto->global_discount_type,
                'global_discount_value' => $dto->global_discount_value,
                'global_discount_amount' => $globalDiscountAmount,
                'discount_total' => $totalItemDiscount + $globalDiscountAmount,

                'ppn_percent' => $dto->ppn_percent,
                'ppn_amount' => $headerPPN,

                'pph_percent' => $dto->pph_percent,
                'pph_amount' => $headerPPH,

                'shipping_cost' => $dto->shipping_cost,
                'additional_fee' => $dto->additional_fee,
                'marketplace_fee' => $marketplaceFee,

                'grand_total' => $grandTotal,
                'unique_code' => $uniqueCode,
                'advance_applied' => $advanceApplied,
                'total_cogs' => 0,
                'notes' => $dto->notes,
            ]);

            // =========================
            // SIMPAN ITEMS SNAPSHOT
            // =========================

            foreach ($calculatedItems as $item) {

                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'sales_order_item_id' => $item['resolved_so_item_id'] ?? $item['dto']->sales_order_item_id,
                    'product_id' => $item['dto']->product_id,
                    'description' => $item['dto']->description,
                    'item_type' => $item['dto']->item_type,
                    'qty' => round($item['dto']->qty, 4),
                    'unit_price' => round($item['dto']->unit_price, 2),
                    'discount_type' => $item['dto']->discount_type,
                    'discount_value' => round($item['dto']->discount_value, 2),
                    'discount_amount' => $item['discount_amount'],
                    'subtotal' => $item['subtotal'],
                    'ppn_percent' => $item['dto']->ppn_percent,
                    'ppn_amount' => $item['ppn_amount'],
                    'pph_percent' => $item['dto']->pph_percent,
                    'pph_amount' => $item['pph_amount'],
                    'cogs_unit' => 0,
                    'cogs_total' => 0,
                ]);
            }

            // Auto-rematch settlement marketplace yang pending.
            // Kalau customer ini marketplace & order_ref-nya match line draft yg unmatched, auto-set matched.
            if ($marketplaceRef) {
                try {
                    app(\App\Modules\Finance\Services\MarketplaceSettlementService::class)
                        ->autoRematchForOrderRef($dto->customer_id, $marketplaceRef);
                } catch (\Throwable $e) {
                    \Log::warning('Auto-rematch settlement gagal: ' . $e->getMessage(), [
                        'customer_id' => $dto->customer_id,
                        'order_ref'   => $marketplaceRef,
                    ]);
                }
            }

            return $invoice;
        });
    }

    /**
     * Kode unik pembayaran transfer toko online milik SO (Rp1–999).
     *
     * Pembeli mentransfer grand total SO yang SUDAH dikurangi kode unik, jadi faktur
     * harus ikut dikurangi — kalau tidak, tiap pesanan web menyisakan piutang receh.
     * Hanya dipakai SEKALI per SO: kalau sudah ada faktur lain (non-void) yang memakai
     * kode ini, faktur berikutnya (tagihan parsial) menagih penuh.
     */
    private function resolveUniqueCode(?int $salesOrderId): int
    {
        if (! $salesOrderId) {
            return 0;
        }

        $code = (int) (\App\Modules\Sales\Models\SalesOrder::where('id', $salesOrderId)->value('unique_code') ?? 0);
        if ($code <= 0) {
            return 0;
        }

        $alreadyUsed = SalesInvoice::where('sales_order_id', $salesOrderId)
            ->where('status', '!=', 'void')
            ->where('unique_code', '>', 0)
            ->exists();

        return $alreadyUsed ? 0 : $code;
    }

    public function updateDraft(SalesInvoice $invoice, SalesInvoiceDTO $dto): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $dto) {

            if ($invoice->status !== 'draft') {
                throw new Exception("Hanya draft invoice yang dapat diedit.");
            }

            // =========================
            // VALIDASI DATA LAMA & REVERSE QTY
            // =========================
            $hasSO = !empty($dto->sales_order_id);
            $so = null;

            if ($hasSO) {
                $so = SalesOrder::with('items')->findOrFail($dto->sales_order_id);

                // 🔥 PRE-VALIDATION: Pastikan data lama tidak corrupted
                foreach ($invoice->items as $oldItem) {
                    if ($oldItem->sales_order_item_id) {
                        $existsInSO = \App\Modules\Sales\Models\SalesOrderItem::where('id', $oldItem->sales_order_item_id)->exists();
                        if (!$existsInSO) {
                            throw new Exception("Invoice ID {$invoice->id} mengandung data SO Item (ID: {$oldItem->sales_order_item_id}) yang sudah tidak valid di database. Harap hapus invoice ini dan buat ulang.");
                        }
                        
                        // 🔥 SAFE REVERSE: Hanya kurangi jika item masih ada
                        $oldSoItem = \App\Modules\Sales\Models\SalesOrderItem::find($oldItem->sales_order_item_id);
                        if ($oldSoItem) {
                            $oldSoItem->qty_invoiced -= $oldItem->qty;
                            $oldSoItem->save();
                        }
                    }
                }

                // FRESH REFETCH
                $so->load('items');
            }

            $resolvedSoItemIds = [];

            foreach ($dto->items as $idx => $itemDTO) {
                $product = \App\Core\Inventory\Product::findOrFail($itemDTO->product_id);

                if ($hasSO) {
                    // 🔥 Auto-resolve sales_order_item_id jika tidak tersedia dari frontend
                    $resolvedSoItemId = $itemDTO->sales_order_item_id;
                    if (!$resolvedSoItemId) {
                        $soItemFallback = $so->items->firstWhere('product_id', $itemDTO->product_id);
                        if (!$soItemFallback) {
                            throw new Exception("Produk {$product->name} tidak ditemukan di Sales Order.");
                        }
                        $resolvedSoItemId = $soItemFallback->id;
                    }
                    
                    // 🔥 STRICT VALIDATION: Harus ada ID SO Item
                    if (!$resolvedSoItemId) {
                        throw new Exception("sales_order_item_id wajib untuk invoice dari SO (Produk: {$product->name})");
                    }

                    $resolvedSoItemIds[$idx] = $resolvedSoItemId;

                    $soItem = \App\Modules\Sales\Models\SalesOrderItem::findOrFail($resolvedSoItemId);

                    $remaining = $soItem->qty - $soItem->qty_invoiced;

                    if ($itemDTO->qty > $remaining) {
                        throw new Exception("Qty {$product->name} melebihi sisa SO.");
                    }

                    // update qty invoiced
                    $soItem->qty_invoiced += $itemDTO->qty;
                    $soItem->save();
                }
            }

            // =========================
            // HITUNG NILAI ITEM
            // =========================
            
            $subtotal = 0;
            $totalItemDiscount = 0;
            $totalPPN = 0;
            $totalPPH = 0;

            $calculatedItems = [];

            foreach ($dto->items as $idx => $itemDTO) {

                if ($itemDTO->qty <= 0) {
                    throw new Exception("Qty tidak boleh 0.");
                }

                $gross = round($itemDTO->qty * $itemDTO->unit_price);

                if ($itemDTO->discount_type === 'percent') {
                      $discountAmount = round(
                          $gross * ($itemDTO->discount_value / 100));
                 } else {
                      // 🔥 FIXED: Diskon item nominal adalah PER UNIT
                      $discountAmount = round($itemDTO->qty * $itemDTO->discount_value);
                 }

                $netBeforeTax = round(
                     $gross - $discountAmount);

                $ppnAmount = round(
                     $netBeforeTax * ($itemDTO->ppn_percent / 100));

                $pphAmount = round(
                     $netBeforeTax * ($itemDTO->pph_percent / 100));

                $subtotal += $netBeforeTax;
                $totalItemDiscount += $discountAmount;
                $totalPPN += $ppnAmount;
                $totalPPH += $pphAmount;

                $calculatedItems[] = [
                     'dto' => $itemDTO,
                     'resolved_so_item_id' => $resolvedSoItemIds[$idx] ?? $itemDTO->sales_order_item_id,
                     'subtotal' => $netBeforeTax,
                     'discount_amount' => $discountAmount,
                     'ppn_amount' => $ppnAmount,
                     'pph_amount' => $pphAmount,
                ];
            }

            // =========================
            // GLOBAL DISCOUNT
            // =========================

            $type = strtolower(trim((string) $dto->global_discount_type));
            $value = round((float) $dto->global_discount_value, 2);

            $globalDiscountAmount = 0;

            if ($value > 0) {
                 if ($type === 'percent') {
                     $globalDiscountAmount = round(
                         $subtotal * ($value / 100));
                 } else {
                     $globalDiscountAmount = round($value);
                 }
            }

            $dpp = round($subtotal - $globalDiscountAmount);

            // PPN Indonesia → dari DPP
            $headerPPN = round($dpp * ($dto->ppn_percent / 100));
            $headerPPH = round($dpp * ($dto->pph_percent / 100));

            // Biaya admin marketplace mengurangi piutang/payout (bukan diskon penjualan).
            $marketplaceFee = round((float) $dto->marketplace_fee, 2);

            // Kode unik dipertahankan saat faktur diedit ulang (nominal transfer pembeli
            // sudah terlanjur memakai angka ini).
            $uniqueCode = (int) ($invoice->unique_code ?? 0);

            $grandTotal = round(
                $dpp
                + $headerPPN
                - $headerPPH
                + $dto->shipping_cost
                + $dto->additional_fee
                - $marketplaceFee) - $uniqueCode;

            if ($grandTotal < 0) {
                throw new Exception("Grand total tidak boleh negatif.");
            }

            $check = round(
                $subtotal
                - $globalDiscountAmount
                + $dto->shipping_cost
                + $dto->additional_fee
                - $marketplaceFee
                + $headerPPN
                - $headerPPH) - $uniqueCode;

            if ($check !== round($grandTotal)) {
                throw new Exception("Invoice draft calculation mismatch.");
            }

            $advanceApplied = 0;

            if ($dto->sales_order_id) {

                $totalAdvance = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('amount');

                // Kurangi uang muka yang SUDAH terpakai invoice lain (posted) dari SO sama,
                // supaya invoice ke-2+ tidak ikut menghitung ulang advance yang sama.
                $usedByOthers = \App\Models\SalesInvoice::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('advance_applied');

                $availableAdvance = max(0, $totalAdvance - $usedByOthers);
                $advanceApplied = min($availableAdvance, $grandTotal);
            }

            // =========================
            // UPDATE HEADER
            // =========================

            $invoice->update([
                'sales_order_id' => $dto->sales_order_id,
                'customer_id' => $dto->customer_id,
                'warehouse_id' => $dto->warehouse_id,
                'invoice_date' => $dto->invoice_date,

                'subtotal' => $subtotal,
                'global_discount_type' => $dto->global_discount_type,
                'global_discount_value' => $dto->global_discount_value,
                'global_discount_amount' => $globalDiscountAmount,
                'discount_total' => $totalItemDiscount + $globalDiscountAmount,

                'ppn_percent' => $dto->ppn_percent,
                'ppn_amount' => $headerPPN,

                'pph_percent' => $dto->pph_percent,
                'pph_amount' => $headerPPH,

                'shipping_cost' => $dto->shipping_cost,
                'additional_fee' => $dto->additional_fee,
                'marketplace_fee' => $marketplaceFee,

                'grand_total' => $grandTotal,
                'advance_applied' => $advanceApplied,
                'notes' => $dto->notes,
            ]);

            // =========================
            // SIMPAN ITEMS SNAPSHOT
            // =========================
            
            $invoice->items()->delete();

            foreach ($calculatedItems as $item) {

                SalesInvoiceItem::create([
                    'sales_invoice_id' => $invoice->id,
                    'sales_order_item_id' => $item['resolved_so_item_id'] ?? $item['dto']->sales_order_item_id,
                    'product_id' => $item['dto']->product_id,
                    'description' => $item['dto']->description,
                    'item_type' => $item['dto']->item_type,
                    'qty' => round($item['dto']->qty, 4),
                    'unit_price' => round($item['dto']->unit_price, 2),
                    'discount_type' => $item['dto']->discount_type,
                    'discount_value' => round($item['dto']->discount_value, 2),
                    'discount_amount' => $item['discount_amount'],
                    'subtotal' => $item['subtotal'],
                    'ppn_percent' => $item['dto']->ppn_percent,
                    'ppn_amount' => $item['ppn_amount'],
                    'pph_percent' => $item['dto']->pph_percent,
                    'pph_amount' => $item['pph_amount'],
                    'cogs_unit' => 0,
                    'cogs_total' => 0,
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Void Faktur posted/paid: void semua SJ terkait (+balik stok), void jurnal Faktur &
     * jurnal marketplace (fee/settlement), kembalikan qty_invoiced ke SO, reset uang muka.
     * Sumber kebenaran void Faktur — dipanggil oleh DebugInvoiceController::void (manual) DAN
     * auto-void pembatalan Jubelio. Lempar RuntimeException untuk pelanggaran dependency.
     */
    public function voidPosted(SalesInvoice $invoice): void
    {
        $invoice->loadMissing(['items.product', 'delivery']);

        $statusVal = $invoice->status instanceof \App\Enums\InvoiceStatusEnum
            ? $invoice->status->value
            : $invoice->status;

        if (!in_array($statusVal, ['posted', 'paid'], true)) {
            throw new \RuntimeException('Hanya invoice berstatus posted/paid yang dapat di-void.');
        }

        // -- Dependency checks ------------------------------------------
        $activePayment = \App\Models\CustomerPaymentAllocation::where('invoice_id', $invoice->id)
            ->whereHas('payment', fn($q) => $q->where('status', 'posted'))
            ->with('payment')
            ->first();
        if ($activePayment) {
            $payNum = $activePayment->payment->payment_number ?? '#' . $activePayment->customer_payment_id;
            throw new \RuntimeException("Invoice tidak bisa di-void: masih ada Payment {$payNum} aktif. Void payment tersebut terlebih dahulu.");
        }

        $activeReturn = \App\Modules\Sales\Models\SalesReturn::where('invoice_id', $invoice->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeReturn) {
            throw new \RuntimeException("Invoice tidak bisa di-void: masih ada Retur {$activeReturn->return_number} aktif. Void retur tersebut terlebih dahulu.");
        }

        $activeWarranty = \App\Models\WarrantyOrder::where('invoice_id', $invoice->id)
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->first();
        if ($activeWarranty) {
            throw new \RuntimeException("Invoice tidak bisa di-void: masih ada Garansi {$activeWarranty->warranty_number} aktif. Void garansi tersebut terlebih dahulu.");
        }

        $activeBilling = \App\Models\CustomerBillingItem::where('invoice_id', $invoice->id)
            ->whereHas('billing', fn($q) => $q->whereNotIn('status', ['void', 'draft']))
            ->with('billing')
            ->first();
        if ($activeBilling) {
            $bilNum = $activeBilling->billing->billing_number ?? '#' . $activeBilling->billing_id;
            throw new \RuntimeException("Invoice tidak bisa di-void: masih ada Billing {$bilNum} aktif. Void billing tersebut terlebih dahulu.");
        }

        DB::transaction(function () use ($invoice) {
            // 1. Reverse stock via delivery. Satu invoice bisa punya BANYAK SJ
            //    (partial + auto-SJ sisa, Bagian A) → balik & void SEMUA yang posted.
            $deliveries = \App\Modules\Sales\Models\SalesDelivery::where('invoice_id', $invoice->id)
                ->where('status', 'posted')
                ->get();
            foreach ($deliveries as $delivery) {
                $this->reverseDeliveryStock($delivery);
                $delivery->status = 'void';
                if (\Illuminate\Support\Facades\Schema::hasColumn($delivery->getTable(), 'voided_at')) {
                    $delivery->voided_at = now();
                }
                $delivery->save();
            }

            // 2. Mark journal utama (sales_invoice) sebagai void
            \App\Core\Journal\Journal::where('reference_type', 'sales_invoice')
                ->where('reference_id', $invoice->id)
                ->update(['status' => 'void', 'voided_at' => now()]);

            // 2b. Void jurnal MARKETPLACE (fee + settlement) dari MarketplaceEngineService,
            //     dan reset flag agar invoice bisa diproses ulang bila di-post lagi.
            \App\Core\Journal\Journal::whereIn('reference_type', ['sales_invoice_fee', 'sales_invoice_settlement'])
                ->where('reference_id', $invoice->id)
                ->update(['status' => 'void', 'voided_at' => now()]);
            if (\Illuminate\Support\Facades\Schema::hasColumn($invoice->getTable(), 'marketplace_processed')) {
                $invoice->marketplace_processed = false;
            }

            // 3. Kembalikan qty_invoiced pada item SO (kalau dari SO), supaya SO tidak
            //    terkunci dan bisa di-invoice ulang untuk koreksi.
            foreach ($invoice->items as $invItem) {
                if ($invItem->sales_order_item_id) {
                    $soItem = \App\Modules\Sales\Models\SalesOrderItem::find($invItem->sales_order_item_id);
                    if ($soItem) {
                        $soItem->qty_invoiced = max(0, (float) $soItem->qty_invoiced - (float) $invItem->qty);
                        $soItem->save();
                    }
                }
            }

            // 4. Reset advance_applied (uang muka kembali ke saldo SO)
            $invoice->advance_applied = 0;

            // 5. Set status void
            $invoice->status = \App\Enums\InvoiceStatusEnum::VOID;
            $invoice->save();
        });
    }

    /**
     * Reverse stok yang dikonsumsi saat delivery posted.
     * Mencatat ledger qty_in untuk mengembalikan saldo stok, dan
     * membuat StockLayer baru sebagai stok kembali (FIFO order tetap terjaga).
     */
    protected function reverseDeliveryStock(\App\Modules\Sales\Models\SalesDelivery $delivery): void
    {
        $delivery->loadMissing('items.product');
        $engine = app(\App\Core\Inventory\InventoryEngine::class);

        foreach ($delivery->items as $item) {
            $product = $item->product;
            if (!$product || in_array($product->sale_type ?? null, ['service', 'non_stock'], true)) {
                continue;
            }
            if ((float) $item->qty <= 0) {
                continue;
            }

            // Cari InventoryCostLayer hasil consume saat delivery posted
            $consumeLayers = \App\Models\InventoryCostLayer::where('product_id', $item->product_id)
                ->where('reference_type', 'sales')
                ->where('reference_id', $delivery->id)
                ->where('qty_out', '>', 0)
                ->get();

            $totalQty = (float) $consumeLayers->sum('qty_out');
            if ($totalQty <= 0) {
                $totalQty = (float) $item->qty;
            }

            // Restore: catat ledger qty_in dan buat StockLayer kembali (avg cost)
            $avgCost = 0;
            if ($consumeLayers->count() > 0 && $totalQty > 0) {
                $totalCost = $consumeLayers->sum(fn($l) => (float) $l->qty_out * (float) $l->unit_cost);
                $avgCost = $totalCost / $totalQty;
            } elseif ((float) $item->qty > 0 && (float) ($item->cogs_total ?? 0) > 0) {
                $avgCost = (float) $item->cogs_total / (float) $item->qty;
            }

            $engine->ledger(
                $item->product_id,
                $delivery->warehouse_id,
                $totalQty,
                0,
                'sales_void',
                $delivery->delivery_number,
                'Void delivery ' . $delivery->delivery_number,
                $delivery->id
            );

            // Catat balik di FIFO sebagai layer baru (qty_remaining penuh)
            \App\Core\Inventory\StockLayer::create([
                'product_id'   => $item->product_id,
                'warehouse_id' => $delivery->warehouse_id,
                'qty_in'       => $totalQty,
                'qty_remaining'=> $totalQty,
                'unit_cost'    => $avgCost,
                'source_type'  => 'sales_void',
                'source_id'    => $delivery->id,
            ]);

            \App\Models\InventoryCostLayer::create([
                'product_id'     => $item->product_id,
                'qty_in'         => $totalQty,
                'qty_balance'    => $totalQty,
                'unit_cost'      => $avgCost,
                'reference_type' => 'sales_void',
                'reference_id'   => $delivery->id,
            ]);
        }
    }

}
