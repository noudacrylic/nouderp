<?php

namespace App\Modules\Sales\Services;

use App\DTO\SalesReturnDTO;
use App\Models\SalesInvoice;
use App\Enums\AccountCodeEnum;
use App\Core\Accounting\Account;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Core\Journal\JournalPostingService;
use App\Core\Inventory\FifoService;
use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\ProductBundle;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Models\CustomerOverpayment;
use App\Services\NumberGeneratorService;
use Illuminate\Support\Facades\DB;
use Exception;

class SalesReturnService
{
    /**
     * Save return as draft (no accounting impact)
     */
    public function saveDraft(SalesReturnDTO $dto): SalesReturn
    {
        return DB::transaction(function () use ($dto) {
            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            $return = SalesReturn::create([
                'return_number'  => NumberGeneratorService::generate('SR'),
                'customer_id'    => $dto->customer_id,
                'invoice_id'     => $dto->invoice_id,
                'sales_order_id' => $dto->sales_order_id,
                'return_date'    => $dto->date,
                'grand_total'    => $totals['net'],
                'status'         => 'draft',
            ]);

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Update an existing draft
     */
    public function updateDraft(int $id, SalesReturnDTO $dto): SalesReturn
    {
        return DB::transaction(function () use ($id, $dto) {
            $return = SalesReturn::findOrFail($id);
            if ($return->status !== 'draft') {
                throw new Exception('Only draft returns can be updated.');
            }

            $doc = $this->getDoc($dto);
            $totals = $this->calculateTotals($dto, $doc);

            $return->update([
                'return_date' => $dto->date,
                'grand_total' => $totals['net'],
            ]);

            $return->items()->delete();

            foreach ($dto->items as $item) {
                $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
                $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

                SalesReturnItem::create([
                    'sales_return_id'   => $return->id,
                    'reference_item_id' => $item['invoice_item_id'],
                    'product_id'        => $docItem->product_id,
                    'qty'               => $item['qty'],
                    'unit_price'        => $docItem->unit_price,
                    'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
                    'condition'         => $item['condition'],
                    'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
                ]);
            }

            return $return;
        });
    }

    /**
     * Post the return (creates journal entries and updates inventory)
     */
    public function post(SalesReturnDTO $dto, ?int $existingReturnId = null): SalesReturn
    {
        return DB::transaction(function () use ($dto, $existingReturnId) {
            $doc = $this->getDoc($dto);
            $this->validatePosting($dto, $doc, $existingReturnId);
            $totals = $this->calculateTotals($dto, $doc);

            if ($existingReturnId) {
                $return = SalesReturn::findOrFail($existingReturnId);
                $return->update([
                    'return_date' => $dto->date,
                    'grand_total' => $totals['net'],
                    'status'      => 'posted',
                ]);
                $return->items()->delete();
                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            } else {
                $return = SalesReturn::create([
                    'return_number'  => NumberGeneratorService::generate('SR'),
                    'customer_id'    => $dto->customer_id,
                    'invoice_id'     => $dto->invoice_id,
                    'sales_order_id' => $dto->sales_order_id,
                    'return_date'    => $dto->date,
                    'grand_total'    => $totals['net'],
                    'status'         => 'posted',
                ]);

                foreach ($dto->items as $item) {
                    $this->storeReturnItem($return->id, $doc, $item);
                }
            }

            $journalLines = [];
            $journalLines = array_merge($journalLines, $this->getRevenueReversalLines($doc, $totals['net'], (bool) $dto->sales_order_id));
            $journalLines = array_merge($journalLines, $this->getCogsReversalLines($dto, $doc, $return->id));

            if (!empty($journalLines)) {
                $docNumber = $doc->invoice_number ?? $doc->order_number;
                $description = "Sales Return Reversal (Ref: {$docNumber})";

                app(JournalPostingService::class)->post(new JournalEntryDTO(
                    date: $dto->date,
                    reference_type: 'sales_return',
                    reference_id: $return->id,
                    description: $description,
                    lines: $journalLines,
                    reference_number: $return->return_number
                ));
            }

            if ($totals['net'] > 0 && !$doc->customer->is_marketplace) {
                CustomerOverpayment::create([
                    'customer_id' => $dto->customer_id,
                    'amount'      => $totals['net'],
                    'reference'   => $return->return_number,
                    'note'        => 'Sales Return',
                ]);
            }

            return $return;
        });
    }

    private function storeReturnItem(int $returnId, $doc, array $item): void
    {
        $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
        $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;

        SalesReturnItem::create([
            'sales_return_id'   => $returnId,
            'reference_item_id' => $item['invoice_item_id'],
            'product_id'        => $docItem->product_id,
            'qty'               => $item['qty'],
            'unit_price'        => $docItem->unit_price,
            'subtotal'          => round(($docItemSubtotal / $docItem->qty) * $item['qty'], 2),
            'condition'         => $item['condition'],
            'component_conditions' => $this->normalizeComponentConditions($item['component_conditions'] ?? null),
        ]);
    }

    /**
     * Bersihkan map kondisi per komponen: buang nilai kosong/tak valid, kunci = product_id int.
     * Return null bila tidak ada (item non-bundle) → item pakai `condition` tunggal.
     */
    private function normalizeComponentConditions($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $pid => $cond) {
            if (in_array($cond, ['good', 'repair', 'damaged'], true)) {
                $out[(int) $pid] = $cond;
            }
        }
        return $out ?: null;
    }

    private function getRevenueReversalLines($doc, $amount, $isSO)
    {
        $customer = $doc->customer;
        $creditAccountId = $this->getAccountId(AccountCodeEnum::CUSTOMER_OVERPAY);

        if ($customer->is_marketplace) {
            $config = \App\Models\MarketplaceConfig::where('customer_id', $customer->id)->first();
            if ($config && $config->account_receivable_hold_id) {
                $creditAccountId = $config->account_receivable_hold_id;
            }
        }

        $debitAccount = $isSO ? AccountCodeEnum::SALES_ADVANCE : AccountCodeEnum::SALES_REVENUE;

        return [
            new JournalLineDTO(
                account_id: $this->getAccountId($debitAccount),
                debit: (float) $amount,
                credit: 0,
                description: $isSO ? 'Sales Return Reversal - Advance' : 'Sales Return Reversal - Revenue'
            ),
            new JournalLineDTO(
                account_id: $creditAccountId,
                debit: 0,
                credit: (float) $amount,
                customer_id: $doc->customer_id,
                description: $customer->is_marketplace ? 'Marketplace Hold / Receivable Hold' : 'Customer Overpayment'
            ),
        ];
    }

    private function getCogsReversalLines($dto, $doc, $returnId)
    {
        $lines = [];
        $isSO = (bool) $dto->sales_order_id;
        $docNumber = $doc->invoice_number ?? $doc->order_number;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);
            if (!$docItem) {
                continue;
            }

            $qty = (float) $docItem->qty;
            if ($qty <= 0) {
                continue;
            }

            $cogsTotal = (float) ($docItem->cogs_total ?? 0);

            if ($doc instanceof \App\Modules\Sales\Models\SalesOrder && $cogsTotal == 0) {
                $cogsTotal = $doc->deliveries()->where('status', 'posted')->with('items')->get()
                    ->flatMap->items
                    ->where('sales_order_item_id', $docItem->id)
                    ->sum('cogs_total');
            }

            $product = Product::find($docItem->product_id);

            if ($product && $product->sale_type === 'bundle' && $cogsTotal == 0 && $dto->invoice_id) {
                $delivery = $doc->delivery ?? null;
                if ($delivery && $delivery->items) {
                    $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
                    $qtyField = 'qty';
                    if ($components->isEmpty()) {
                        $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                        $qtyField = 'qty_required';
                    }
                    foreach ($components as $comp) {
                        $deliveryItem = $delivery->items->firstWhere('product_id', $comp->component_product_id);
                        if ($deliveryItem) {
                            $cogsTotal += (float) $deliveryItem->cogs_total;
                        }
                    }
                }
            }

            // Bundle dengan kondisi PER KOMPONEN → tiap komponen dirutekan & di-COGS sendiri.
            $componentConditions = $this->normalizeComponentConditions($item['component_conditions'] ?? null);
            if ($product && $product->sale_type === 'bundle' && $componentConditions) {
                $lines = array_merge($lines, $this->bundleComponentCogsLines(
                    $doc, $docItem, $item, $componentConditions, $docNumber, $returnId, $isSO
                ));
                continue;
            }

            $unitCogs = $cogsTotal > 0 ? $cogsTotal / $qty : 0;
            $returnCogs = round($unitCogs * $item['qty'], 2);

            if ($item['condition'] === 'good' || $item['condition'] === 'repair') {
                $this->restoreReturnStock($dto, $doc, $docItem, $item, $unitCogs, $docNumber, $returnId);
            }

            if ($returnCogs <= 0) {
                continue;
            }

            $debitAccountCode = $this->getReturnConditionAccountCode($item['condition']);
            $debitAccountId = $this->getAccountId($debitAccountCode);
            $conditionLabel = $this->getReturnConditionLabel($item['condition']);

            $lines[] = new JournalLineDTO(
                account_id: $debitAccountId,
                debit: (float) $returnCogs,
                credit: 0,
                description: $isSO
                    ? "Retur SO - {$conditionLabel}"
                    : "Retur Invoice - {$conditionLabel}"
            );

            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $returnCogs,
                description: "{$conditionLabel} COGS Reversal"
            );
        }

        return $lines;
    }

    private function restoreReturnStock($dto, $doc, $docItem, array $item, float $unitCogs, string $docNumber, int $returnId): void
    {
        $product = Product::find($docItem->product_id);

        // Barang kondisi 'repair' masuk ke Gudang Perbaikan (non-jual, = akun 1131), BUKAN
        // gudang jual — supaya tidak ikut terjual & saldo 1131 cocok dengan stok fisik.
        // Kondisi 'good' tetap kembali ke gudang dokumen. (bundle: komponennya ikut aturan sama)
        $targetWarehouseId = $item['condition'] === 'repair'
            ? (Warehouse::repairId() ?? $doc->warehouse_id)
            : $doc->warehouse_id;

        if ($product && $product->sale_type === 'bundle') {
            $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty';

            if ($components->isEmpty()) {
                $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
                $qtyField = 'qty_required';
            }

            $totalUnits = $components->sum(fn ($c) => $c->{$qtyField} ?? 1);

            foreach ($components as $comp) {
                $compQtyPerBundle = $comp->{$qtyField} ?? 1;
                $compQtyReturn = $compQtyPerBundle * $item['qty'];
                $compCostPerUnit = ($totalUnits > 0 && $unitCogs > 0)
                    ? $unitCogs * ($compQtyPerBundle / $totalUnits)
                    : 0;

                app(FifoService::class)->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $targetWarehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compCostPerUnit,
                    transactionId: $returnId
                );
            }

            return;
        }

        app(FifoService::class)->stockIn(
            productId: $docItem->product_id,
            warehouseId: $targetWarehouseId,
            type: 'sales_return',
            reference: $docNumber,
            qty: $item['qty'],
            cost: $unitCogs,
            transactionId: $returnId
        );
    }

    /** Kumpulkan item SJ (posted) untuk dokumen retur — sumber COGS per komponen. */
    private function deliveryItemsFor($doc)
    {
        if ($doc instanceof \App\Modules\Sales\Models\SalesOrder) {
            return $doc->deliveries()->where('status', 'posted')->with('items')->get()->flatMap->items;
        }
        return $doc->delivery?->items ?? collect();
    }

    /**
     * Bundle dengan kondisi PER KOMPONEN: rutekan stok & buat baris COGS untuk tiap komponen
     * sesuai kondisinya sendiri (good→persediaan, repair→Gudang Perbaikan, damaged→beban).
     * Pembalikan pendapatan tetap di level bundle (di getRevenueReversalLines), tak disentuh.
     */
    private function bundleComponentCogsLines($doc, $docItem, array $item, array $componentConditions, string $docNumber, int $returnId, bool $isSO): array
    {
        $components = BundleComponent::where('bundle_product_id', $docItem->product_id)->get();
        $qtyField = 'qty';
        if ($components->isEmpty()) {
            $components = ProductBundle::where('bundle_product_id', $docItem->product_id)->get();
            $qtyField = 'qty_required';
        }

        $deliveryItems     = $this->deliveryItemsFor($doc);
        $docQty            = (float) $docItem->qty;
        $retQty            = (float) $item['qty'];
        $repairWarehouseId = Warehouse::repairId();
        $fifo              = app(FifoService::class);
        $lines             = [];

        foreach ($components as $comp) {
            $cond = $componentConditions[(int) $comp->component_product_id] ?? 'good';
            $compQtyPerBundle = (float) ($comp->{$qtyField} ?? 1);
            if ($compQtyPerBundle <= 0) {
                continue;
            }

            $di            = $deliveryItems->firstWhere('product_id', $comp->component_product_id);
            $compDelivCogs = (float) ($di->cogs_total ?? 0);
            // Biaya 1 unit komponen (delivery cogs mencakup docQty × compQtyPerBundle unit).
            $compUnitCost   = ($docQty > 0) ? $compDelivCogs / ($docQty * $compQtyPerBundle) : 0;
            $compQtyReturn  = $compQtyPerBundle * $retQty;
            $compReturnCogs = round($compUnitCost * $compQtyReturn, 2);

            // Routing stok: good/repair masuk gudang (repair→Gudang Perbaikan); damaged tidak.
            if ($cond === 'good' || $cond === 'repair') {
                $warehouseId = $cond === 'repair'
                    ? ($repairWarehouseId ?? $doc->warehouse_id)
                    : $doc->warehouse_id;
                $fifo->stockIn(
                    productId: $comp->component_product_id,
                    warehouseId: $warehouseId,
                    type: 'sales_return',
                    reference: $docNumber,
                    qty: $compQtyReturn,
                    cost: $compUnitCost,
                    transactionId: $returnId
                );
            }

            if ($compReturnCogs <= 0) {
                continue;
            }

            $label = $this->getReturnConditionLabel($cond);
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId($this->getReturnConditionAccountCode($cond)),
                debit: (float) $compReturnCogs,
                credit: 0,
                description: ($isSO ? 'Retur SO' : 'Retur Invoice') . " - {$label} (komponen)"
            );
            $lines[] = new JournalLineDTO(
                account_id: $this->getAccountId(AccountCodeEnum::COGS),
                debit: 0,
                credit: (float) $compReturnCogs,
                description: "{$label} COGS Reversal (komponen)"
            );
        }

        return $lines;
    }

    private function getReturnConditionAccountCode(string $condition): string
    {
        return match ($condition) {
            'good' => AccountCodeEnum::INVENTORY,
            'repair' => AccountCodeEnum::INVENTORY_REPAIR,
            'damaged' => AccountCodeEnum::SALES_LOSS,
            default => AccountCodeEnum::INVENTORY,
        };
    }

    private function getReturnConditionLabel(string $condition): string
    {
        return match ($condition) {
            'good' => 'Persediaan',
            'repair' => 'Persediaan Perbaikan',
            'damaged' => 'Beban Kerugian Retur',
            default => 'Persediaan',
        };
    }

    private function getDoc(SalesReturnDTO $dto)
    {
        if ($dto->invoice_id) {
            return SalesInvoice::with(['items.product', 'customer', 'delivery.items'])->findOrFail($dto->invoice_id);
        } elseif ($dto->sales_order_id) {
            return \App\Modules\Sales\Models\SalesOrder::with(['items', 'customer', 'deliveries.items'])->findOrFail($dto->sales_order_id);
        }

        throw new Exception('Either invoice_id atau sales_order_id harus diisi.');
    }

    private function validatePosting(SalesReturnDTO $dto, $doc, ?int $existingReturnId = null)
    {
        $docType = $dto->invoice_id ? 'invoice' : 'so';
        $docStatus = $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status;

        if ($docType === 'invoice') {
            if (!in_array($docStatus, ['posted', 'partial'])) {
                throw new Exception("Invoice status '{$docStatus}' tidak dapat diretur. Harus posted atau partial.");
            }
        } else {
            if (!in_array($docStatus, ['confirmed', 'closed', 'partial'])) {
                throw new Exception("Sales Order status '{$docStatus}' tidak dapat diretur.");
            }

            $hasSJ = $doc->deliveries()->where('status', 'posted')->exists();
            if (!$hasSJ) {
                throw new Exception('Sales Order belum memiliki Pengiriman (SJ) yang diposting. Jika barang belum keluar, cukup lakukan VOID pada SO.');
            }

            $isPaid = $doc->payment_status === 'paid' || (($doc->paid_amount ?? 0) >= $doc->grand_total);
            if (!$isPaid) {
                throw new Exception('Sales Order belum lunas. Retur uang hanya berlaku untuk pesanan yang sudah terbayar penuh (Advance).');
            }
        }

        if (empty($dto->items)) {
            throw new Exception('Minimal harus ada 1 item yang diretur.');
        }

        // Satu produk kini bisa dipecah ke beberapa baris (kondisi berbeda: utuh/perbaikan/
        // rusak). Validasi returnable harus pakai TOTAL per item dokumen, bukan per baris —
        // kalau tidak, tiap baris lolos sendiri (mis. utuh 2 + rusak 2) padahal jumlahnya
        // melebihi qty dokumen.
        $requestedByRef = [];
        foreach ($dto->items as $item) {
            $refId = $item['invoice_item_id'];
            if (!$doc->items->firstWhere('id', $refId)) {
                throw new Exception("Item ID {$refId} tidak ditemukan pada dokumen");
            }
            if ($item['qty'] <= 0) {
                throw new Exception('Qty retur harus lebih besar dari 0');
            }
            $requestedByRef[$refId] = ($requestedByRef[$refId] ?? 0) + (float) $item['qty'];
        }

        foreach ($requestedByRef as $refId => $requestedQty) {
            $targetItem = $doc->items->firstWhere('id', $refId);

            // Qty yang SUDAH diretur (posted) untuk item dokumen ini, kecuali retur yang
            // sedang di-post. Cegah double-return: validasi pakai SISA, bukan qty penuh.
            $alreadyReturned = (float) SalesReturnItem::where('reference_item_id', $refId)
                ->whereHas('salesReturn', function ($q) use ($existingReturnId) {
                    $q->where('status', 'posted');
                    if ($existingReturnId) {
                        $q->where('id', '!=', $existingReturnId);
                    }
                })
                ->sum('qty');

            $returnable = (float) $targetItem->qty - $alreadyReturned;
            if ($requestedQty > $returnable + 0.00001) {
                throw new Exception(
                    "Total qty retur ({$requestedQty}) untuk " . ($targetItem->product?->name ?? "item #{$refId}") . " melebihi sisa yang bisa diretur ({$returnable}). "
                    . "Qty dokumen: {$targetItem->qty}, sudah diretur: {$alreadyReturned}."
                );
            }
        }
    }

    private function calculateTotals($dto, $doc)
    {
        $totalNet = 0;

        foreach ($dto->items as $item) {
            $docItem = $doc->items->firstWhere('id', $item['invoice_item_id']);

            if (!$docItem) {
                throw new Exception("Item ID {$item['invoice_item_id']} tidak ditemukan");
            }

            $docItemSubtotal = $docItem->subtotal ?? $docItem->line_total ?? 0;
            $ratio = $item['qty'] / $docItem->qty;
            $lineNet = $docItemSubtotal * $ratio;
            $totalNet += $lineNet;
        }

        return [
            'net' => round($totalNet, 2),
        ];
    }

    private function getAccountId($code)
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            if ($code === AccountCodeEnum::INVENTORY_REPAIR || $code === AccountCodeEnum::INVENTORY_DAMAGED) {
                return $this->getAccountId(AccountCodeEnum::INVENTORY);
            }
            if ($code === AccountCodeEnum::SALES_LOSS) {
                return $this->getAccountId(AccountCodeEnum::COGS);
            }

            throw new Exception("Account with code {$code} not found.");
        }

        return $id;
    }
}
