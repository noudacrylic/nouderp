<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\FixedAsset\Models\AssetCategory;
use App\Modules\FixedAsset\Services\FixedAssetAcquisitionService;
use App\Core\Inventory\InventoryEngine;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class PurchaseInvoicePostingService
{
    public function __construct(
        protected PeriodService $periodService
    ) {}

    /**
     * MAPPING AKUN PURCHASING
     */
    protected function account(string $key): string
    {
        return match ($key) {
            'inventory'    => '1130', // Persediaan
            'ap'           => '2101', // Hutang Usaha
            'ppn_masukan'  => '2102', // Pajak Masukan
            'dp_supplier'  => '1107', // Uang Muka Supplier
            default => throw new \Exception("Account mapping not found for key: $key"),
        };
    }

    /**
     * Faktor konversi satuan beli item → satuan dasar produk.
     * PurchaseInvoiceItem hanya menyimpan nama satuan (string), jadi faktor
     * dilihat dari product_units (unit_name = item->unit). Default 1 bila
     * satuan kosong / sama dengan satuan dasar / tidak terdaftar.
     */
    protected function conversionToBase($item): float
    {
        if (empty($item->unit)) {
            return 1.0;
        }
        $conv = \App\Core\Inventory\ProductUnit::where('product_id', $item->product_id)
            ->where('unit_name', $item->unit)
            ->value('conversion_to_base');

        return $conv && (float) $conv > 0 ? (float) $conv : 1.0;
    }

    public function post(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status === 'posted') {
            throw new DomainException('Invoice sudah posted.');
        }
        if ($invoice->status === 'void') {
            throw new DomainException('Invoice sudah voided.');
        }

        // Double-post guard
        $exists = Journal::where('reference_type', 'purchase_invoice')
            ->where('reference_id', $invoice->id)
            ->exists();
        if ($exists) {
            throw new DomainException('Journal sudah ada untuk invoice ini.');
        }

        $invoiceDate = Carbon::parse($invoice->invoice_date);
        $this->periodService->ensureOpen($invoiceDate);

        return DB::transaction(function () use ($invoice, $invoiceDate) {

            $invoice->load(['items.product', 'items.assetCategory', 'expenses', 'supplier']);

            // Pisahkan item ke 3 grup: stock, non-stock, asset.
            // - stock: berstok (ready/preorder/bundle) → masuk FIFO + Dr 1130
            // - non-stock: jasa/non_stock product → Dr expense_account_id produk
            // - asset: is_asset=true → Dr akun aset dari kategori, auto-create FixedAsset draft
            $assetItems    = $invoice->items->filter(fn($it) => (bool) $it->is_asset);
            $stockItems    = $invoice->items->filter(fn($it) => !$it->is_asset && optional($it->product)->type !== 'non_stock');
            $nonStockItems = $invoice->items->filter(fn($it) => !$it->is_asset && optional($it->product)->type === 'non_stock');

            // Validasi: tiap produk non-stock wajib sudah punya expense_account_id
            foreach ($nonStockItems as $it) {
                if (empty($it->product?->expense_account_id)) {
                    throw new DomainException(
                        "Produk non-stock '" . ($it->product?->name ?? $it->product_id) .
                        "' belum punya Akun Beban. Buka setup produk dan pilih akun bebannya sebelum posting invoice."
                    );
                }
            }

            // Validasi: tiap row asset wajib punya kategori dengan akun aset
            foreach ($assetItems as $it) {
                if (!$it->asset_category_id) {
                    throw new DomainException("Baris aset '" . ($it->asset_name ?? '') . "' belum punya kategori.");
                }
                $cat = $it->assetCategory ?? AssetCategory::find($it->asset_category_id);
                if (!$cat || !$cat->fixed_asset_account_id) {
                    throw new DomainException("Kategori aset untuk baris '" . ($it->asset_name ?? '') . "' belum punya akun aset.");
                }
            }

            // 1. STOCK IN via FIFO — hanya untuk item berstok (bukan asset).
            // Stok & FIFO selalu dalam satuan dasar. Item invoice bisa dibeli dalam
            // satuan beli (mis. Lusin) — konversi qty × faktor ke satuan dasar dan
            // bagi unit cost dengan faktor yang sama agar nilai total layer tetap
            // (qty_beli × cost_beli = qty_dasar × cost_dasar).
            $engine = app(InventoryEngine::class);
            foreach ($stockItems as $item) {
                $conv     = $this->conversionToBase($item);
                $baseQty  = (float) $item->qty * $conv;
                $baseCost = $conv > 0 ? (float) $item->final_unit_cost / $conv : (float) $item->final_unit_cost;
                $engine->purchase(
                    $item->product_id,
                    $invoice->warehouse_id,
                    $baseQty,
                    $baseCost,
                    $invoice->invoice_number,
                    $invoice->id
                );
            }

            // 2. JOURNAL HEADER
            $journal = $this->createJournalHeader($invoice, $invoiceDate);
            $invoice->journal_id = $journal->id;
            $invoice->save();

            // 3. JOURNAL LINES — pembagian diskon global proporsional antara stock vs non-stock vs asset.
            $stockGross    = (float) $stockItems->sum(fn($it) => (float) $it->subtotal + (float) $it->landed_cost_share);
            $nonStockGross = (float) $nonStockItems->sum(fn($it) => (float) $it->subtotal + (float) $it->landed_cost_share);
            $assetGross    = (float) $assetItems->sum(fn($it) => (float) $it->subtotal + (float) $it->landed_cost_share);
            $totalGross    = $stockGross + $nonStockGross + $assetGross;
            $gdisc         = (float) ($invoice->global_discount_amount ?? 0);
            $gdiscRatio    = $totalGross > 0 ? ($gdisc / $totalGross) : 0;

            // Non-stock: hitung dulu per-akun (rounded), supaya inventory bisa absorb residual.
            $nonStockByAccount = [];
            foreach ($nonStockItems as $item) {
                $accountId = (int) $item->product->expense_account_id;
                $itemGross = (float) $item->subtotal + (float) $item->landed_cost_share;
                $itemNet   = round($itemGross * (1 - $gdiscRatio), 2);
                $nonStockByAccount[$accountId] = round(($nonStockByAccount[$accountId] ?? 0) + $itemNet, 2);
            }
            $nonStockTotal = array_sum($nonStockByAccount);

            // Asset: hitung per-akun (per kategori). Group by fixed_asset_account_id.
            $assetByAccount = [];
            foreach ($assetItems as $item) {
                $cat = $item->assetCategory ?? AssetCategory::find($item->asset_category_id);
                $accountId = (int) $cat->fixed_asset_account_id;
                $itemGross = (float) $item->subtotal + (float) $item->landed_cost_share;
                $itemNet = round($itemGross * (1 - $gdiscRatio), 2);
                $assetByAccount[$accountId] = round(($assetByAccount[$accountId] ?? 0) + $itemNet, 2);
            }
            $assetTotal = array_sum($assetByAccount);

            // Inventory debit menyerap selisih pembulatan agar Dr stock + Dr non-stock + Dr asset = totalGross − gdisc.
            // Kalau tidak ada stock items (semua asset/non-stock), residual akan selalu = 0 atau diserap di kalkulasi.
            $inventoryDebit = round($totalGross - $gdisc - $nonStockTotal - $assetTotal, 2);

            if ($inventoryDebit > 0) {
                $this->createLine($invoice, $this->getAccountIdByCode($this->account('inventory')), 'debit', $inventoryDebit, 'Persediaan masuk');
            }
            foreach ($nonStockByAccount as $accountId => $amount) {
                if ($amount > 0) {
                    $this->createLine($invoice, $accountId, 'debit', $amount, 'Pembelian bahan non-stock');
                }
            }
            foreach ($assetByAccount as $accountId => $amount) {
                if ($amount > 0) {
                    $this->createLine($invoice, $accountId, 'debit', $amount, 'Perolehan aset tetap');
                }
            }

            // Dr Beban (expenses with mode = direct_expense), per akun
            foreach ($invoice->expenses as $expense) {
                if ($expense->mode === 'direct_expense') {
                    $this->createLine($invoice, $expense->account_id, 'debit', (float) $expense->amount, $expense->description ?? 'Beban langsung');
                }
            }

            // Dr PPN Masukan
            if ($invoice->ppn_amount > 0) {
                $this->createLine($invoice, $this->getAccountIdByCode($this->account('ppn_masukan')), 'debit', (float) $invoice->ppn_amount, 'PPN Masukan');
            }

            // Cr Hutang Usaha (use supplier's account_payable_id if set, else default 2101)
            $apAccountId = $invoice->supplier->account_payable_id
                ?? $this->getAccountIdByCode($this->account('ap'));
            $this->createLine($invoice, $apAccountId, 'credit', (float) $invoice->total, 'Hutang ke supplier');

            // 3.5 AUTO-APPLY DP: kalau supplier punya saldo DP, langsung kurangi AP up to min(invoice_total, dp_balance).
            // Reduksi piutang-supplier (1107) ditangani via journal line Cr 1107, allocation row di-flag is_auto_dp=true
            // supaya void invoice bisa reverse selektif (manual pelunasan tidak ikut ter-reverse).
            $this->autoApplyDp($invoice, $apAccountId);

            // 4. UPDATE PO ITEM qty_invoiced
            if ($invoice->purchase_order_id) {
                foreach ($invoice->items as $item) {
                    if ($item->purchase_order_item_id) {
                        $poItem = PurchaseOrderItem::find($item->purchase_order_item_id);
                        if ($poItem) {
                            $poItem->qty_invoiced = (float) $poItem->qty_invoiced + (float) $item->qty;
                            $poItem->save();
                        }
                    }
                }
            }

            // 5. VALIDATE BALANCE
            $this->validateBalance($journal->id);

            // 6. FINALIZE
            $invoice->status = 'posted';
            $invoice->posted_at = now();
            $invoice->save();

            // 7. AUTO-CREATE FIXED ASSETS untuk baris is_asset=true.
            // Jurnal sudah include Dr akun aset (step 3) — di sini hanya buat master draft.
            $invoice->refresh();
            $hasAssetRow = $invoice->items()->where('is_asset', true)->exists();
            if ($hasAssetRow) {
                app(FixedAssetAcquisitionService::class)->createAssetsFromInvoice($invoice);
            }

            return $invoice;
        });
    }

    /**
     * Void: reverse journal + reverse FIFO. Tolak jika layer sudah dipakai sales.
     */
    public function void(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if ($invoice->status !== 'posted') {
            throw new DomainException('Hanya invoice posted yang bisa di-void.');
        }

        // Cek apakah ada layer dari invoice ini yang sudah dikonsumsi
        $layers = \App\Core\Inventory\StockLayer::where('source_type', 'purchase')
            ->where('source_id', $invoice->id)
            ->get();

        foreach ($layers as $layer) {
            if ((float) $layer->qty_remaining < (float) $layer->qty_in - 0.0001) {
                throw new DomainException('Stok dari invoice ini sudah dipakai (terjual). Gunakan Purchase Return, bukan void.');
            }
        }

        // Cek manual pelunasan (allocations is_auto_dp=false). Auto-DP akan di-reverse otomatis di transaction.
        $manualPaid = SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)
            ->where('is_auto_dp', false)
            ->sum('amount_applied');
        if ((float) $manualPaid > 0) {
            throw new DomainException('Invoice sudah dibayar manual via Payment. Void payment-nya dulu sebelum void invoice.');
        }

        $hasAssetRow = $invoice->items()->where('is_asset', true)->exists();

        return DB::transaction(function () use ($invoice, $layers, $hasAssetRow) {

            // Revert aset tetap (set status 'voided') DI DALAM transaksi: dulu dipanggil
            // sebelum transaksi sehingga bila void utama gagal, aset terlanjur ter-void
            // tapi invoice tetap posted (inkonsisten). Throw dari sini (mis. aset punya
            // riwayat) tetap membatalkan seluruh void & tampil bersih ke user.
            if ($hasAssetRow) {
                app(FixedAssetAcquisitionService::class)->revertAssetsForInvoice($invoice);
            }

            // Reverse auto-DP allocations dulu — kembalikan saldo DP ke supplier.
            $this->reverseAutoDpAllocations($invoice);

            // 1. Reverse FIFO: hapus layers + reverse ledger
            $engine = app(InventoryEngine::class);
            foreach ($layers as $layer) {
                // Reverse ledger: out qty_in
                $engine->ledger(
                    $layer->product_id,
                    $layer->warehouse_id,
                    0,
                    (float) $layer->qty_in,
                    'purchase_void',
                    $invoice->invoice_number,
                    null,
                    $invoice->id
                );
                // Hapus layer
                \App\Models\InventoryCostLayer::where('reference_type', 'purchase')
                    ->where('reference_id', $invoice->id)
                    ->where('product_id', $layer->product_id)
                    ->delete();
                $layer->delete();
            }

            // 2. Void journal (set status='void' + lines tetap atau create reverse)
            if ($invoice->journal_id) {
                $journal = Journal::find($invoice->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }

            // 3. Rollback PO qty_invoiced
            if ($invoice->purchase_order_id) {
                foreach ($invoice->items as $item) {
                    if ($item->purchase_order_item_id) {
                        $poItem = PurchaseOrderItem::find($item->purchase_order_item_id);
                        if ($poItem) {
                            $poItem->qty_invoiced = max(0, (float) $poItem->qty_invoiced - (float) $item->qty);
                            $poItem->save();
                        }
                    }
                }
            }

            $invoice->status = 'void';
            $invoice->voided_at = now();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Auto-apply saldo DP supplier (1107) ke invoice yang baru posted, FIFO per payment_date.
     * Buat Dr AP / Cr 1107 di journal yang sama, plus allocation row dengan is_auto_dp=true.
     * Update payment.allocated_amount/remaining_amount + invoice.paid_amount/outstanding_amount.
     */
    protected function autoApplyDp(PurchaseInvoice $invoice, int $apAccountId): void
    {
        $outstanding = (float) $invoice->outstanding_amount;
        if ($outstanding <= 0) return;

        // lockForUpdate: cegah dua invoice yg di-post bersamaan sama-sama membaca
        // remaining_amount lama lalu mengalokasikan DP yg sama (over-consume → 1107 minus).
        $dpPayments = SupplierPayment::where('supplier_id', $invoice->supplier_id)
            ->where('status', 'posted')
            ->where('remaining_amount', '>', 0)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($dpPayments->isEmpty()) return;

        $totalDp = (float) $dpPayments->sum('remaining_amount');
        $applyTotal = round(min($outstanding, $totalDp), 2);
        if ($applyTotal <= 0) return;

        $remaining = $applyTotal;
        foreach ($dpPayments as $payment) {
            if ($remaining <= 0.0001) break;
            $applyHere = round(min($remaining, (float) $payment->remaining_amount), 2);
            if ($applyHere <= 0) continue;

            SupplierPaymentAllocation::create([
                'supplier_payment_id' => $payment->id,
                'purchase_invoice_id' => $invoice->id,
                'amount_applied' => $applyHere,
                'is_auto_dp' => true,
            ]);

            $payment->allocated_amount = (float) $payment->allocated_amount + $applyHere;
            $payment->remaining_amount = (float) $payment->remaining_amount - $applyHere;
            $payment->save();

            $remaining -= $applyHere;
        }

        // Journal lines: Dr 2101 AP, Cr 1107 Uang Muka
        $dpAccountId = $invoice->supplier->account_dp_id
            ?? $this->getAccountIdByCode($this->account('dp_supplier'));

        $this->createLine($invoice, $apAccountId, 'debit', $applyTotal, 'Auto-apply DP supplier');
        $this->createLine($invoice, $dpAccountId, 'credit', $applyTotal, 'DP teralokasi ke ' . $invoice->invoice_number);

        $invoice->paid_amount = (float) $invoice->paid_amount + $applyTotal;
        $invoice->outstanding_amount = max(0, (float) $invoice->outstanding_amount - $applyTotal);
        $invoice->save();
    }

    /**
     * Reverse semua allocation auto-DP yang dibuat saat posting invoice ini.
     * Manual pelunasan (is_auto_dp=false) TIDAK di-reverse — user harus void payment-nya dulu.
     */
    protected function reverseAutoDpAllocations(PurchaseInvoice $invoice): void
    {
        $allocations = SupplierPaymentAllocation::where('purchase_invoice_id', $invoice->id)
            ->where('is_auto_dp', true)
            ->get();

        foreach ($allocations as $alloc) {
            $payment = SupplierPayment::find($alloc->supplier_payment_id);
            if ($payment) {
                $payment->allocated_amount = max(0, (float) $payment->allocated_amount - (float) $alloc->amount_applied);
                $payment->remaining_amount = (float) $payment->remaining_amount + (float) $alloc->amount_applied;
                $payment->save();
            }

            $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $alloc->amount_applied);
            $invoice->outstanding_amount = (float) $invoice->outstanding_amount + (float) $alloc->amount_applied;

            $alloc->delete();
        }

        if ($allocations->isNotEmpty()) {
            $invoice->save();
        }
    }

    protected function createJournalHeader(PurchaseInvoice $invoice, Carbon $date): Journal
    {
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();
        if (!$period) {
            throw new \Exception('Accounting period tidak ditemukan.');
        }

        return Journal::create([
            'journal_number' => $this->generateJournalNumber(),
            'date' => $invoice->invoice_date,
            'period_id' => $period->id,
            'reference_type' => 'purchase_invoice',
            'reference_id' => $invoice->id,
            'reference_number' => $invoice->invoice_number,
            'description' => 'Purchase Invoice ' . $invoice->invoice_number,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    protected function createLine(PurchaseInvoice $invoice, int $accountId, string $type, float $amount, ?string $description = null): void
    {
        if ($amount <= 0) return;
        JournalLine::create([
            'journal_id' => $invoice->journal_id,
            'account_id' => $accountId,
            'debit' => $type === 'debit' ? $amount : 0,
            'credit' => $type === 'credit' ? $amount : 0,
            'description' => $description,
            'reference_type' => 'purchase_invoice',
            'reference_id' => $invoice->id,
            'reference_number' => $invoice->invoice_number,
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
        $prefix = 'PJV/' . now()->format('Y/m') . '/';
        $latest = Journal::where('journal_number', 'LIKE', $prefix . '%')
            ->orderByDesc('journal_number')
            ->value('journal_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    protected function getAccountIdByCode(string $code): int
    {
        $id = Account::where('code', $code)->value('id');
        if (!$id) {
            throw new \Exception("Account code NOT found: $code");
        }
        return $id;
    }
}
