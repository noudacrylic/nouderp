<?php

namespace App\Modules\Sales\Services;

use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use App\Core\Accounting\Account;
use App\Core\Period\AccountingPeriod;
use Illuminate\Support\Facades\DB;

class SalesAdvanceService
{
    public function post($advance)
    {
        DB::transaction(function () use ($advance) {

            $bank = Account::findOrFail($advance->bank_account_id);
            $uangMuka = Account::where('code', '2102')->firstOrFail();

            $period = AccountingPeriod::where('year', date('Y', strtotime($advance->advance_date)))
                ->where('month', date('m', strtotime($advance->advance_date)))
                ->where('status', 'open')
                ->first();

            if (!$period) {
                throw new \Exception('Periode belum dibuka atau sudah ditutup');
            }

            // 1. Buat Journal
            $journal = Journal::create([
                'journal_number' => $this->generateJournalNumber($advance->advance_date),
                'period_id' => $period->id,
                'date' => $advance->advance_date,
                'reference_type' => 'sales_advance',
                'reference_id' => $advance->id,
                'description' => 'Sales Advance ' . $advance->advance_number
            ]);

            // 2. Debit Bank
            JournalLine::create([
                'journal_id' => $journal->id,
                'account_id' => $bank->id,
                'debit' => $advance->amount,
                'credit' => 0
            ]);

            // 3. Credit Uang Muka
            JournalLine::create([
                'journal_id' => $journal->id,
                'account_id' => $uangMuka->id,
                'debit' => 0,
                'credit' => $advance->amount
            ]);

            // 4. Update status
            $advance->update([
                'status' => 'posted'
            ]);
        });
    }

    private function generateJournalNumber($date): string
    {
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));

        $prefix = "JV/{$year}/{$month}/";

        $last = Journal::where('journal_number', 'like', $prefix . '%')
            ->orderBy('journal_number', 'desc')
            ->first();

        if ($last) {
            $lastNumber = (int) substr($last->journal_number, -4);
            $next = $lastNumber + 1;
        } else {
            $next = 1;
        }

        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}
