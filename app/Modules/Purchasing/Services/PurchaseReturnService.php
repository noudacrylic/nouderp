<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnItem;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierOverpayment;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\InventoryEngine;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

/**
 * Purchase Return — 2 mode:
 *
 *   1. INVOICE retur — barang diterima tapi rusak. Refund supplier dihitung
 *      berbasis HPP (final_unit_cost = harga supplier − global discount +
 *      landed cost capitalized share). Beban yang TIDAK dikapitalkan
 *      (direct_expense) adalah expense perusahaan, tidak ikut di-refund.
 *      PPN tidak ikut di-reverse (per spec user).
 *
 *   2. PO retur — barang batal kirim oleh supplier (kasus marketplace),
 *      DP yang sudah dibayar dipindah dari Uang Muka (1107) ke Piutang
 *      Lebih Bayar Supplier (1108). Tidak ada efek FIFO/inventory.
 *
 * Routing receivable: nilai retur dialokasikan ke AP (2101) dulu sampai
 *   habis outstanding invoice; sisanya ke 1108 (Piutang Lebih Bayar).
 */
class PurchaseReturnService
{
    public function __construct(
        protected PeriodService $periodService
    ) {}

    protected function account(string $key): string
    {
        return match ($key) {
            'inventory'        => '1130',
            'ap'               => '2101',
            'ppn_masukan'      => '2102',
            'dp_supplier'      => '1107',
            'overpay_supplier' => '1108',
            'loss_return'      => '6105', // Beban Kerugian Retur (residual landed cost)
            default => throw new \Exception("Account mapping not found: $key"),
        };
    }

    public function generateNumber(): string
    {
        $prefix = 'RPB/' . now()->format('Y/m') . '/';
        $latest = PurchaseReturn::where('return_number', 'LIKE', $prefix . '%')
            ->orderByDesc('return_number')
            ->value('return_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    // ──────────────────────────────────────────────────────────
    //  CREATE / UPDATE DRAFT
    // ──────────────────────────────────────────────────────────

    public function createDraft(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data) {
            $type = $data['return_type'] ?? 'invoice';

            return $type === 'po'
                ? $this->createDraftFromPo($data)
                : $this->createDraftFromInvoice($data);
        });
    }

    protected function createDraftFromInvoice(array $data): PurchaseReturn
    {
        $invoice = PurchaseInvoice::with('items')->find($data['purchase_invoice_id'] ?? null);
        if (!$invoice) throw new DomainException('Purchase Invoice tidak ditemukan.');
        if ($invoice->status !== 'posted') throw new DomainException('Hanya invoice posted yang bisa di-retur.');

        $items = $data['items'] ?? [];
        if (empty($items)) throw new DomainException('Retur invoice harus punya minimal 1 item.');

        $rows = $this->buildInvoiceItemRows($invoice, $items);
        $subtotal = array_sum(array_column($rows, 'subtotal'));

        $return = PurchaseReturn::create([
            'return_number'       => $data['return_number'] ?? $this->generateNumber(),
            'return_date'         => $data['return_date'],
            'return_type'         => 'invoice',
            'supplier_id'         => $invoice->supplier_id,
            'purchase_invoice_id' => $invoice->id,
            'purchase_order_id'   => null,
            'warehouse_id'        => $invoice->warehouse_id,
            'subtotal'            => $subtotal,
            'ppn_amount'          => 0,
            'total'               => $subtotal,
            'refund_amount'       => $subtotal,
            'status'              => 'draft',
            'notes'               => $data['notes'] ?? null,
        ]);
        foreach ($rows as $r) {
            PurchaseReturnItem::create(array_merge(['purchase_return_id' => $return->id], $r));
        }
        return $return;
    }

    protected function createDraftFromPo(array $data): PurchaseReturn
    {
        $po = PurchaseOrder::find($data['purchase_order_id'] ?? null);
        if (!$po) throw new DomainException('Purchase Order tidak ditemukan.');
        if ($po->status !== 'posted') throw new DomainException('Hanya PO posted yang bisa di-retur.');

        $refund = round((float) ($data['refund_amount'] ?? 0), 2);
        if ($refund <= 0) throw new DomainException('Nominal refund DP harus > 0.');

        $dpBalance = $this->getSupplierDpBalance((int) $po->supplier_id);
        if ($refund > $dpBalance + 0.0001) {
            throw new DomainException('Refund DP melebihi saldo Uang Muka supplier (Rp ' . number_format($dpBalance, 0, ',', '.') . ').');
        }

        return PurchaseReturn::create([
            'return_number'       => $data['return_number'] ?? $this->generateNumber(),
            'return_date'         => $data['return_date'],
            'return_type'         => 'po',
            'supplier_id'         => $po->supplier_id,
            'purchase_invoice_id' => null,
            'purchase_order_id'   => $po->id,
            'warehouse_id'        => null,
            'subtotal'            => $refund,
            'ppn_amount'          => 0,
            'total'               => $refund,
            'refund_amount'       => $refund,
            'status'              => 'draft',
            'notes'               => $data['notes'] ?? null,
        ]);
    }

    public function update(PurchaseReturn $return, array $data): PurchaseReturn
    {
        if ($return->status !== 'draft') {
            throw new DomainException('Hanya retur draft yang bisa diedit.');
        }
        return DB::transaction(function () use ($return, $data) {
            $return->items()->delete();

            if ($return->return_type === 'po') {
                $refund = round((float) ($data['refund_amount'] ?? 0), 2);
                if ($refund <= 0) throw new DomainException('Nominal refund DP harus > 0.');
                $dpBalance = $this->getSupplierDpBalance((int) $return->supplier_id);
                if ($refund > $dpBalance + 0.0001) {
                    throw new DomainException('Refund DP melebihi saldo Uang Muka supplier.');
                }
                $return->update([
                    'return_date'   => $data['return_date'],
                    'subtotal'      => $refund,
                    'ppn_amount'    => 0,
                    'total'         => $refund,
                    'refund_amount' => $refund,
                    'notes'         => $data['notes'] ?? null,
                ]);
            } else {
                $invoice = PurchaseInvoice::with('items')->find($return->purchase_invoice_id);
                if (!$invoice) throw new DomainException('Invoice asal tidak ditemukan.');
                $items = $data['items'] ?? [];
                if (empty($items)) throw new DomainException('Retur invoice harus punya minimal 1 item.');

                $rows = $this->buildInvoiceItemRows($invoice, $items);
                $subtotal = array_sum(array_column($rows, 'subtotal'));

                foreach ($rows as $r) {
                    PurchaseReturnItem::create(array_merge(['purchase_return_id' => $return->id], $r));
                }
                $return->update([
                    'return_date'   => $data['return_date'],
                    'subtotal'      => $subtotal,
                    'ppn_amount'    => 0,
                    'total'         => $subtotal,
                    'refund_amount' => $subtotal,
                    'notes'         => $data['notes'] ?? null,
                ]);
            }
            return $return;
        });
    }

    /**
     * Bangun array item rows utk retur invoice. unit_cost & subtotal per
     * item = HPP (final_unit_cost) — sudah include landed cost capitalized
     * dan global discount share. Beban direct_expense tidak ikut di-refund
     * karena bukan bagian HPP.
     */
    protected function buildInvoiceItemRows(PurchaseInvoice $invoice, array $items): array
    {
        $rows = [];
        foreach ($items as $i) {
            $invItem = $invoice->items->firstWhere('id', $i['purchase_invoice_item_id']);
            if (!$invItem) throw new DomainException('Item invoice tidak ditemukan.');

            $qty = round((float) $i['qty'], 4);
            if ($qty <= 0) continue;

            $remaining = (float) $invItem->qty - (float) $invItem->qty_returned;
            if ($qty > $remaining + 0.0001) {
                throw new DomainException("Qty retur ({$qty}) melebihi qty tersisa ({$remaining}) untuk produk ID {$invItem->product_id}.");
            }

            // Refund per unit = HPP (final_unit_cost). Sama dengan layer FIFO,
            // sehingga journal Cr 1130 = layer total yang dikonsumsi.
            $refundPerUnit = round((float) $invItem->final_unit_cost, 4);
            $lineSubtotal = round($qty * $refundPerUnit, 2);

            $rows[] = [
                'purchase_invoice_item_id' => $invItem->id,
                'product_id'               => $invItem->product_id,
                'qty'                      => $qty,
                'unit_cost'                => $refundPerUnit,
                'subtotal'                 => $lineSubtotal,
            ];
        }
        if (empty($rows)) throw new DomainException('Tidak ada item dengan qty valid.');
        return $rows;
    }

    public function destroy(PurchaseReturn $return): void
    {
        if ($return->status !== 'draft') {
            throw new DomainException('Hanya retur draft yang bisa dihapus.');
        }
        DB::transaction(function () use ($return) {
            $return->items()->delete();
            $return->delete();
        });
    }

    // ──────────────────────────────────────────────────────────
    //  POST
    // ──────────────────────────────────────────────────────────

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status === 'posted') throw new DomainException('Retur sudah posted.');
        if ($return->status === 'void')   throw new DomainException('Retur sudah voided.');

        $exists = Journal::where('reference_type', 'purchase_return')
            ->where('reference_id', $return->id)
            ->exists();
        if ($exists) throw new DomainException('Journal sudah ada untuk retur ini.');

        $returnDate = Carbon::parse($return->return_date);
        $this->periodService->ensureOpen($returnDate);

        return DB::transaction(function () use ($return, $returnDate) {
            return $return->return_type === 'po'
                ? $this->postPoReturn($return, $returnDate)
                : $this->postInvoiceReturn($return, $returnDate);
        });
    }

    protected function postInvoiceReturn(PurchaseReturn $return, Carbon $returnDate): PurchaseReturn
    {
        $return->load(['items', 'purchaseInvoice.supplier']);
        $invoice = $return->purchaseInvoice;
        if (!$invoice) throw new DomainException('Invoice asal hilang.');

        $engine = app(InventoryEngine::class);
        $invCreditTotal = 0;   // total kredit ke 1130 (akumulasi proporsional + residual)
        $residualLoss   = 0;   // total ke 6105 kalau layer habis tapi masih sisa landed

        // 1. Stock OUT + repricing layer
        foreach ($return->items as $item) {
            $invCreditLayer = $this->consumeInvoiceLayer(
                $invoice->id,
                $item->product_id,
                $return->warehouse_id,
                (float) $item->qty,
                (float) $item->subtotal,   // refund value yang akan dikurangi dari layer
                $return->id
            );
            // jika layer habis tapi masih ada landed residual, $invCreditLayer > $item->subtotal
            $invCreditTotal += $invCreditLayer;
            $residualLoss   += round($invCreditLayer - (float) $item->subtotal, 2);

            $engine->ledger(
                $item->product_id,
                $return->warehouse_id,
                0,
                (float) $item->qty,
                'purchase_return',
                $return->return_number,
                null,
                $return->id
            );

            // qty_returned di invoice item
            $invItem = PurchaseInvoiceItem::find($item->purchase_invoice_item_id);
            if ($invItem) {
                $invItem->qty_returned = (float) $invItem->qty_returned + (float) $item->qty;
                $invItem->save();
            }
        }

        // 2. Journal
        $journal = $this->createJournal($return, $returnDate);
        $return->journal_id = $journal->id;

        $refund = (float) $return->refund_amount;

        // Routing receivable: pakai outstanding invoice dulu (Dr 2101), sisanya Dr 1108
        $invOutstanding = (float) $invoice->outstanding_amount;
        $apReduction    = round(min($invOutstanding, $refund), 2);
        $overpayAdd     = round($refund - $apReduction, 2);

        if ($apReduction > 0) {
            $apAccountId = $invoice->supplier->account_payable_id ?? $this->getAccountIdByCode($this->account('ap'));
            $this->createLine($journal, $return, $apAccountId, 'debit', $apReduction, 'Pengurangan hutang (retur)');
        }
        if ($overpayAdd > 0) {
            $this->createLine($journal, $return, $this->getAccountIdByCode($this->account('overpay_supplier')), 'debit', $overpayAdd, 'Piutang lebih bayar supplier (retur)');
        }
        if ($residualLoss > 0) {
            $this->createLine($journal, $return, $this->getAccountIdByCode($this->account('loss_return')), 'debit', $residualLoss, 'Sisa landed cost layer habis');
        }
        if ($invCreditTotal > 0) {
            $this->createLine($journal, $return, $this->getAccountIdByCode($this->account('inventory')), 'credit', $invCreditTotal, 'Pengurangan persediaan (retur)');
        }

        // 3. Update invoice outstanding
        if ($apReduction > 0) {
            $invoice->outstanding_amount = max(0, $invOutstanding - $apReduction);
            $invoice->save();
        }

        // 4. Pool supplier_overpayments untuk porsi 1108
        if ($overpayAdd > 0) {
            SupplierOverpayment::create([
                'supplier_id' => $return->supplier_id,
                'amount'      => $overpayAdd,
                'reference'   => $return->return_number,
                'note'        => 'Retur invoice ' . ($invoice->invoice_number ?? '-'),
            ]);
        }

        $this->validateBalance($journal->id);
        $return->status    = 'posted';
        $return->posted_at = now();
        $return->save();
        return $return;
    }

    protected function postPoReturn(PurchaseReturn $return, Carbon $returnDate): PurchaseReturn
    {
        $refund = (float) $return->refund_amount;
        if ($refund <= 0) throw new DomainException('Nominal refund DP harus > 0.');

        // Re-cek saldo cukup
        $dpBalance = $this->getSupplierDpBalance((int) $return->supplier_id);
        if ($refund > $dpBalance + 0.0001) {
            throw new DomainException('Saldo DP supplier tidak cukup saat post (Rp ' . number_format($dpBalance, 0, ',', '.') . ').');
        }

        // Konsumsi DP FIFO dari supplier_payments.remaining_amount
        $log = [];
        $remaining = $refund;
        $payments = SupplierPayment::where('supplier_id', $return->supplier_id)
            ->where('status', 'posted')
            ->where('remaining_amount', '>', 0)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($payments as $payment) {
            if ($remaining <= 0.0001) break;
            $take = round(min($remaining, (float) $payment->remaining_amount), 2);
            if ($take <= 0) continue;
            $payment->remaining_amount = (float) $payment->remaining_amount - $take;
            $payment->allocated_amount = (float) $payment->allocated_amount + $take;
            $payment->save();
            $log[] = ['payment_id' => $payment->id, 'amount' => $take];
            $remaining -= $take;
        }
        if ($remaining > 0.0001) {
            throw new DomainException('Gagal konsumsi DP — saldo berubah saat post.');
        }
        $return->dp_allocation = $log;

        // Journal
        $journal = $this->createJournal($return, $returnDate);
        $return->journal_id = $journal->id;

        $this->createLine($journal, $return, $this->getAccountIdByCode($this->account('overpay_supplier')), 'debit', $refund, 'Piutang lebih bayar dari refund DP');
        $this->createLine($journal, $return, $this->getAccountIdByCode($this->account('dp_supplier')), 'credit', $refund, 'Pemindahan dari Uang Muka Supplier');

        // Pool supplier_overpayments
        SupplierOverpayment::create([
            'supplier_id' => $return->supplier_id,
            'amount'      => $refund,
            'reference'   => $return->return_number,
            'note'        => 'Retur DP PO ' . ($return->purchaseOrder->po_number ?? '-'),
        ]);

        $this->validateBalance($journal->id);
        $return->status    = 'posted';
        $return->posted_at = now();
        $return->save();
        return $return;
    }

    /**
     * Konsumsi 1 layer dari purchase invoice ini.
     * Return: nilai yang DI-KREDIT ke 1130 (= refund_value untuk partial,
     * = sisa_total_layer untuk full retur layer).
     *
     * Effect pada layer:
     *   - qty_remaining berkurang
     *   - kalau qty_remaining baru > 0: unit_cost di-reprice supaya
     *     sisa_total = old_total - refund_value (landed cost diserap qty sisa)
     *   - kalau qty_remaining baru = 0: layer dihabiskan, sisa_total
     *     residual masuk loss
     */
    protected function consumeInvoiceLayer(int $invoiceId, int $productId, ?int $warehouseId, float $qtyReturn, float $refundValue, int $returnId): float
    {
        $layer = StockLayer::where('product_id', $productId)
            ->where('source_type', 'purchase')
            ->where('source_id', $invoiceId)
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId))
            ->where('qty_remaining', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (!$layer) {
            throw new DomainException("Layer FIFO untuk produk ID {$productId} dari invoice ini tidak ditemukan / habis. Tidak bisa retur.");
        }
        if ($qtyReturn > (float) $layer->qty_remaining + 0.0001) {
            throw new DomainException("Stok dari invoice asal sudah dipakai (terjual). Sisa qty: {$layer->qty_remaining}, retur diminta: {$qtyReturn}.");
        }

        $oldQty   = (float) $layer->qty_remaining;
        $oldCost  = (float) $layer->unit_cost;
        $oldTotal = round($oldQty * $oldCost, 2);
        $newQty   = round($oldQty - $qtyReturn, 4);

        if ($newQty > 0.0001) {
            // Partial: kurangi stok senilai refund. Sisanya menyerap landed.
            $newTotal = round($oldTotal - $refundValue, 2);
            if ($newTotal < 0) $newTotal = 0; // safety
            $layer->qty_remaining = $newQty;
            $layer->unit_cost     = round($newTotal / $newQty, 4);
            $layer->save();
            return $refundValue;
        }

        // Full retur layer: kurangi stok senilai full layer total, residual = total - refund
        $layer->qty_remaining = 0;
        $layer->save();
        return $oldTotal;
    }

    // ──────────────────────────────────────────────────────────
    //  VOID
    // ──────────────────────────────────────────────────────────

    public function void(PurchaseReturn $return): PurchaseReturn
    {
        if ($return->status !== 'posted') {
            throw new DomainException('Hanya retur posted yang bisa di-void.');
        }

        return DB::transaction(function () use ($return) {
            if ($return->return_type === 'po') {
                $this->voidPoReturn($return);
            } else {
                $this->voidInvoiceReturn($return);
            }

            // Void pool supplier_overpayments
            SupplierOverpayment::where('supplier_id', $return->supplier_id)
                ->where('reference', $return->return_number)
                ->delete();

            if ($return->journal_id) {
                $journal = Journal::find($return->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }
            $return->status    = 'void';
            $return->voided_at = now();
            $return->save();
            return $return;
        });
    }

    protected function voidInvoiceReturn(PurchaseReturn $return): void
    {
        $return->load('items', 'purchaseInvoice');
        $invoice = $return->purchaseInvoice;
        $engine = app(InventoryEngine::class);

        foreach ($return->items as $item) {
            // Rollback ke layer purchase asli (bukan bikin layer baru) — supaya retur
            // berikutnya untuk invoice yang sama bisa menemukan FIFO layer-nya.
            // Karena retur sekarang konsumsi layer persis sebesar HPP, restore juga
            // tidak mengubah unit_cost (mathematically: total_baru/qty_baru = HPP).
            $layer = StockLayer::where('product_id', $item->product_id)
                ->where('source_type', 'purchase')
                ->where('source_id', $invoice->id)
                ->when($return->warehouse_id, fn($q) => $q->where('warehouse_id', $return->warehouse_id))
                ->orderBy('id')
                ->first();

            if ($layer) {
                $oldQty   = (float) $layer->qty_remaining;
                $oldTotal = round($oldQty * (float) $layer->unit_cost, 2);
                $newQty   = round($oldQty + (float) $item->qty, 4);
                $newTotal = round($oldTotal + (float) $item->subtotal, 2);
                $layer->qty_remaining = $newQty;
                $layer->unit_cost     = $newQty > 0 ? round($newTotal / $newQty, 4) : (float) $item->unit_cost;
                $layer->save();
            } else {
                // Fallback: layer asli tidak ada lagi — recreate sebagai source_type=purchase
                // sehingga tetap findable untuk retur berikutnya.
                StockLayer::create([
                    'product_id'    => $item->product_id,
                    'warehouse_id'  => $return->warehouse_id,
                    'qty_in'        => $item->qty,
                    'qty_remaining' => $item->qty,
                    'unit_cost'     => $item->unit_cost,
                    'source_type'   => 'purchase',
                    'source_id'     => $invoice->id,
                ]);
            }

            $engine->ledger(
                $item->product_id,
                $return->warehouse_id,
                (float) $item->qty,
                0,
                'purchase_return_void',
                $return->return_number,
                null,
                $return->id
            );
            $invItem = PurchaseInvoiceItem::find($item->purchase_invoice_item_id);
            if ($invItem) {
                $invItem->qty_returned = max(0, (float) $invItem->qty_returned - (float) $item->qty);
                $invItem->save();
            }
        }

        // Restore invoice outstanding sebesar apReduction yang dulu di-deduct saat post,
        // BUKAN full refund_amount. apReduction = refund_amount − overpayAdd (porsi 1108).
        // Overpay row dibaca dulu sebelum dihapus di void() pool cleanup.
        if ($invoice) {
            $overpayAmount = (float) (SupplierOverpayment::where('supplier_id', $return->supplier_id)
                ->where('reference', $return->return_number)
                ->value('amount') ?? 0);
            $apReduction = max(0, round((float) $return->refund_amount - $overpayAmount, 2));
            if ($apReduction > 0) {
                $invoice->outstanding_amount = (float) $invoice->outstanding_amount + $apReduction;
                $invoice->save();
            }
        }
    }

    protected function voidPoReturn(PurchaseReturn $return): void
    {
        // Kembalikan saldo DP berdasar log dp_allocation
        $log = $return->dp_allocation ?? [];
        foreach ($log as $entry) {
            $payment = SupplierPayment::find($entry['payment_id'] ?? null);
            if (!$payment) continue;
            $amt = (float) ($entry['amount'] ?? 0);
            if ($amt <= 0) continue;
            $payment->remaining_amount = (float) $payment->remaining_amount + $amt;
            $payment->allocated_amount = max(0, (float) $payment->allocated_amount - $amt);
            $payment->save();
        }
    }

    // ──────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────

    public function getSupplierDpBalance(int $supplierId): float
    {
        return (float) SupplierPayment::where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->sum('remaining_amount');
    }

    protected function createJournal(PurchaseReturn $return, Carbon $returnDate): Journal
    {
        $period = AccountingPeriod::where('year', $returnDate->year)
            ->where('month', $returnDate->month)
            ->first();
        if (!$period) throw new \Exception('Accounting period tidak ditemukan / belum dibuka.');

        return Journal::create([
            'journal_number'   => $this->generateJournalNumber(),
            'date'             => $return->return_date,
            'period_id'        => $period->id,
            'reference_type'   => 'purchase_return',
            'reference_id'     => $return->id,
            'reference_number' => $return->return_number,
            'description'      => 'Purchase Return ' . $return->return_number,
            'status'           => 'posted',
            'posted_at'        => now(),
        ]);
    }

    protected function createLine(Journal $journal, PurchaseReturn $return, int $accountId, string $type, float $amount, ?string $description = null): void
    {
        if ($amount <= 0) return;
        JournalLine::create([
            'journal_id'       => $journal->id,
            'account_id'       => $accountId,
            'debit'            => $type === 'debit' ? $amount : 0,
            'credit'           => $type === 'credit' ? $amount : 0,
            'description'      => $description,
            'reference_type'   => 'purchase_return',
            'reference_id'     => $return->id,
            'reference_number' => $return->return_number,
        ]);
    }

    protected function validateBalance(int $journalId): void
    {
        $lines = JournalLine::where('journal_id', $journalId)->get();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('credit'), 2);
        // Bandingkan dgn toleransi (float strict !== rawan false-positive).
        if (abs($debit - $credit) > 0.005) {
            throw new \Exception("Journal not balanced: Dr=$debit Cr=$credit");
        }
    }

    protected function generateJournalNumber(): string
    {
        $prefix = 'RPV/' . now()->format('Y/m') . '/';
        $latest = Journal::where('journal_number', 'LIKE', $prefix . '%')
            ->orderByDesc('journal_number')
            ->value('journal_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    protected function getAccountIdByCode(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) throw new \Exception("Account code NOT found: $code");
        return $id;
    }
}
