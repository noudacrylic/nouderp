<?php

namespace App\Modules\Sales\Services;

use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\CustomerBilling;
use App\Core\Journal\JournalPostingService;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use App\Enums\InvoiceStatusEnum;
use Illuminate\Support\Facades\DB;
use Exception;

class CustomerPaymentService
{
    public function __construct(
        protected AllocationService $allocationService,
        protected JournalPostingService $journalService,
        protected BillingService $billingService
    ) {
    }

    /**
     * Create a draft customer payment.
     */
    public function create(array $dto): CustomerPayment
    {
        return CustomerPayment::create([
            'payment_number' => $dto['payment_number'] ?? $this->generateNumber(),
            'customer_id' => $dto['customer_id'],
            'date' => $dto['date'],
            'cash_account_id' => $dto['cash_account_id'],
            'amount' => $dto['amount'],
            'admin_fee' => $dto['admin_fee'] ?? 0,
            'payment_type' => $dto['payment_type'] ?? 'invoice',
            'sales_order_id' => $dto['sales_order_id'] ?? null,
            'status' => 'draft',
            'notes' => $dto['notes'] ?? null,
        ]);
    }

    /**
     * Post the payment using the 10-step Payment Engine logic.
     */
    public function post(int $paymentId, ?int $billingId = null, array $invoiceIds = [], array $soIds = [], bool $useBalance = true): CustomerPayment
    {
        return DB::transaction(function () use ($paymentId, $billingId, $invoiceIds, $soIds, $useBalance) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== 'draft') {
                throw new Exception("Payment is not in draft status.");
            }

            // 🎯 VALIDASI TIPIKAL (User Request)
            if ($payment->payment_type === 'advance') {
                if (empty($soIds) && !$billingId) {
                    throw new Exception('Sales Order atau Billing wajib dipilih untuk uang muka.');
                }
            }

            if (($payment->admin_fee ?? 0) < 0) {
                throw new Exception("Biaya admin tidak valid.");
            }
            if (($payment->admin_fee ?? 0) > $payment->amount) {
                throw new Exception("Biaya admin tidak boleh melebihi jumlah pembayaran.");
            }

            if ($payment->payment_type === 'invoice') {
                if (empty($invoiceIds) && !$billingId) {
                    throw new Exception('Invoice atau Billing wajib dipilih untuk pelunasan.');
                }
            }

            $customerId = $payment->customer_id;
            $amount = $payment->amount;

            // 🎯 STEP 1 — HITUNG DASAR
            $billingIds = $billingId ? [$billingId] : [];
            $total = $this->calculateTotal($invoiceIds, $billingIds, $soIds);
            
            $customerBalance = \App\Models\CustomerOverpayment::where('customer_id', $customerId)->sum('amount');
            if ($customerBalance < 0) throw new Exception("Saldo customer tidak boleh minus.");

            $saldoDipakai = 0;
            if ($useBalance) {
                // Determine if user actually wants to use balance (amount < total)
                $deficit = round($total - $amount, 2);
                if ($deficit > 0.01) {
                    $saldoDipakai = min($customerBalance, $deficit);
                }
            }

            $totalDialokasikan = $saldoDipakai + $amount;
            
            $overpay = round($totalDialokasikan - $total, 2);
            if ($overpay < 0) $overpay = 0;

            // 🎯 STEP 2 — VALIDASI
            if ((count($invoiceIds) + count($billingIds)) > 1 && $totalDialokasikan < ($total - 0.01)) {
                throw new Exception("Multi invoice harus bayar penuh atau gunakan billing. Kurang: Rp " . number_format($total - $totalDialokasikan, 0, ',', '.'));
            }

            // 🎯 STEP 4 — BUAT PAYMENT (Update draft)
            $payment->update([
                'used_balance' => $saldoDipakai,
                'overpay' => $overpay,
                'status' => 'posted'
            ]);

            // 🎯 STEP 5 & 6 & 7 — JOURNAL ENTRIES
            $this->postJournal($payment, $total, $saldoDipakai, $overpay, $invoiceIds, $soIds, $billingIds);

            // 🎯 STEP 6 — PAKAI SALDO CUSTOMER (Decrement pool)
            if ($saldoDipakai > 0) {
                \App\Models\CustomerOverpayment::create([
                    'customer_id' => $customerId,
                    'amount' => -$saldoDipakai,
                    'reference' => $payment->payment_number,
                    'note' => "Penggunaan saldo untuk pembayaran {$payment->payment_number}"
                ]);
            }

            // 🎯 STEP 7 — OVERPAY (Increment pool)
            if ($overpay > 0.01) {
                \App\Models\CustomerOverpayment::create([
                    'customer_id' => $customerId,
                    'amount' => $overpay,
                    'reference' => $payment->payment_number,
                    'note' => "Kelebihan bayar dari {$payment->payment_number}"
                ]);
            }

            // 🎯 STEP 5 CASE 3 — BILLING
            if (!empty($billingIds)) {
                $this->billingService->process($billingIds, $totalDialokasikan, $payment->id);
            }

            // 🎯 STEP 8 — ALLOCATION ENGINE
            if (!empty($invoiceIds) || !empty($soIds)) {
                $this->allocateToItems($payment, $invoiceIds, $soIds, $totalDialokasikan);
            }

            return $payment;
        });
    }

    protected function calculateTotal(array $invoiceIds, array $billingIds, array $soIds): float
    {
        $total = 0;

        if (!empty($invoiceIds)) {
            $total += SalesInvoice::whereIn('id', $invoiceIds)->get()->sum(function($inv) {
                return round($inv->grand_total - ($inv->advance_applied ?? 0) - $inv->paid_amount, 2);
            });
        }

        if (!empty($billingIds)) {
            $total += CustomerBilling::whereIn('id', $billingIds)->sum('total_amount');
        }

        if (!empty($soIds)) {
            $total += \App\Modules\Sales\Models\SalesOrder::whereIn('id', $soIds)->get()->sum(function($so) {
                return round($so->grand_total - $so->paid_amount, 2);
            });
        }

        return round($total, 2);
    }

    /**
     * Bangun label dokumen untuk keterangan jurnal:
     * - Pelunasan (invoice) -> nomor Invoice
     * - Uang Muka (advance)  -> nomor Sales Order
     * Nomor payment tetap tersimpan sebagai reference_number (kolom REF di ledger),
     * jadi keterangan cukup menampilkan dokumen sumber agar mudah di-tracking.
     */
    public function documentReference(CustomerPayment $payment, array $invoiceIds, array $soIds): string
    {
        // Pelunasan -> Invoice
        if ($payment->payment_type === 'invoice' && !empty($invoiceIds)) {
            $numbers = SalesInvoice::whereIn('id', $invoiceIds)->pluck('invoice_number')->filter()->all();
            if (!empty($numbers)) return implode(', ', $numbers);
        }

        // Uang Muka -> Sales Order
        if (!empty($soIds)) {
            $numbers = \App\Modules\Sales\Models\SalesOrder::whereIn('id', $soIds)->pluck('order_number')->filter()->all();
            if (!empty($numbers)) return implode(', ', $numbers);
        }

        // Fallback: masih ada invoice walau tipe berbeda (mis. billing multi-invoice)
        if (!empty($invoiceIds)) {
            $numbers = SalesInvoice::whereIn('id', $invoiceIds)->pluck('invoice_number')->filter()->all();
            if (!empty($numbers)) return implode(', ', $numbers);
        }

        // Fallback terakhir: nomor payment
        return $payment->payment_number;
    }

    protected function postJournal(CustomerPayment $payment, float $total, float $saldoUsed, float $overpay, array $invoiceIds, array $soIds, array $billingIds)
    {
        $arAccountId = DB::table('accounts')->where('code', '1120')->value('id'); // Piutang
        $advanceAccountId = DB::table('accounts')->where('code', '2105')->value('id'); // Uang Muka Customer
        $overpayAccountId = DB::table('accounts')->where('code', '2106')->value('id'); // Kelebihan Bayar Customer

        // Label dokumen sumber (Invoice utk pelunasan, SO utk uang muka)
        $docRef = $this->documentReference($payment, $invoiceIds, $soIds);
        $typeLabel = $payment->payment_type === 'invoice' ? 'Pelunasan' : 'Uang Muka';

        $totalToAllocate = round($payment->amount + $saldoUsed, 2);
        $journalLines = [];

        // 🎯 DEBIT SIDE
        // CASH IN (Debit Kas & Beban Admin)
        if ($payment->amount > 0) {
            $adminFee = (float)($payment->admin_fee ?? 0);
            $netAmount = round($payment->amount - $adminFee, 2);

            if ($netAmount > 0) {
                $journalLines[] = new JournalLineDTO(
                    account_id: $payment->cash_account_id,
                    debit: $netAmount,
                    credit: 0,
                    description: "Penerimaan Kas (Net) - {$docRef}"
                );
            }

            if ($adminFee > 0) {
                // Resolve expense account from setting
                $setting = \App\Models\PaymentFeeSetting::where('cash_account_id', $payment->cash_account_id)->first();
                $expenseAccountId = $setting?->expense_account_id;

                if (!$expenseAccountId) {
                    throw new \Exception("Akun beban belum di setting untuk kas ini ({$payment->cash_account_id})");
                }

                $journalLines[] = new JournalLineDTO(
                    account_id: $expenseAccountId,
                    debit: $adminFee,
                    credit: 0,
                    description: "Biaya Admin/Bank - {$docRef}"
                );
            }
        }

        // SALDO USED (Debit Kelebihan Bayar Customer - Karena pool berasal dari 2106)
        if ($saldoUsed > 0) {
            $journalLines[] = new JournalLineDTO(
                account_id: $overpayAccountId,
                debit: $saldoUsed,
                credit: 0,
                description: "Penggunaan Saldo (Kelebihan Bayar) - {$payment->payment_number}"
            );
        }

        // 🎯 CREDIT SIDE
        $remainingCredit = $totalToAllocate;

        // 1. OVERPAYMENT (Credit Kelebihan Bayar Customer)
        if ($overpay > 0.01) {
            $journalLines[] = new JournalLineDTO(
                account_id: $overpayAccountId,
                debit: 0,
                credit: $overpay,
                description: "Kelebihan Bayar (Overpayment) - {$payment->payment_number}"
            );
            $remainingCredit = round($remainingCredit - $overpay, 2);
        }

        // 2. CATEGORIZED CREDIT (Based on payment_type)
        if ($remainingCredit > 0.01) {
            if ($payment->payment_type === 'invoice') {
                // Pelunasan Invoice / Billing Invoice -> Credit Piutang
                $journalLines[] = new JournalLineDTO(
                    account_id: $arAccountId,
                    debit: 0,
                    credit: $remainingCredit,
                    description: "Pelunasan Piutang - {$docRef}"
                );
            } else {
                // Uang Muka SO / Billing SO -> Credit Uang Muka Penjualan
                $journalLines[] = new JournalLineDTO(
                    account_id: $advanceAccountId,
                    debit: 0,
                    credit: $remainingCredit,
                    description: "Penerimaan Uang Muka SO - {$docRef}"
                );
            }
        }

        // 🎯 VALIDASI AKHIR (Safety check)
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($journalLines as $line) {
            $totalDebit += $line->debit;
            $totalCredit += $line->credit;
        }

        // Bandingkan dgn toleransi (float strict !== rawan false-positive).
        if (abs(round($totalDebit, 2) - round($totalCredit, 2)) > 0.005) {
            throw new Exception("Journal not balanced: Debit " . number_format($totalDebit, 2) . " vs Credit " . number_format($totalCredit, 2));
        }

        $journalDto = new JournalEntryDTO(
            date: \Carbon\Carbon::parse($payment->date)->format('Y-m-d'),
            reference_type: 'customer_payment',
            reference_id: $payment->id,
            description: "{$typeLabel} - {$docRef}",
            lines: $journalLines,
            reference_number: $payment->payment_number
        );

        $this->journalService->post($journalDto);
    }

    protected function allocateToItems(CustomerPayment $payment, array $invoiceIds, array $soIds, float $amount): void
    {
        $remaining = $amount;

        // 1. Invoices
        if (!empty($invoiceIds)) {
            $allocs = $this->allocationService->allocate($payment->customer_id, $remaining, $invoiceIds);
            foreach ($allocs as $row) {
                \App\Models\CustomerPaymentAllocation::create([
                    'customer_payment_id' => $payment->id,
                    'invoice_id' => $row['invoice_id'],
                    'amount' => $row['amount'],
                ]);

                $invoice = SalesInvoice::lockForUpdate()->find($row['invoice_id']);
                $invoice->paid_amount += $row['amount'];
                // Status dokumen tetap POSTED (atau tidak berubah), 
                // karena status pembayaran dihitung dinamis di view.
                $invoice->save();

                $remaining -= $row['amount'];
            }
        }

        // 2. Sales Orders (Advances)
        if (!empty($soIds) && $remaining > 0) {
            foreach ($soIds as $soId) {
                if ($remaining <= 0) break;

                $so = \App\Modules\Sales\Models\SalesOrder::lockForUpdate()->find($soId);
                $soRemaining = round($so->grand_total - $so->paid_amount, 2);
                if ($soRemaining <= 0) continue;

                $alloc = min($remaining, $soRemaining);
                
                \App\Models\CustomerPaymentAllocation::create([
                    'customer_payment_id' => $payment->id,
                    'sales_order_id' => $soId,
                    'amount' => $alloc,
                ]);

                $so->paid_amount += $alloc;
                $so->save();

                // ⚡ SYNC TO SALES_ADVANCES (Required for InvoicePostingService application)
                \App\Modules\Sales\Models\SalesAdvance::create([
                    'advance_number' => 'ADV-' . $payment->payment_number,
                    'sales_order_id' => $soId,
                    'customer_id' => $payment->customer_id,
                    'bank_account_id' => $payment->cash_account_id,
                    'advance_date' => $payment->date,
                    'amount' => $alloc,
                    'status' => 'posted',
                    'notes' => "Otomatis dari Payment {$payment->payment_number}"
                ]);

                // 🟢 AUTOMASI PREORDER: OP preorder auto dibuat oleh SalesAdvanceObserver begitu
                // SalesAdvance di atas berstatus 'posted' (berlaku universal: manual / payment link /
                // marketplace). Di sini cukup konfirmasi OP custom DRAFT yang dibuat manual (kalau ada).
                $this->autoConfirmCustomProductionOrders($soId, $payment->payment_number);

                $remaining -= $alloc;
            }
        }
    }

    /**
     * Auto-confirm setiap Production Order tipe "custom" yang masih draft dan terhubung ke SO ini.
     * Dipakai saat DP/uang muka diposting agar status preorder otomatis berpindah ke "Dikonfirmasi".
     *
     * Catatan: hanya membalik status (soft confirm) — TIDAK menjalankan konsumsi material via FIFO
     * dan TIDAK memposting jurnal Dr. WIP / Cr. Persediaan. Ini disengaja karena untuk preorder
     * material biasanya belum dibeli saat DP masuk; konsumsi/jurnal akan dilakukan nanti saat
     * produksi benar-benar dimulai (start step) atau via konfirmasi manual.
     */
    protected function autoConfirmCustomProductionOrders(int $soId, string $paymentNumber): void
    {
        $orders = \App\Modules\Production\Models\ProductionOrder::where('sales_order_id', $soId)
            ->where('type', 'custom')
            ->where('status', 'draft')
            ->get();

        if ($orders->isEmpty()) return;

        foreach ($orders as $order) {
            $order->update(['status' => 'confirmed']);

            \Illuminate\Support\Facades\Log::info(
                "Production Order otomatis dikonfirmasi via DP",
                [
                    'production_order_id' => $order->id,
                    'order_number'        => $order->order_number,
                    'sales_order_id'      => $soId,
                    'payment_number'      => $paymentNumber,
                ]
            );
        }
    }

    private function generateNumber(): string
    {
        // Urut per bulan (MAX suffix + 1), BUKAN acak — rand(1,9999) sering bentrok
        // begitu jumlah payment/bulan padat (unique constraint gagal). Ekstrak suffix
        // numerik (aman >9999) + guard anti-tabrakan ringan untuk race jarang.
        $prefix = 'PAY/' . date('Y/m') . '/';

        $max = (int) CustomerPayment::where('payment_number', 'like', $prefix . '%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(payment_number, '/', -1) AS UNSIGNED)) as m")
            ->value('m');

        $next = $max + 1;
        do {
            $number = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (CustomerPayment::where('payment_number', $number)->exists());

        return $number;
    }
}
