<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseInvoiceExpense;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use DomainException;

class PurchaseInvoiceService
{
    public function generateNumber(): string
    {
        $prefix = 'PINV/' . now()->format('Y/m') . '/';
        // Pakai MAX nomor existing dengan prefix yang sama, bukan count by date.
        // count() gampang bocor: invoice di-back-date / di-hapus → nomor duplicate.
        $latest = PurchaseInvoice::where('invoice_number', 'LIKE', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {

            $items = $data['items'] ?? [];
            $expenses = $data['expenses'] ?? [];

            if (empty($items)) {
                throw new DomainException('Invoice harus punya minimal 1 item.');
            }

            // Validate PO remaining qty
            if (!empty($data['purchase_order_id'])) {
                $this->validateAgainstPO($data['purchase_order_id'], $items);
            }

            // SAFETY NET biaya PO: form kadang men-drop baris "Biaya Tambahan" saat isi
            // ulang item dari PO (mis. re-pick PO, atau resubmit setelah validasi gagal).
            // Akibatnya biaya nyata (mis. Biaya Layanan/admin Shopee) hilang dari faktur →
            // total faktur < DP → sisa DP nyangkut permanen di 1107. Kalau faktur dibuat
            // dari PO tapi TANPA baris biaya, tarik biaya dari PO — sekali saja (hanya bila
            // belum ada faktur non-void lain dari PO ini yang sudah membawa biaya).
            if (empty($expenses) && !empty($data['purchase_order_id'])) {
                $expenses = $this->inheritPoExpenses((int) $data['purchase_order_id']);
            }

            [$totals, $itemCalcs] = $this->calculateTotals(
                $items,
                $expenses,
                (float) ($data['ppn_percent'] ?? 0),
                $data['global_discount_type'] ?? null,
                (float) ($data['global_discount_value'] ?? 0)
            );

            $invoiceDate = $data['invoice_date'];
            $supplier = \App\Models\Supplier::find($data['supplier_id']);
            $dueDate = $data['due_date'] ?? null;
            if (!$dueDate && $supplier && $supplier->payment_term_days) {
                $dueDate = \Carbon\Carbon::parse($invoiceDate)->addDays((int) $supplier->payment_term_days)->toDateString();
            }

            $invoice = PurchaseInvoice::create([
                'invoice_number' => $data['invoice_number'] ?? $this->generateNumber(),
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'global_discount_type' => $data['global_discount_type'] ?? null,
                'global_discount_value' => (float) ($data['global_discount_value'] ?? 0),
                'global_discount_amount' => $totals['global_discount_amount'],
                'expense_capitalized_total' => $totals['expense_capitalized_total'],
                'expense_direct_total' => $totals['expense_direct_total'],
                'ppn_percent' => (float) ($data['ppn_percent'] ?? 0),
                'ppn_amount' => $totals['ppn_amount'],
                'total' => $totals['total'],
                'paid_amount' => 0,
                'outstanding_amount' => $totals['total'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemCalcs as $idx => $calc) {
                $i = $items[$idx];
                $isAsset = (bool) ($i['is_asset'] ?? false);
                $assetName = $isAsset ? ($i['asset_name'] ?? $i['description'] ?? null) : null;
                if ($isAsset) {
                    if (empty($i['asset_category_id'])) {
                        throw new DomainException('Baris aset wajib pilih kategori.');
                    }
                    if (empty($assetName)) {
                        throw new DomainException('Baris aset wajib isi nama aset / deskripsi.');
                    }
                }
                PurchaseInvoiceItem::create([
                    'purchase_invoice_id' => $invoice->id,
                    'purchase_order_item_id' => $i['purchase_order_item_id'] ?? null,
                    'product_id' => $isAsset ? null : ($i['product_id'] ?? null),
                    'is_asset' => $isAsset,
                    'asset_category_id' => $isAsset ? (int) $i['asset_category_id'] : null,
                    'asset_name' => $assetName,
                    'description' => $i['description'] ?? null,
                    'qty' => $i['qty'],
                    'unit' => $i['unit'] ?? null,
                    'price' => $i['price'],
                    'discount_type' => $i['discount_type'] ?? 'nominal',
                    'discount_value' => (float) ($i['discount_value'] ?? 0),
                    'discount' => $calc['line_discount'],
                    'subtotal' => $calc['line_subtotal'],
                    'landed_cost_share' => $calc['landed_share'],
                    'final_unit_cost' => $calc['final_unit_cost'],
                ]);
            }

            foreach ($expenses as $e) {
                PurchaseInvoiceExpense::create([
                    'purchase_invoice_id' => $invoice->id,
                    'account_id' => $e['account_id'],
                    'description' => $e['description'] ?? null,
                    'amount' => $e['amount'],
                    'mode' => $e['mode'],
                ]);
            }

            return $invoice;
        });
    }

    public function update(PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new DomainException('Hanya invoice berstatus draft yang boleh diedit.');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $invoice->items()->delete();
            $invoice->expenses()->delete();

            $items = $data['items'] ?? [];
            $expenses = $data['expenses'] ?? [];

            if (!empty($data['purchase_order_id'])) {
                $this->validateAgainstPO($data['purchase_order_id'], $items, $invoice->id);
            }

            [$totals, $itemCalcs] = $this->calculateTotals(
                $items,
                $expenses,
                (float) ($data['ppn_percent'] ?? 0),
                $data['global_discount_type'] ?? null,
                (float) ($data['global_discount_value'] ?? 0)
            );

            $invoice->update([
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'global_discount_type' => $data['global_discount_type'] ?? null,
                'global_discount_value' => (float) ($data['global_discount_value'] ?? 0),
                'global_discount_amount' => $totals['global_discount_amount'],
                'expense_capitalized_total' => $totals['expense_capitalized_total'],
                'expense_direct_total' => $totals['expense_direct_total'],
                'ppn_percent' => (float) ($data['ppn_percent'] ?? 0),
                'ppn_amount' => $totals['ppn_amount'],
                'total' => $totals['total'],
                'outstanding_amount' => $totals['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($itemCalcs as $idx => $calc) {
                $i = $items[$idx];
                $isAsset = (bool) ($i['is_asset'] ?? false);
                $assetName = $isAsset ? ($i['asset_name'] ?? $i['description'] ?? null) : null;
                if ($isAsset) {
                    if (empty($i['asset_category_id'])) {
                        throw new DomainException('Baris aset wajib pilih kategori.');
                    }
                    if (empty($assetName)) {
                        throw new DomainException('Baris aset wajib isi nama aset / deskripsi.');
                    }
                }
                PurchaseInvoiceItem::create([
                    'purchase_invoice_id' => $invoice->id,
                    'purchase_order_item_id' => $i['purchase_order_item_id'] ?? null,
                    'product_id' => $isAsset ? null : ($i['product_id'] ?? null),
                    'is_asset' => $isAsset,
                    'asset_category_id' => $isAsset ? (int) $i['asset_category_id'] : null,
                    'asset_name' => $assetName,
                    'description' => $i['description'] ?? null,
                    'qty' => $i['qty'],
                    'unit' => $i['unit'] ?? null,
                    'price' => $i['price'],
                    'discount_type' => $i['discount_type'] ?? 'nominal',
                    'discount_value' => (float) ($i['discount_value'] ?? 0),
                    'discount' => $calc['line_discount'],
                    'subtotal' => $calc['line_subtotal'],
                    'landed_cost_share' => $calc['landed_share'],
                    'final_unit_cost' => $calc['final_unit_cost'],
                ]);
            }

            foreach ($expenses as $e) {
                PurchaseInvoiceExpense::create([
                    'purchase_invoice_id' => $invoice->id,
                    'account_id' => $e['account_id'],
                    'description' => $e['description'] ?? null,
                    'amount' => $e['amount'],
                    'mode' => $e['mode'],
                ]);
            }

            return $invoice;
        });
    }

    public function destroy(PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new DomainException('Hanya invoice draft yang bisa dihapus.');
        }
        DB::transaction(function () use ($invoice) {
            $invoice->items()->delete();
            $invoice->expenses()->delete();
            $invoice->delete();
        });
    }

    /**
     * Ambil baris biaya dari PO untuk dibawa ke faktur — hanya bila belum ada faktur
     * non-void lain dari PO yang sama yang sudah membawa biaya (cegah dobel saat
     * faktur parsial). Kembalikan array kosong bila tak perlu / tak ada.
     */
    protected function inheritPoExpenses(int $poId): array
    {
        $po = PurchaseOrder::with('expenses')->find($poId);
        if (!$po || $po->expenses->isEmpty()) {
            return [];
        }

        $alreadyCarried = PurchaseInvoiceExpense::whereHas('purchaseInvoice', function ($q) use ($poId) {
            $q->where('purchase_order_id', $poId)->where('status', '!=', 'void');
        })->exists();
        if ($alreadyCarried) {
            return [];
        }

        return $po->expenses->map(fn ($e) => [
            'account_id'  => $e->account_id,
            'description' => $e->description,
            'amount'      => (float) $e->amount,
            'mode'        => $e->mode,
        ])->all();
    }

    /**
     * Validate items against PO remaining qty.
     */
    protected function validateAgainstPO(int $poId, array $items, ?int $excludeInvoiceId = null): void
    {
        $po = PurchaseOrder::with('items')->find($poId);
        if (!$po) {
            throw new DomainException('PO tidak ditemukan.');
        }
        if ($po->status !== 'posted') {
            throw new DomainException('PO harus berstatus posted untuk di-invoice.');
        }

        // Aggregate qty per po_item_id from incoming items
        $byPoItem = [];
        foreach ($items as $i) {
            if (!empty($i['purchase_order_item_id'])) {
                $key = (int) $i['purchase_order_item_id'];
                $byPoItem[$key] = ($byPoItem[$key] ?? 0) + (float) $i['qty'];
            }
        }

        foreach ($byPoItem as $poItemId => $qty) {
            $poItem = $po->items->firstWhere('id', $poItemId);
            if (!$poItem) {
                throw new DomainException("PO item ID $poItemId bukan milik PO ini.");
            }
            $remaining = (float) $poItem->qty - (float) $poItem->qty_invoiced;
            if ($qty > $remaining + 0.0001) {
                throw new DomainException("Qty melebihi sisa PO untuk produk ID {$poItem->product_id} (sisa: $remaining).");
            }
        }
    }

    /**
     * Hitung discount + subtotal per line dari discount_type/value (sama dengan PO service).
     */
    protected function calcLine(array $i): array
    {
        $qty = (float) ($i['qty'] ?? 0);
        $price = (float) ($i['price'] ?? 0);
        $gross = $qty * $price;
        $type = $i['discount_type'] ?? 'nominal';
        $value = (float) ($i['discount_value'] ?? 0);
        $discount = $type === 'percent' ? round($gross * $value / 100, 2) : round($value, 2);
        $subtotal = round($gross - $discount, 2);
        return ['discount' => $discount, 'subtotal' => $subtotal];
    }

    /**
     * Compute landed cost allocation, totals.
     * Returns [$totals, $itemCalcs] where itemCalcs is indexed parallel to $items.
     */
    protected function calculateTotals(array $items, array $expenses, float $ppnPercent, ?string $gdiscType = null, float $gdiscValue = 0): array
    {
        $itemSubtotals = [];
        $itemDiscounts = [];
        $sumItemSubtotal = 0;
        foreach ($items as $i) {
            $line = $this->calcLine($i);
            $itemSubtotals[] = $line['subtotal'];
            $itemDiscounts[] = $line['discount'];
            $sumItemSubtotal += $line['subtotal'];
        }

        // Global discount (sama formula seperti PO)
        $globalDiscAmount = 0;
        if ($gdiscType === 'percent' && $gdiscValue > 0) {
            $globalDiscAmount = round($sumItemSubtotal * $gdiscValue / 100, 2);
        } elseif ($gdiscType === 'nominal' && $gdiscValue > 0) {
            $globalDiscAmount = round($gdiscValue, 2);
        }

        $expenseCapitalized = 0;
        $expenseDirect = 0;
        foreach ($expenses as $e) {
            if (($e['mode'] ?? null) === 'capitalized') {
                $expenseCapitalized += (float) $e['amount'];
            } else {
                $expenseDirect += (float) $e['amount'];
            }
        }

        $itemCalcs = [];
        foreach ($items as $idx => $i) {
            $lineSubtotal = $itemSubtotals[$idx];
            $share = 0;
            if ($sumItemSubtotal > 0 && $expenseCapitalized > 0) {
                $share = round($lineSubtotal / $sumItemSubtotal * $expenseCapitalized, 2);
            }
            // Distribusikan diskon global proporsional ke setiap line — mengurangi final cost.
            $gdiscShare = 0;
            if ($sumItemSubtotal > 0 && $globalDiscAmount > 0) {
                $gdiscShare = round($lineSubtotal / $sumItemSubtotal * $globalDiscAmount, 2);
            }
            $qty = (float) $i['qty'];
            $finalUnitCost = $qty > 0 ? round(($lineSubtotal - $gdiscShare + $share) / $qty, 4) : 0;
            $itemCalcs[$idx] = [
                'line_subtotal' => $lineSubtotal,
                'line_discount' => $itemDiscounts[$idx],
                'landed_share' => $share,
                'final_unit_cost' => $finalUnitCost,
            ];
        }

        $subtotal = round($sumItemSubtotal, 2);
        $discountTotal = round(array_sum($itemDiscounts), 2);

        // Tax base = subtotal − global discount + expense (cap + direct)
        $taxBase = $sumItemSubtotal - $globalDiscAmount + $expenseCapitalized + $expenseDirect;
        $ppnAmount = round($taxBase * $ppnPercent / 100, 2);
        $total = round($taxBase + $ppnAmount, 2);

        return [
            [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'global_discount_amount' => $globalDiscAmount,
                'expense_capitalized_total' => round($expenseCapitalized, 2),
                'expense_direct_total' => round($expenseDirect, 2),
                'ppn_amount' => $ppnAmount,
                'total' => $total,
            ],
            $itemCalcs,
        ];
    }
}
