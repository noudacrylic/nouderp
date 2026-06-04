<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashReceipt;
use App\Modules\Finance\Models\CashReceiptLine;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use App\Modules\Purchasing\Models\SupplierOverpayment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class CashReceiptService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService
    ) {}

    public function createDraft(array $data): CashReceipt
    {
        return DB::transaction(function () use ($data) {
            $this->validateLines($data);

            $cr = CashReceipt::create([
                'number'          => $this->generateNumber(CashReceipt::class, $this->prefix($data['type'])),
                'date'            => $data['date'],
                'type'            => $data['type'],
                'cash_account_id' => $data['cash_account_id'],
                'supplier_id'     => $data['supplier_id'] ?? null,
                'payer'           => $data['payer'] ?? null,
                'reference'       => $data['reference'] ?? null,
                'total'           => $this->sumLines($data['lines'] ?? []),
                'status'          => 'draft',
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            foreach ($data['lines'] as $line) {
                CashReceiptLine::create([
                    'cash_receipt_id'         => $cr->id,
                    'account_id'              => $line['account_id'],
                    'supplier_overpayment_id' => $line['supplier_overpayment_id'] ?? null,
                    'amount'                  => $line['amount'],
                    'description'             => $line['description'] ?? null,
                ]);
            }

            return $cr;
        });
    }

    public function update(CashReceipt $cr, array $data): CashReceipt
    {
        if (!$cr->isDraft()) {
            throw new DomainException('Hanya draft yang bisa diedit.');
        }
        return DB::transaction(function () use ($cr, $data) {
            $this->validateLines($data);
            $cr->lines()->delete();
            $cr->update([
                'date'            => $data['date'],
                'type'            => $data['type'],
                'cash_account_id' => $data['cash_account_id'],
                'supplier_id'     => $data['supplier_id'] ?? null,
                'payer'           => $data['payer'] ?? null,
                'reference'       => $data['reference'] ?? null,
                'total'           => $this->sumLines($data['lines'] ?? []),
                'notes'           => $data['notes'] ?? null,
            ]);
            foreach ($data['lines'] as $line) {
                CashReceiptLine::create([
                    'cash_receipt_id'         => $cr->id,
                    'account_id'              => $line['account_id'],
                    'supplier_overpayment_id' => $line['supplier_overpayment_id'] ?? null,
                    'amount'                  => $line['amount'],
                    'description'             => $line['description'] ?? null,
                ]);
            }
            return $cr;
        });
    }

    public function post(CashReceipt $cr): CashReceipt
    {
        if ($cr->isPosted()) throw new DomainException('Sudah diposting.');
        if ($cr->isVoid())   throw new DomainException('Sudah di-void.');

        $payDate = Carbon::parse($cr->date);
        $this->periodService->ensureOpen($payDate);

        $exists = Journal::where('reference_type', 'cash_receipt')
            ->where('reference_id', $cr->id)
            ->where('status', '!=', 'void')
            ->exists();
        if ($exists) throw new DomainException('Journal sudah ada untuk dokumen ini.');

        return DB::transaction(function () use ($cr, $payDate) {
            $cr->load('lines');

            // Validasi supplier_refund: saldo overpay supplier cukup
            if ($cr->type === 'supplier_refund') {
                if (!$cr->supplier_id) throw new DomainException('Supplier wajib dipilih.');
                $balance = $this->getSupplierOverpayBalance($cr->supplier_id);
                if ((float) $cr->total > $balance + 0.0001) {
                    throw new DomainException('Saldo piutang supplier tidak cukup. Saldo: ' . number_format($balance, 0, ',', '.'));
                }
            }

            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'CR-J-' . $cr->number,
                'date'             => $cr->date,
                'period_id'        => $period->id,
                'reference_type'   => 'cash_receipt',
                'reference_id'     => $cr->id,
                'reference_number' => $cr->number,
                'description'      => $this->journalDescription($cr),
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            // Dr Kas/Bank sebesar total. Khusus type=general, pakai notes user kalau ada —
            // biar catatan custom muncul di rekonsiliasi tanpa drill ke dokumen.
            // Tipe lain (supplier_refund) tetap pakai label default karena sudah cukup informatif.
            $cashDesc = ($cr->type === 'general' && !empty($cr->notes))
                ? $cr->notes
                : ('Pemasukan ' . $cr->number);
            JournalLine::create([
                'journal_id'      => $journal->id,
                'account_id'      => $cr->cash_account_id,
                'debit'           => $cr->total,
                'credit'          => 0,
                'description'     => $cashDesc,
                'reference_type'  => 'cash_receipt',
                'reference_id'    => $cr->id,
                'reference_number'=> $cr->number,
            ]);
            // Cr akun pendapatan / akun overpay supplier per line
            foreach ($cr->lines as $line) {
                JournalLine::create([
                    'journal_id'      => $journal->id,
                    'account_id'      => $line->account_id,
                    'debit'           => 0,
                    'credit'          => $line->amount,
                    'description'     => $line->description,
                    'reference_type'  => 'cash_receipt',
                    'reference_id'    => $cr->id,
                    'reference_number'=> $cr->number,
                ]);
            }

            $this->validateBalance($journal->id);

            // supplier_refund → kurangi pool 1108 (insert negative row total)
            if ($cr->type === 'supplier_refund') {
                SupplierOverpayment::create([
                    'supplier_id' => $cr->supplier_id,
                    'amount'      => -1 * (float) $cr->total,
                    'reference'   => $cr->number,
                    'note'        => 'Refund via ' . $cr->number,
                ]);
            }

            $cr->journal_id = $journal->id;
            $cr->status = 'posted';
            $cr->posted_at = now();
            $cr->save();

            return $cr;
        });
    }

    public function void(CashReceipt $cr): CashReceipt
    {
        if (!$cr->canBeVoided()) {
            throw new DomainException('Hanya dokumen posted yang bisa di-void.');
        }

        return DB::transaction(function () use ($cr) {
            if ($cr->type === 'supplier_refund') {
                SupplierOverpayment::where('reference', $cr->number)->delete();
            }

            if ($cr->journal_id) {
                $journal = Journal::find($cr->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }

            $cr->status = 'void';
            $cr->voided_at = now();
            $cr->save();

            return $cr;
        });
    }

    protected function prefix(string $type): string
    {
        return match ($type) {
            'general'         => 'CR',
            'supplier_refund' => 'CRS',
            default           => 'CR',
        };
    }

    protected function journalDescription(CashReceipt $cr): string
    {
        return match ($cr->type) {
            'general'         => 'Pemasukan umum ' . $cr->number,
            'supplier_refund' => 'Refund dari supplier ' . $cr->number,
            default           => 'Pemasukan ' . $cr->number,
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

        if (($data['type'] ?? '') === 'supplier_refund') {
            $overpayId = Account::where('code', '1108')->value('id');
            if (!$overpayId) throw new DomainException("Akun 1108 (Piutang Lebih Bayar Supplier) belum terdaftar di COA.");
            foreach ($lines as $i => $line) {
                if ((int) $line['account_id'] !== (int) $overpayId) {
                    throw new DomainException("Baris " . ($i + 1) . ": tipe Supplier Refund wajib pakai akun 1108.");
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

    public function getSupplierOverpayBalance(int $supplierId): float
    {
        return (float) SupplierOverpayment::where('supplier_id', $supplierId)->sum('amount');
    }
}
