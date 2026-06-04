<?php

namespace App\Services\Accounting;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Journal\JournalPostingService;
use App\Core\Period\AccountingPeriod;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    public function __construct(
        protected JournalPostingService $postingService
    ) {}

    /**
     * Set saldo awal akun — overwrite per akun (1 akun = 1 saldo aktif).
     *
     * @param array{account_id:int, amount:float|string, date:string, description?:string} $data
     */
    public function handle(array $data): Journal
    {
        return DB::transaction(function () use ($data) {
            $account = Account::findOrFail($data['account_id']);
            $amount  = (float) $data['amount'];
            $date    = $data['date'];
            $description = $data['description'] ?? 'Initial Opening Balance Entry';

            if ($amount <= 0) {
                throw new DomainException('Saldo harus lebih besar dari 0.');
            }
            if ($account->children()->exists()) {
                throw new DomainException('Tidak bisa input saldo ke akun induk.');
            }

            $equity = Account::where('type', 'equity')->first();
            if (!$equity) {
                throw new DomainException('Akun Equity/Modal untuk penyeimbang saldo tidak ditemukan.');
            }

            // Tentukan sisi debit/credit dari sifat akun.
            // Asset/Expense normal balance = debit → akun di-debit, equity di-credit.
            // Liability/Equity/Revenue normal balance = credit → akun di-credit, equity di-debit.
            $accountIsDebitSide = in_array($account->type, ['asset', 'expense'], true);

            // Cari journal saldo awal manual existing untuk akun ini.
            $existing = Journal::where('reference_type', 'opening_balance')
                ->where('status', '!=', 'void')
                ->whereHas('lines', fn($q) => $q->where('account_id', $account->id))
                ->first();

            if ($existing) {
                return $this->overwrite($existing, $account, $equity, $amount, $date, $description, $accountIsDebitSide);
            }

            return $this->createNew($account, $equity, $amount, $date, $description, $accountIsDebitSide);
        });
    }

    public function destroy(Journal $journal): void
    {
        if ($journal->reference_type !== 'opening_balance') {
            throw new DomainException('Hanya saldo awal manual yang bisa dihapus dari sini.');
        }
        DB::transaction(function () use ($journal) {
            JournalLine::where('journal_id', $journal->id)->delete();
            $journal->delete();
        });
    }

    protected function overwrite(
        Journal $journal,
        Account $account,
        Account $equity,
        float $amount,
        string $date,
        string $description,
        bool $accountIsDebitSide
    ): Journal {
        $parsed = Carbon::parse($date);
        $period = AccountingPeriod::where('year', $parsed->year)
            ->where('month', $parsed->month)
            ->first();
        if (!$period || $period->status !== 'open') {
            throw new DomainException('Periode akuntansi pada tanggal tersebut belum dibuka.');
        }

        $journal->update([
            'date'        => $date,
            'period_id'   => $period->id,
            'description' => $description,
            'posted_at'   => now(),
            'status'      => 'posted',
        ]);

        JournalLine::where('journal_id', $journal->id)->delete();

        $this->insertLines($journal->id, $account, $equity, $amount, $description, $accountIsDebitSide);

        return $journal->refresh();
    }

    protected function createNew(
        Account $account,
        Account $equity,
        float $amount,
        string $date,
        string $description,
        bool $accountIsDebitSide
    ): Journal {
        $lines = $accountIsDebitSide
            ? [
                new JournalLineDTO(account_id: $account->id, debit: $amount, credit: 0, description: $description),
                new JournalLineDTO(account_id: $equity->id,  debit: 0,       credit: $amount, description: $description . ' (Offset)'),
            ]
            : [
                new JournalLineDTO(account_id: $equity->id,  debit: $amount, credit: 0, description: $description . ' (Offset)'),
                new JournalLineDTO(account_id: $account->id, debit: 0,       credit: $amount, description: $description),
            ];

        $dto = new JournalEntryDTO(
            date: $date,
            reference_type: 'opening_balance',
            reference_id: now()->timestamp,
            description: $description,
            lines: $lines,
            is_initial_balance: true,
        );

        return $this->postingService->post($dto);
    }

    protected function insertLines(
        int $journalId,
        Account $account,
        Account $equity,
        float $amount,
        string $description,
        bool $accountIsDebitSide
    ): void {
        $rows = $accountIsDebitSide
            ? [
                ['account_id' => $account->id, 'debit' => $amount, 'credit' => 0,       'description' => $description],
                ['account_id' => $equity->id,  'debit' => 0,       'credit' => $amount, 'description' => $description . ' (Offset)'],
            ]
            : [
                ['account_id' => $equity->id,  'debit' => $amount, 'credit' => 0,       'description' => $description . ' (Offset)'],
                ['account_id' => $account->id, 'debit' => 0,       'credit' => $amount, 'description' => $description],
            ];

        foreach ($rows as $row) {
            JournalLine::create(array_merge($row, [
                'journal_id'     => $journalId,
                'reference_type' => 'opening_balance',
            ]));
        }
    }
}
