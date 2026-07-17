<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrderExpense;
use Illuminate\Support\Facades\DB;
use DomainException;

class PurchaseOrderService
{
    public function generateNumber(): string
    {
        $prefix = 'PO/' . now()->format('Y/m') . '/';
        // Pakai MAX nomor existing dengan prefix yang sama, bukan count by date.
        $latest = PurchaseOrder::where('po_number', 'LIKE', $prefix . '%')
            ->orderByDesc('po_number')
            ->value('po_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function createDraft(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {

            $items = $data['items'] ?? [];
            $expenses = $data['expenses'] ?? [];
            if (empty($items)) {
                throw new DomainException('Purchase Order harus punya minimal 1 item.');
            }

            $totals = $this->calculateTotals($items, $expenses,
                (float) ($data['ppn_percent'] ?? 0),
                $data['global_discount_type'] ?? null,
                (float) ($data['global_discount_value'] ?? 0)
            );

            $po = PurchaseOrder::create([
                'po_number' => $data['po_number'] ?? $this->generateNumber(),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'po_date' => $data['po_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['line_discount_total'],
                'global_discount_type' => $data['global_discount_type'] ?? null,
                'global_discount_value' => (float) ($data['global_discount_value'] ?? 0),
                'global_discount_amount' => $totals['global_discount_amount'],
                'expense_capitalized_total' => $totals['expense_capitalized_total'],
                'expense_direct_total' => $totals['expense_direct_total'],
                'ppn_percent' => (float) ($data['ppn_percent'] ?? 0),
                'ppn_amount' => $totals['ppn_amount'],
                'total' => $totals['total'],
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            foreach ($items as $i) {
                $line = $this->calcLine($i);
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
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $isAsset ? null : ($i['product_id'] ?: null),
                    'is_asset' => $isAsset,
                    'asset_category_id' => $isAsset ? (int) $i['asset_category_id'] : null,
                    'asset_name' => $assetName,
                    'description' => $i['description'] ?? null,
                    'qty' => $i['qty'],
                    'unit' => $i['unit'] ?? null,
                    'price' => $i['price'],
                    'discount_type' => $i['discount_type'] ?? 'nominal',
                    'discount_value' => (float) ($i['discount_value'] ?? 0),
                    'discount' => $line['discount'],
                    'subtotal' => $line['subtotal'],
                ]);
            }

            foreach ($expenses as $e) {
                PurchaseOrderExpense::create([
                    'purchase_order_id' => $po->id,
                    'account_id' => $e['account_id'],
                    'description' => $e['description'] ?? null,
                    'amount' => $e['amount'],
                    'mode' => $e['mode'],
                ]);
            }

            return $po;
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if (in_array($po->status, ['cancelled', 'closed'])) {
            throw new DomainException('PO sudah ' . $po->status . ', tidak bisa diedit.');
        }
        if ($po->invoices()->whereIn('status', ['posted', 'draft'])->exists()) {
            throw new DomainException('PO sudah punya invoice. Tidak bisa diedit.');
        }

        return DB::transaction(function () use ($po, $data) {
            $po->items()->delete();
            $po->expenses()->delete();

            $items = $data['items'] ?? [];
            $expenses = $data['expenses'] ?? [];

            $totals = $this->calculateTotals($items, $expenses,
                (float) ($data['ppn_percent'] ?? 0),
                $data['global_discount_type'] ?? null,
                (float) ($data['global_discount_value'] ?? 0)
            );

            $po->update([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'po_date' => $data['po_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['line_discount_total'],
                'global_discount_type' => $data['global_discount_type'] ?? null,
                'global_discount_value' => (float) ($data['global_discount_value'] ?? 0),
                'global_discount_amount' => $totals['global_discount_amount'],
                'expense_capitalized_total' => $totals['expense_capitalized_total'],
                'expense_direct_total' => $totals['expense_direct_total'],
                'ppn_percent' => (float) ($data['ppn_percent'] ?? 0),
                'ppn_amount' => $totals['ppn_amount'],
                'total' => $totals['total'],
                'notes' => $data['notes'] ?? null,
                'status' => $po->status === 'draft' ? 'posted' : $po->status,
            ]);

            foreach ($items as $i) {
                $line = $this->calcLine($i);
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
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $isAsset ? null : ($i['product_id'] ?: null),
                    'is_asset' => $isAsset,
                    'asset_category_id' => $isAsset ? (int) $i['asset_category_id'] : null,
                    'asset_name' => $assetName,
                    'description' => $i['description'] ?? null,
                    'qty' => $i['qty'],
                    'unit' => $i['unit'] ?? null,
                    'price' => $i['price'],
                    'discount_type' => $i['discount_type'] ?? 'nominal',
                    'discount_value' => (float) ($i['discount_value'] ?? 0),
                    'discount' => $line['discount'],
                    'subtotal' => $line['subtotal'],
                ]);
            }

            foreach ($expenses as $e) {
                PurchaseOrderExpense::create([
                    'purchase_order_id' => $po->id,
                    'account_id' => $e['account_id'],
                    'description' => $e['description'] ?? null,
                    'amount' => $e['amount'],
                    'mode' => $e['mode'],
                ]);
            }

            return $po;
        });
    }

    /**
     * Hitung diskon line nominal & subtotal line dari input qty/price/discount_type/discount_value.
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
     * Hitung total: subtotal items, line discount, global discount, expense capitalized/direct, PPN, total.
     */
    protected function calculateTotals(array $items, array $expenses, float $ppnPercent, ?string $gdiscType, float $gdiscValue): array
    {
        $sumLine = 0;
        $sumLineDisc = 0;
        foreach ($items as $i) {
            $line = $this->calcLine($i);
            $sumLine += $line['subtotal'];
            $sumLineDisc += $line['discount'];
        }

        // Global discount
        $globalDiscAmount = 0;
        if ($gdiscType === 'percent' && $gdiscValue > 0) {
            $globalDiscAmount = round($sumLine * $gdiscValue / 100, 2);
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

        $taxBase = $sumLine - $globalDiscAmount + $expenseCapitalized + $expenseDirect;
        $ppnAmount = round($taxBase * $ppnPercent / 100, 2);
        $total = round($taxBase + $ppnAmount, 2);

        return [
            'subtotal' => round($sumLine, 2),
            'line_discount_total' => round($sumLineDisc, 2),
            'global_discount_amount' => $globalDiscAmount,
            'expense_capitalized_total' => round($expenseCapitalized, 2),
            'expense_direct_total' => round($expenseDirect, 2),
            'ppn_amount' => $ppnAmount,
            'total' => $total,
        ];
    }
}
