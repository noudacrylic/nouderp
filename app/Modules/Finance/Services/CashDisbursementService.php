<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashDisbursement;
use App\Modules\Finance\Models\CashDisbursementLine;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use App\Models\CustomerOverpayment;
use App\Models\FreightSetting;
use App\Models\SalesInvoice;
use App\Enums\AccountCodeEnum;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class CashDisbursementService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService
    ) {}

    public function createDraft(array $data): CashDisbursement
    {
        return DB::transaction(function () use ($data) {
            $this->validateLines($data);

            $cd = CashDisbursement::create([
                'number'          => $this->generateNumber(CashDisbursement::class, $this->prefix($data['type'])),
                'date'            => $data['date'],
                'type'            => $data['type'],
                'cash_account_id' => $data['cash_account_id'],
                'customer_id'     => $data['customer_id'] ?? null,
                'payee'           => $data['payee'] ?? null,
                'reference'       => $data['reference'] ?? null,
                'total'           => $this->sumLines($data['lines'] ?? []),
                'status'          => 'draft',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($data['lines'] as $line) {
                CashDisbursementLine::create([
                    'cash_disbursement_id'    => $cd->id,
                    'account_id'              => $line['account_id'],
                    'sales_invoice_id'        => $line['sales_invoice_id'] ?? null,
                    'customer_overpayment_id' => $line['customer_overpayment_id'] ?? null,
                    'amount'                  => $line['amount'],
                    'description'             => $line['description'] ?? null,
                ]);
            }

            return $cd;
        });
    }

    public function update(CashDisbursement $cd, array $data): CashDisbursement
    {
        if (!$cd->isDraft()) {
            throw new DomainException('Hanya draft yang bisa diedit.');
        }
        return DB::transaction(function () use ($cd, $data) {
            $this->validateLines($data);
            $cd->lines()->delete();
            $cd->update([
                'date'            => $data['date'],
                'type'            => $data['type'],
                'cash_account_id' => $data['cash_account_id'],
                'customer_id'     => $data['customer_id'] ?? null,
                'payee'           => $data['payee'] ?? null,
                'reference'       => $data['reference'] ?? null,
                'total'           => $this->sumLines($data['lines'] ?? []),
                'notes'           => $data['notes'] ?? null,
            ]);
            foreach ($data['lines'] as $line) {
                CashDisbursementLine::create([
                    'cash_disbursement_id'    => $cd->id,
                    'account_id'              => $line['account_id'],
                    'sales_invoice_id'        => $line['sales_invoice_id'] ?? null,
                    'customer_overpayment_id' => $line['customer_overpayment_id'] ?? null,
                    'amount'                  => $line['amount'],
                    'description'             => $line['description'] ?? null,
                ]);
            }
            return $cd;
        });
    }

    public function post(CashDisbursement $cd): CashDisbursement
    {
        if ($cd->isPosted()) throw new DomainException('Sudah diposting.');
        if ($cd->isVoid())   throw new DomainException('Sudah di-void.');

        $payDate = Carbon::parse($cd->date);
        $this->periodService->ensureOpen($payDate);

        $exists = Journal::where('reference_type', 'cash_disbursement')
            ->where('reference_id', $cd->id)
            ->where('status', '!=', 'void')
            ->exists();
        if ($exists) throw new DomainException('Journal sudah ada untuk dokumen ini.');

        return DB::transaction(function () use ($cd, $payDate) {
            $cd->load('lines');

            // Validasi customer_refund: saldo overpay customer cukup
            if ($cd->type === 'customer_refund') {
                if (!$cd->customer_id) throw new DomainException('Customer wajib dipilih untuk refund.');
                $balance = $this->getCustomerOverpayBalance($cd->customer_id);
                if ((float) $cd->total > $balance + 0.0001) {
                    throw new DomainException('Saldo overpay customer tidak cukup. Saldo: ' . number_format($balance, 0, ',', '.'));
                }
            }

            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'CD-J-' . $cd->number,
                'date'             => $cd->date,
                'period_id'        => $period->id,
                'reference_type'   => 'cash_disbursement',
                'reference_id'     => $cd->id,
                'reference_number' => $cd->number,
                'description'      => $this->journalDescription($cd),
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            if ($cd->type === 'freight') {
                $this->postFreightLines($journal, $cd);
            } else {
                // Lines: Dr akun pilihan tiap line
                foreach ($cd->lines as $line) {
                    JournalLine::create([
                        'journal_id'      => $journal->id,
                        'account_id'      => $line->account_id,
                        'debit'           => $line->amount,
                        'credit'          => 0,
                        'description'     => $line->description,
                        'reference_type'  => 'cash_disbursement',
                        'reference_id'    => $cd->id,
                        'reference_number'=> $cd->number,
                    ]);
                }
                // Cr Kas/Bank sebesar total. Khusus type=general, pakai notes user kalau ada —
                // biar catatan custom (mis. "bayar BPJS") muncul di rekonsiliasi tanpa drill ke dokumen.
                // Tipe lain (freight/customer_refund) tetap pakai label default karena sudah cukup informatif.
                $cashDesc = ($cd->type === 'general' && !empty($cd->notes))
                    ? $cd->notes
                    : ('Pengeluaran ' . $cd->number);
                JournalLine::create([
                    'journal_id'      => $journal->id,
                    'account_id'      => $cd->cash_account_id,
                    'debit'           => 0,
                    'credit'          => $cd->total,
                    'description'     => $cashDesc,
                    'reference_type'  => 'cash_disbursement',
                    'reference_id'    => $cd->id,
                    'reference_number'=> $cd->number,
                ]);
            }

            $this->validateBalance($journal->id);

            // customer_refund → kurangi pool overpay (insert negative row total per customer)
            if ($cd->type === 'customer_refund') {
                CustomerOverpayment::create([
                    'customer_id' => $cd->customer_id,
                    'amount'      => -1 * (float) $cd->total,
                    'reference'   => $cd->number,
                    'note'        => 'Refund via ' . $cd->number,
                ]);
            }

            $cd->journal_id = $journal->id;
            $cd->status = 'posted';
            $cd->posted_at = now();
            $cd->save();

            return $cd;
        });
    }

    public function void(CashDisbursement $cd): CashDisbursement
    {
        if (!$cd->canBeVoided()) {
            throw new DomainException('Hanya dokumen posted yang bisa di-void.');
        }

        return DB::transaction(function () use ($cd) {
            // Reverse pool overpay untuk customer_refund
            if ($cd->type === 'customer_refund') {
                CustomerOverpayment::where('reference', $cd->number)->delete();
            }

            if ($cd->journal_id) {
                $journal = Journal::find($cd->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }

            $cd->status = 'void';
            $cd->voided_at = now();
            $cd->save();

            return $cd;
        });
    }

    /**
     * Posting jurnal freight:
     * - Per line: Dr 1203 = invoice.shipping_cost (titipan dilepas)
     * - Cr Kas/Bank = sum(line.amount) (total cash out aktual)
     * - Selisih (sum titipan − sum aktual):
     *     > 0 → Cr akun gain (kelebihan titipan jadi pendapatan)
     *     < 0 → Dr akun loss (kekurangan titipan jadi beban)
     */
    protected function postFreightLines(Journal $journal, CashDisbursement $cd): void
    {
        $invoiceIds = $cd->lines->pluck('sales_invoice_id')->filter()->unique()->all();
        $invoices = SalesInvoice::whereIn('id', $invoiceIds)->get()->keyBy('id');

        $totalTitipan = 0.0;
        $totalBayar   = 0.0;

        foreach ($cd->lines as $line) {
            $invoice = $invoices[$line->sales_invoice_id] ?? null;
            if (!$invoice) {
                throw new DomainException("Baris ongkir merujuk ke invoice yang tidak ditemukan.");
            }
            $titipan = (float) $invoice->shipping_cost;
            if ($titipan <= 0) {
                throw new DomainException("Invoice {$invoice->invoice_number} tidak punya titipan ongkir.");
            }
            $totalTitipan += $titipan;
            $totalBayar   += (float) $line->amount;

            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $line->account_id,  // 1203 (titipan)
                'debit'            => $titipan,
                'credit'           => 0,
                'description'      => 'Pelunasan titipan ongkir ' . $invoice->invoice_number,
                'reference_type'   => 'cash_disbursement',
                'reference_id'     => $cd->id,
                'reference_number' => $cd->number,
            ]);
        }

        JournalLine::create([
            'journal_id'       => $journal->id,
            'account_id'       => $cd->cash_account_id,
            'debit'            => 0,
            'credit'           => $totalBayar,
            'description'      => 'Pembayaran ongkir ' . $cd->number,
            'reference_type'   => 'cash_disbursement',
            'reference_id'     => $cd->id,
            'reference_number' => $cd->number,
        ]);

        $selisih = round($totalTitipan - $totalBayar, 2);
        if ($selisih == 0) return;

        $setting = FreightSetting::singleton();
        if ($selisih > 0) {
            if (!$setting->gain_account_id) {
                throw new DomainException("Selisih lebih titipan ongkir Rp " . number_format($selisih, 0, ',', '.')
                    . ". Set akun 'Selisih Lebih (Gain)' di Settings → Pengaturan Ongkir.");
            }
            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $setting->gain_account_id,
                'debit'            => 0,
                'credit'           => $selisih,
                'description'      => 'Selisih lebih titipan ongkir ' . $cd->number,
                'reference_type'   => 'cash_disbursement',
                'reference_id'     => $cd->id,
                'reference_number' => $cd->number,
            ]);
        } else {
            $loss = abs($selisih);
            if (!$setting->loss_account_id) {
                throw new DomainException("Selisih kurang titipan ongkir Rp " . number_format($loss, 0, ',', '.')
                    . ". Set akun 'Selisih Kurang (Loss)' di Settings → Pengaturan Ongkir.");
            }
            JournalLine::create([
                'journal_id'       => $journal->id,
                'account_id'       => $setting->loss_account_id,
                'debit'            => $loss,
                'credit'           => 0,
                'description'      => 'Selisih kurang titipan ongkir ' . $cd->number,
                'reference_type'   => 'cash_disbursement',
                'reference_id'     => $cd->id,
                'reference_number' => $cd->number,
            ]);
        }
    }

    protected function prefix(string $type): string
    {
        return match ($type) {
            'general'         => 'CD',
            'freight'         => 'CDF',
            'customer_refund' => 'CDR',
            default           => 'CD',
        };
    }

    protected function journalDescription(CashDisbursement $cd): string
    {
        return match ($cd->type) {
            'general'         => 'Pengeluaran umum ' . $cd->number,
            'freight'         => 'Pelunasan ongkir ' . $cd->number . ($cd->payee ? ' (' . $cd->payee . ')' : ''),
            'customer_refund' => 'Refund customer ' . $cd->number,
            default           => 'Pengeluaran ' . $cd->number,
        };
    }

    protected function validateLines(array $data): void
    {
        $lines = $data['lines'] ?? [];
        if (empty($lines)) throw new DomainException('Detail baris wajib diisi minimal 1.');

        foreach ($lines as $i => $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount <= 0) throw new DomainException("Baris " . ($i + 1) . ": nominal harus > 0.");
            if (empty($line['account_id'])) throw new DomainException("Baris " . ($i + 1) . ": akun wajib dipilih.");
        }

        // Type-specific validation
        if (($data['type'] ?? '') === 'freight') {
            $titipanId = Account::where('code', '1203')->value('id');
            if (!$titipanId) throw new DomainException("Akun 1203 (Titipan Ongkir) belum terdaftar di COA.");
            $seenInvoiceIds = [];
            foreach ($lines as $i => $line) {
                if ((int) $line['account_id'] !== (int) $titipanId) {
                    throw new DomainException("Baris " . ($i + 1) . ": tipe Freight wajib pakai akun 1203 Titipan Ongkir.");
                }
                if (empty($line['sales_invoice_id'])) {
                    throw new DomainException("Baris " . ($i + 1) . ": invoice wajib dipilih untuk pembayaran ongkir.");
                }
                $invId = (int) $line['sales_invoice_id'];
                if (in_array($invId, $seenInvoiceIds, true)) {
                    throw new DomainException("Invoice ID {$invId} dipilih lebih dari sekali.");
                }
                $seenInvoiceIds[] = $invId;
            }
        }
        if (($data['type'] ?? '') === 'customer_refund') {
            $overpayId = Account::where('code', AccountCodeEnum::CUSTOMER_OVERPAY)->value('id');
            if (!$overpayId) throw new DomainException("Akun 2106 (Customer Overpay) belum terdaftar di COA.");
            foreach ($lines as $i => $line) {
                if ((int) $line['account_id'] !== (int) $overpayId) {
                    throw new DomainException("Baris " . ($i + 1) . ": tipe Customer Refund wajib pakai akun 2106.");
                }
            }
        }
    }

    protected function sumLines(array $lines): float
    {
        return round(array_reduce($lines, fn($s, $l) => $s + (float) ($l['amount'] ?? 0), 0), 2);
    }

    protected function validateBalance(int $journalId): void
    {
        $lines = JournalLine::where('journal_id', $journalId)->get();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('credit'), 2);
        if ($debit !== $credit) {
            throw new DomainException("Journal not balanced: Dr=$debit Cr=$credit");
        }
    }

    public function getCustomerOverpayBalance(int $customerId): float
    {
        return (float) CustomerOverpayment::where('customer_id', $customerId)->sum('amount');
    }
}
