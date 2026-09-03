<?php

namespace App\Core\Journal;

use App\DTO\JournalEntryDTO;
use App\Core\Period\PeriodService;
use App\Core\Period\AccountingPeriod;
use App\Core\Accounting\Account;
use Illuminate\Support\Facades\DB;
use DomainException;
use Carbon\Carbon;

class JournalPostingService
{
    public function __construct(
        protected PeriodService $periodService
    ) {
    }

    public function post(JournalEntryDTO $dto): Journal
    {
        return DB::transaction(function () use ($dto) {

            $date = Carbon::parse($dto->date);

            $this->periodService->ensureOpen($date);

            $period = AccountingPeriod::where('year', $date->year)
                ->where('month', $date->month)
                ->first();

            // Cek double posting — hanya hitung journal yang masih AKTIF.
            // Journal yang sudah di-void (mis. lewat reverse posting / void invoice / void finalisasi)
            // tidak boleh memblokir posting ulang, karena event keuangannya sudah dibalik.
            if ($dto->reference_id && !$dto->allow_repeat) {
                $exists = Journal::where('reference_type', $dto->reference_type)
                    ->where('reference_id', $dto->reference_id)
                    ->where('status', '!=', 'void')
                    ->exists();

                if ($exists) {
                    throw new DomainException("Journal already posted for this reference.");
                }
            }

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($dto->lines as $line) {
                $totalDebit += $line->debit;
                $totalCredit += $line->credit;

                $account = Account::findOrFail($line->account_id);

                if ($account->children()->exists()) {
                    throw new DomainException("Cannot post to parent account.");
                }
            }

            // Bandingkan dgn toleransi (float strict !== rawan false-positive).
            if (abs(round($totalDebit, 4) - round($totalCredit, 4)) > 0.00005) {
                throw new DomainException("Journal not balanced.");
            }

            $journal = Journal::create([
                'journal_number' => 'JRN-' . uniqid(),
                'date' => $dto->date,
                'reference_type' => $dto->reference_type,
                'reference_id' => $dto->reference_id,
                'is_initial_balance' => $dto->is_initial_balance,
                'reference_number' => $dto->reference_number,
                'description' => $dto->description,
                'period_id' => $period->id,
                'status' => 'posted',
                'posted_at' => now(),
            ]);

            foreach ($dto->lines as $line) {
                $journal->lines()->create([
                    'account_id'       => $line->account_id,
                    // CATATAN: kolom journal_lines.customer_id TIDAK ADA di skema (tak pernah
                    // di-migrate). customer_id pada DTO selalu null & tak pernah dibaca, jadi
                    // insert-nya dihapus — sebelumnya bikin SQL "Unknown column" yg memblok
                    // SEMUA pemanggil (finalisasi produksi, sales return, opening balance, dll).
                    'debit'            => $line->debit,
                    'credit'           => $line->credit,
                    'description'      => $line->description,
                    'reference_type'   => $dto->reference_type,
                    'reference_id'     => $dto->reference_id,
                    'reference_number' => $dto->reference_number,
                ]);
            }

            return $journal;
        });
    }
}
