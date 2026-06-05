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

                $gross = round($itemDTO->qty * $itemDTO->unit_price, 2);

                if ($itemDTO->discount_type === 'percent') {
                     $discountAmount = round(
                         $gross * ($itemDTO->discount_value / 100),
                         2
                     );
                } else {
                     // 🔥 FIXED: Diskon item nominal adalah PER UNIT
                     $discountAmount = round($itemDTO->qty * $itemDTO->discount_value, 2);
                }

                $netBeforeTax = round(
                    $gross - $discountAmount,
                    2
                );

                $ppnAmount = round(
                    $netBeforeTax * ($itemDTO->ppn_percent / 100),
                    2
                );

                $pphAmount = round(
                    $netBeforeTax * ($itemDTO->pph_percent / 100),
                    2
                );

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
                        $subtotal * ($value / 100),
                        2
                    );
                } else {
                    // treat as nominal by default
                    $globalDiscountAmount = $value;
                }
            }

            $dpp = round($subtotal - $globalDiscountAmount, 2);

            // PPN Indonesia → dari DPP
            $headerPPN = round($dpp * ($dto->ppn_percent / 100), 2);
            $headerPPH = round($dpp * ($dto->pph_percent / 100), 2);

            $grandTotal = round(
                $dpp
                + $headerPPN
                - $headerPPH
                + $dto->shipping_cost
                + $dto->additional_fee,
                2
            );

            if ($grandTotal < 0) {
                throw new Exception("Grand total tidak boleh negatif.");
            }

            $check = round(
                $subtotal
                - $globalDiscountAmount
                + $dto->shipping_cost
                + $dto->additional_fee
                + $headerPPN
                - $headerPPH,
                2
            );

            if ($check !== round($grandTotal, 2)) {
                throw new Exception("Invoice draft calculation mismatch.");
            }

            $advanceApplied = 0;

            if ($dto->sales_order_id) {

                $totalAdvance = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('amount');

                $advanceApplied = min($totalAdvance, $grandTotal);
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

                'grand_total' => $grandTotal,
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

                $gross = round($itemDTO->qty * $itemDTO->unit_price, 2);

                if ($itemDTO->discount_type === 'percent') {
                      $discountAmount = round(
                          $gross * ($itemDTO->discount_value / 100),
                          2
                      );
                 } else {
                      // 🔥 FIXED: Diskon item nominal adalah PER UNIT
                      $discountAmount = round($itemDTO->qty * $itemDTO->discount_value, 2);
                 }

                $netBeforeTax = round(
                     $gross - $discountAmount,
                     2
                );

                $ppnAmount = round(
                     $netBeforeTax * ($itemDTO->ppn_percent / 100),
                     2
                );

                $pphAmount = round(
                     $netBeforeTax * ($itemDTO->pph_percent / 100),
                     2
                );

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
                         $subtotal * ($value / 100),
                         2
                     );
                 } else {
                     $globalDiscountAmount = $value;
                 }
            }

            $dpp = round($subtotal - $globalDiscountAmount, 2);

            // PPN Indonesia → dari DPP
            $headerPPN = round($dpp * ($dto->ppn_percent / 100), 2);
            $headerPPH = round($dpp * ($dto->pph_percent / 100), 2);

            $grandTotal = round(
                $dpp
                + $headerPPN
                - $headerPPH
                + $dto->shipping_cost
                + $dto->additional_fee,
                2
            );

            if ($grandTotal < 0) {
                throw new Exception("Grand total tidak boleh negatif.");
            }

            $check = round(
                $subtotal
                - $globalDiscountAmount
                + $dto->shipping_cost
                + $dto->additional_fee
                + $headerPPN
                - $headerPPH,
                2
            );

            if ($check !== round($grandTotal, 2)) {
                throw new Exception("Invoice draft calculation mismatch.");
            }

            $advanceApplied = 0;

            if ($dto->sales_order_id) {

                $totalAdvance = \App\Modules\Sales\Models\SalesAdvance::where('sales_order_id', $dto->sales_order_id)
                    ->where('status', 'posted')
                    ->sum('amount');

                $advanceApplied = min($totalAdvance, $grandTotal);
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

}
