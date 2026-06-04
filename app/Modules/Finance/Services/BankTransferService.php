<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\BankTransfer;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class BankTransferService
{
    use NumberGeneratorTrait;

    public function __construct(
        protected PeriodService $periodService
    ) {}

    public function createDraft(array $data): BankTransfer
    {
        return DB::transaction(function () use ($data) {
            $this->validate($data);

            return BankTransfer::create([
                'number'               => $this->generateNumber(BankTransfer::class, 'BT'),
                'date'                 => $data['date'],
                'from_account_id'      => $data['from_account_id'],
                'to_account_id'        => $data['to_account_id'],
                'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
                'amount'               => $data['amount'],
                'admin_fee'            => $data['admin_fee'] ?? 0,
                'fee_borne_by'         => $data['fee_borne_by'] ?? 'source',
                'reference'            => $data['reference'] ?? null,
                'status'               => 'draft',
                'notes'                => $data['notes'] ?? null,
                'created_by'           => auth()->id(),
            ]);
        });
    }

    public function update(BankTransfer $bt, array $data): BankTransfer
    {
        if (!$bt->isDraft()) {
            throw new DomainException('Hanya draft yang bisa diedit.');
        }
        $this->validate($data);
        $bt->update([
            'date'                 => $data['date'],
            'from_account_id'      => $data['from_account_id'],
            'to_account_id'        => $data['to_account_id'],
            'admin_fee_account_id' => $data['admin_fee_account_id'] ?? null,
            'amount'               => $data['amount'],
            'admin_fee'            => $data['admin_fee'] ?? 0,
            'fee_borne_by'         => $data['fee_borne_by'] ?? 'source',
            'reference'            => $data['reference'] ?? null,
            'notes'                => $data['notes'] ?? null,
        ]);
        return $bt;
    }

    public function post(BankTransfer $bt): BankTransfer
    {
        if ($bt->isPosted()) throw new DomainException('Sudah diposting.');
        if ($bt->isVoid())   throw new DomainException('Sudah di-void.');

        $payDate = Carbon::parse($bt->date);
        $this->periodService->ensureOpen($payDate);

        $exists = Journal::where('reference_type', 'bank_transfer')
            ->where('reference_id', $bt->id)
            ->where('status', '!=', 'void')
            ->exists();
        if ($exists) throw new DomainException('Journal sudah ada untuk dokumen ini.');

        return DB::transaction(function () use ($bt, $payDate) {
            $period = AccountingPeriod::where('year', $payDate->year)
                ->where('month', $payDate->month)->first();
            if (!$period) throw new DomainException('Periode akuntansi tidak ditemukan.');

            $journal = Journal::create([
                'journal_number'   => 'BT-J-' . $bt->number,
                'date'             => $bt->date,
                'period_id'        => $period->id,
                'reference_type'   => 'bank_transfer',
                'reference_id'     => $bt->id,
                'reference_number' => $bt->number,
                'description'      => 'Transfer antar bank ' . $bt->number,
                'status'           => 'posted',
                'posted_at'        => now(),
            ]);

            $amount   = (float) $bt->amount;
            $adminFee = (float) $bt->admin_fee;
            $borneBy  = $bt->fee_borne_by ?: 'source';

            // Hitung debit/credit per akun berdasarkan siapa yang menanggung biaya.
            //   source: kas sumber turun (amount+fee), kas tujuan naik (amount)
            //   destination: kas sumber turun (amount), kas tujuan naik (amount-fee)
            if ($borneBy === 'destination') {
                if ($amount - $adminFee <= 0) {
                    throw new DomainException('Biaya admin tidak boleh ≥ jumlah transfer saat ditanggung tujuan.');
                }
                $debitTo    = $amount - $adminFee;
                $creditFrom = $amount;
            } else {
                $debitTo    = $amount;
                $creditFrom = $amount + $adminFee;
            }

            // Dr To Account (jumlah diterima)
            JournalLine::create([
                'journal_id'      => $journal->id,
                'account_id'      => $bt->to_account_id,
                'debit'           => $debitTo,
                'credit'          => 0,
                'description'     => 'Transfer masuk ' . $bt->number,
                'reference_type'  => 'bank_transfer',
                'reference_id'    => $bt->id,
                'reference_number'=> $bt->number,
            ]);
            // Dr Beban Admin Bank (kalau ada — regardless siapa yang tanggung)
            if ($adminFee > 0) {
                if (!$bt->admin_fee_account_id) {
                    throw new DomainException('Akun beban admin bank wajib dipilih saat ada biaya admin.');
                }
                JournalLine::create([
                    'journal_id'      => $journal->id,
                    'account_id'      => $bt->admin_fee_account_id,
                    'debit'           => $adminFee,
                    'credit'          => 0,
                    'description'     => 'Biaya admin transfer ' . $bt->number,
                    'reference_type'  => 'bank_transfer',
                    'reference_id'    => $bt->id,
                    'reference_number'=> $bt->number,
                ]);
            }
            // Cr From Account
            JournalLine::create([
                'journal_id'      => $journal->id,
                'account_id'      => $bt->from_account_id,
                'debit'           => 0,
                'credit'          => $creditFrom,
                'description'     => 'Transfer keluar ' . $bt->number,
                'reference_type'  => 'bank_transfer',
                'reference_id'    => $bt->id,
                'reference_number'=> $bt->number,
            ]);

            $this->validateBalance($journal->id);

            $bt->journal_id = $journal->id;
            $bt->status = 'posted';
            $bt->posted_at = now();
            $bt->save();

            return $bt;
        });
    }

    public function void(BankTransfer $bt): BankTransfer
    {
        if (!$bt->canBeVoided()) {
            throw new DomainException('Hanya dokumen posted yang bisa di-void.');
        }
        return DB::transaction(function () use ($bt) {
            if ($bt->journal_id) {
                $journal = Journal::find($bt->journal_id);
                if ($journal) {
                    $journal->status = 'void';
                    $journal->voided_at = now();
                    $journal->save();
                }
            }
            $bt->status = 'void';
            $bt->voided_at = now();
            $bt->save();
            return $bt;
        });
    }

    protected function validate(array $data): void
    {
        if ((float) ($data['amount'] ?? 0) <= 0) throw new DomainException('Jumlah transfer harus > 0.');
        if (empty($data['from_account_id']) || empty($data['to_account_id'])) {
            throw new DomainException('Akun sumber & tujuan wajib diisi.');
        }
        if ((int) $data['from_account_id'] === (int) $data['to_account_id']) {
            throw new DomainException('Akun sumber & tujuan tidak boleh sama.');
        }
        // Verify both are cash accounts
        $cashIds = Account::whereIn('id', [$data['from_account_id'], $data['to_account_id']])
            ->where('is_cash_account', 1)
            ->pluck('id')->toArray();
        if (count($cashIds) < 2) {
            throw new DomainException('Akun sumber & tujuan wajib akun kas/bank (is_cash_account).');
        }
        if ((float) ($data['admin_fee'] ?? 0) > 0 && empty($data['admin_fee_account_id'])) {
            throw new DomainException('Akun beban admin bank wajib dipilih saat biaya admin > 0.');
        }
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
}
