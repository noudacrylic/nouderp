<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Purchasing\Models\SupplierOverpayment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class SupplierPaymentService
{
    public function __construct(
        protected PeriodService $periodService
    ) {}

    protected function account(string $key): string
    {
        return match ($key) {
            'ap'                  => '2101',
            'dp_supplier'         => '1107',
            'overpay_supplier'    => '1108',
            default => throw new \Exception("Account mapping not found: $key"),
        };
    }

    public function generateNumber(): string
    {
        $prefix = 'PAY/' . now()->format('Y/m') . '/';
        // Pakai MAX nomor existing dengan prefix yang sama, bukan count by date.
        $latest = SupplierPayment::where('payment_number', 'LIKE', $prefix . '%')
            ->orderByDesc('payment_number')
            ->value('payment_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Hitung breakdown payment:
     *   total_dana     = amount + used_balance
     *   allocated      = sum(allocations.amount_applied) → Dr AP
     *   overpay        = (input dari form) → Dr 1108 (piutang lebih bayar)
     *   remaining (DP) = total_dana - allocated - overpay → Dr 1107
     *
     * Throw kalau remaining < 0 (alokasi+overpay > dana).
     */
    protected function computeBreakdown(array $data): array
    {
        $amount      = round((float) ($data['amount'] ?? 0), 2);
        $usedBalance = round((float) ($data['used_balance'] ?? 0), 2);
        $overpay     = round((float) ($data['overpay'] ?? 0), 2);

        $allocations = $data['allocations'] ?? [];
        $allocated = 0;
        foreach ($allocations as $a) {
            $allocated += (float) ($a['amount_applied'] ?? 0);
        }
        $allocated = round($allocated, 2);

        if ($amount < 0)      throw new DomainException('Nominal pembayaran tidak boleh negatif.');
        if ($usedBalance < 0) throw new DomainException('Saldo dipakai tidak boleh negatif.');
        if ($overpay < 0)     throw new DomainException('Overpay tidak boleh negatif.');

        $totalDana = round($amount + $usedBalance, 2);
        if ($totalDana <= 0) {
            throw new DomainException('Total dana (amount + saldo dipakai) harus lebih dari 0.');
        }

        $remaining = round($totalDana - $allocated - $overpay, 2);
        if ($remaining < -0.0001) {
            throw new DomainException('Total alokasi + overpay melebihi dana yang tersedia.');
        }
        if ($remaining < 0) $remaining = 0;

        return [
            'amount'      => $amount,
            'used_balance'=> $usedBalance,
            'overpay'     => $overpay,
            'allocated'   => $allocated,
            'remaining'   => $remaining,
        ];
    }

    public function createDraft(array $data): SupplierPayment
    {
        return DB::transaction(function () use ($data) {
            $b = $this->computeBreakdown($data);

            // Cek saldo cukup utk used_balance
            if ($b['used_balance'] > 0) {
                $balance = $this->getSupplierOverpayBalance((int) $data['supplier_id']);
                if ($b['used_balance'] > $balance + 0.0001) {
                    throw new DomainException('Saldo piutang supplier tidak cukup. Saldo: ' . number_format($balance, 0, ',', '.'));
                }
            }

            $payment = SupplierPayment::create([
                'payment_number'  => $data['payment_number'] ?? $this->generateNumber(),
                'payment_date'    => $data['payment_date'],
                'supplier_id'     => $data['supplier_id'],
                'payment_method'  => $data['payment_method'] ?? 'bank',
                'bank_account_id' => $data['bank_account_id'],
                'amount'          => $b['amount'],
                'allocated_amount'=> $b['allocated'],
                'remaining_amount'=> $b['remaining'],
                'overpay'         => $b['overpay'],
                'used_balance'    => $b['used_balance'],
                'status'          => 'draft',
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach (($data['allocations'] ?? []) as $a) {
                if ((float) $a['amount_applied'] <= 0) continue;
                SupplierPaymentAllocation::create([
                    'supplier_payment_id' => $payment->id,
                    'purchase_invoice_id' => $a['purchase_invoice_id'],
                    'amount_applied'      => $a['amount_applied'],
                ]);
            }

            return $payment;
        });
    }

    public function update(SupplierPayment $payment, array $data): SupplierPayment
    {
        if ($payment->status !== 'draft') {
            throw new DomainException('Hanya payment draft yang boleh diedit.');
        }
        return DB::transaction(function () use ($payment, $data) {
            $payment->allocations()->delete();

            $b = $this->computeBreakdown($data);

            if ($b['used_balance'] > 0) {
                $balance = $this->getSupplierOverpayBalance((int) $data['supplier_id']);
                if ($b['used_balance'] > $balance + 0.0001) {
                    throw new DomainException('Saldo piutang supplier tidak cukup. Saldo: ' . number_format($balance, 0, ',', '.'));
                }
            }

            $payment->update([
                'payment_date'    => $data['payment_date'],
                'supplier_id'     => $data['supplier_id'],
                'payment_method'  => $data['payment_method'] ?? 'bank',
                'bank_account_id' => $data['bank_account_id'],
                'amount'          => $b['amount'],
                'allocated_amount'=> $b['allocated'],
                'remaining_amount'=> $b['remaining'],
                'overpay'         => $b['overpay'],
                'used_balance'    => $b['used_balance'],
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach (($data['allocations'] ?? []) as $a) {
                if ((float) $a['amount_applied'] <= 0) continue;
                SupplierPaymentAllocation::create([
                    'supplier_payment_id' => $payment->id,
                    'purchase_invoice_id' => $a['purchase_invoice_id'],
                    'amount_applied'      => $a['amount_applied'],
                ]);
            }

            return $payment;
        });
    }

    public function post(SupplierPayment $payment): SupplierPayment
    {
        if ($payment->status === 'posted') {
            throw new DomainException('Payment sudah posted.');
        }
        if ($payment->status === 'void') {
            throw new DomainException('Payment sudah voided.');
        }

        $exists = Journal::where('reference_type', 'supplier_payment')
            ->where('reference_id', $payment->id)
            ->exists();
        if ($exists) {
            throw new DomainException('Journal sudah ada untuk payment ini.');
        }

        $payDate = Carbon::parse($payment->payment_date);
        $this->periodService->ensureOpen($payDate);

        return DB::transaction(function () use ($payment, $payDate) {

            $payment->load(['allocations.invoice', 'supplier']);

            // Validate: total allocations cannot exceed each invoice's outstanding
            foreach ($payment->allocations as $alloc) {
                $inv = $alloc->invoice;
                if (!$inv || $inv->status !== 'posted') {
                    throw new DomainException('Alokasi ke invoice yang belum posted tidak diperbolehkan.');
                }
                if ((float) $alloc->amount_applied > (float) $inv->outstanding_amount + 0.0001) {
                    throw new DomainException("Alokasi ke invoice {$inv->invoice_number} melebihi outstanding.");
                }
            }

            // Re-cek saldo piutang cukup utk used_balance (lock baris kalau perlu)
            if ((float) $payment->used_balance > 0) {
                $balance = $this->getSupplierOverpayBalance((int) $payment->supplier_id);
                if ((float) $payment->used_balance > $balance + 0.0001) {
                    throw new DomainException('Saldo piutang supplier tidak cukup saat post. Saldo: ' . number_format($balance, 0, ',', '.'));
                }
            }

            // Create journal
            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)
                ->first();
            if (!$period) throw new \Exception('Accounting period tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => $this->generateJournalNumber(),
                'date'             => $payment->payment_date,
                'period_id'        => $period->id,
                'reference_type'   => 'supplier_payment',
                'reference_id'     => $payment->id,
                'reference_number' => $payment->payment_number,
                'description'      => 'Supplier Payment ' . $payment->payment_number,
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            $payment->journal_id = $journal->id;

            // Dr 2101 AP per alokasi (kurangi hutang)
            $apAccountId = $payment->supplier->account_payable_id
                ?? $this->getAccountIdByCode($this->account('ap'));

            foreach ($payment->allocations as $alloc) {
                $this->createLine($journal, $payment, $apAccountId, 'debit', (float) $alloc->amount_applied,
                    'Pelunasan invoice ' . $alloc->invoice->invoice_number);

                // Update invoice paid_amount & outstanding
                $inv = $alloc->invoice;
                $inv->paid_amount = (float) $inv->paid_amount + (float) $alloc->amount_applied;
                $inv->outstanding_amount = max(0, (float) $inv->outstanding_amount - (float) $alloc->amount_applied);
                $inv->save();
            }

            // Dr 1107 Uang Muka Supplier untuk sisa DP (kalau ada)
            if ((float) $payment->remaining_amount > 0) {
                $dpAccountId = $payment->supplier->account_dp_id
                    ?? $this->getAccountIdByCode($this->account('dp_supplier'));
                $this->createLine($journal, $payment, $dpAccountId, 'debit', (float) $payment->remaining_amount,
                    'Uang muka supplier');
            }

            // Dr 1108 Piutang Lebih Bayar Supplier untuk overpay (kalau ada)
            $overpayAccountId = $this->getAccountIdByCode($this->account('overpay_supplier'));
            if ((float) $payment->overpay > 0) {
                $this->createLine($journal, $payment, $overpayAccountId, 'debit', (float) $payment->overpay,
                    'Kelebihan bayar supplier');
            }

            // Cr 1108 saldo piutang yang dipakai
            if ((float) $payment->used_balance > 0) {
                $this->createLine($journal, $payment, $overpayAccountId, 'credit', (float) $payment->used_balance,
                    'Penggunaan saldo piutang supplier');
            }

            // Cr Bank/Cash sebesar amount (kalau > 0)
            if ((float) $payment->amount > 0) {
                $this->createLine($journal, $payment, $payment->bank_account_id, 'credit', (float) $payment->amount,
                    'Pembayaran via ' . $payment->payment_method);
            }

            // Update pool 1108 (supplier_overpayments ledger)
            if ((float) $payment->overpay > 0) {
                SupplierOverpayment::create([
                    'supplier_id' => $payment->supplier_id,
                    'amount'      => (float) $payment->overpay,
                    'reference'   => $payment->payment_number,
                    'note'        => 'Kelebihan bayar dari ' . $payment->payment_number,
                ]);
            }
            if ((float) $payment->used_balance > 0) {
                SupplierOverpayment::create([
                    'supplier_id' => $payment->supplier_id,
                    'amount'      => -1 * (float) $payment->used_balance,
                    'reference'   => $payment->payment_number,
                    'note'        => 'Pemakaian saldo untuk ' . $payment->payment_number,
                ]);
            }

            // Validate balance
            $this->validateBalance($journal->id);

            $payment->status = 'posted';
            $payment->posted_at = now();
            $payment->save();

            return $payment;
        });
    }

    public function void(SupplierPayment $payment): SupplierPayment
    {
        if ($payment->status !== 'posted') {
            throw new DomainException('Hanya payment posted yang bisa di-void.');
        }

        return DB::transaction(function () use ($payment) {
            $payment->load('allocations.invoice');

            // Rollback paid_amount/outstanding di invoice
            foreach ($payment->allocations as $alloc) {
                $inv = $alloc->invoice;
                if ($inv) {
                    $inv->paid_amount = max(0, (float) $inv->paid_amount - (float) $alloc->amount_applied);
                    $inv->outstanding_amount = (float) $inv->outstanding_amount + (float) $alloc->amount_applied;
                    $inv->save();
                }
            }

            // Reverse pool 1108: hapus ledger entries milik payment ini
            SupplierOverpayment::where('supplier_id', $payment->supplier_id)
                ->where('reference', $payment->payment_number)
                ->delete();

            // Void journal
            if ($payment->journal_id) {
                $journal = Journal::find($payment->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }

            $payment->status = 'void';
            $payment->voided_at = now();
            $payment->save();

            return $payment;
        });
    }

    /**
     * Get available DP balance for a supplier (1107 Uang Muka).
     */
    public function getSupplierDpBalance(int $supplierId): float
    {
        $totalDp = SupplierPayment::where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->sum('remaining_amount');
        return (float) $totalDp;
    }

    /**
     * Get available overpay balance for a supplier (1108 Piutang Lebih Bayar).
     */
    public function getSupplierOverpayBalance(int $supplierId): float
    {
        return (float) SupplierOverpayment::where('supplier_id', $supplierId)->sum('amount');
    }

    /**
     * Get list of open invoices for a supplier (outstanding > 0).
     */
    public function getOpenInvoices(int $supplierId)
    {
        return PurchaseInvoice::where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->where('outstanding_amount', '>', 0)
            ->orderBy('invoice_date')
            ->get();
    }

    protected function createLine(Journal $journal, SupplierPayment $payment, int $accountId, string $type, float $amount, ?string $description = null): void
    {
        if ($amount <= 0) return;
        JournalLine::create([
            'journal_id'      => $journal->id,
            'account_id'      => $accountId,
            'debit'           => $type === 'debit' ? $amount : 0,
            'credit'          => $type === 'credit' ? $amount : 0,
            'description'     => $description,
            'reference_type'  => 'supplier_payment',
            'reference_id'    => $payment->id,
            'reference_number'=> $payment->payment_number,
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
        $prefix = 'PAY/' . now()->format('Y/m') . '/';
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
