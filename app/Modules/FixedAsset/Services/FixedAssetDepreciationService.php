<?php

namespace App\Modules\FixedAsset\Services;

use App\Modules\FixedAsset\Models\FixedAsset;
use App\Modules\FixedAsset\Models\FixedAssetDepreciation;
use App\Core\Period\AccountingPeriod;
use App\Core\Period\PeriodService;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalLine;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use DomainException;

class FixedAssetDepreciationService
{
    public function __construct(
        protected PeriodService $periodService
    ) {}

    /**
     * Run depresiasi untuk satu period. Idempotent — skip aset yang sudah punya
     * FixedAssetDepreciation aktif untuk period_id ini.
     *
     * Buat 1 jurnal header per period (reference_type='fixed_asset_depreciation_period'),
     * lines agregat per kategori (Dr beban / Cr akumulasi). Tiap row depreciation
     * di tabel fixed_asset_depreciations menunjuk ke journal_id yang sama.
     *
     * @return array{count:int,total_amount:float,journal_id:?int,details:array}
     */
    public function runForPeriod(AccountingPeriod $period, ?array $assetIds = null): array
    {
        $periodStart = Carbon::parse($period->start_date);
        $periodEnd = Carbon::parse($period->end_date);

        // Validasi periode harus open
        $this->periodService->ensureOpen($periodStart);

        $query = FixedAsset::with('category')
            ->where('status', 'active')
            ->where('is_depreciable', true)
            ->whereDate('depreciation_start_date', '<=', $periodEnd);

        if ($assetIds) {
            $query->whereIn('id', $assetIds);
        }

        $candidates = $query->get();

        // Filter: skip yang sudah punya FixedAssetDepreciation posted untuk period ini
        $candidates = $candidates->filter(function (FixedAsset $a) use ($period, $periodStart) {
            $alreadyPosted = FixedAssetDepreciation::where('fixed_asset_id', $a->id)
                ->where('period_id', $period->id)
                ->where('status', 'posted')
                ->exists();
            if ($alreadyPosted) return false;

            // Skip kalau last_depreciation_date sudah >= start of period
            if ($a->last_depreciation_date && Carbon::parse($a->last_depreciation_date)->gte($periodStart)) {
                return false;
            }

            // Skip kalau book value sudah <= salvage
            $bookValue = (float) $a->acquisition_cost - (float) $a->accumulated_depreciation;
            if ($bookValue <= (float) $a->salvage_value + 0.0001) {
                return false;
            }

            return true;
        })->values();

        if ($candidates->isEmpty()) {
            return ['count' => 0, 'total_amount' => 0, 'journal_id' => null, 'details' => []];
        }

        return DB::transaction(function () use ($candidates, $period, $periodEnd) {
            $details = [];
            $byCategoryAccount = []; // ['expense_acc_id' => float], ['accum_acc_id' => float]
            $totalAmount = 0;

            foreach ($candidates as $asset) {
                $cat = $asset->category;
                if (!$cat || !$cat->depreciation_expense_account_id || !$cat->accumulated_depreciation_account_id) {
                    throw new DomainException("Kategori aset {$asset->asset_code} belum punya akun beban depresiasi/akumulasi.");
                }

                $amount = $this->calculateMonthlyAmount($asset);
                if ($amount <= 0) continue;

                $expAcc = (int) $cat->depreciation_expense_account_id;
                $accAcc = (int) $cat->accumulated_depreciation_account_id;
                $byCategoryAccount[$expAcc]['debit'] = round(($byCategoryAccount[$expAcc]['debit'] ?? 0) + $amount, 2);
                $byCategoryAccount[$accAcc]['credit'] = round(($byCategoryAccount[$accAcc]['credit'] ?? 0) + $amount, 2);

                $details[] = ['asset' => $asset, 'amount' => $amount];
                $totalAmount = round($totalAmount + $amount, 2);
            }

            if ($totalAmount <= 0) {
                return ['count' => 0, 'total_amount' => 0, 'journal_id' => null, 'details' => []];
            }

            // Cek apakah journal aktif untuk period ini sudah ada (mungkin dari run sebelumnya untuk subset aset)
            $existingJournal = Journal::where('reference_type', 'fixed_asset_depreciation_period')
                ->where('reference_id', $period->id)
                ->where('status', '!=', 'void')
                ->first();

            // Strategi: kalau sudah ada journal aktif, append lines ke journal itu (untuk run berikutnya yang menambahkan aset baru).
            // Kalau belum ada, create baru.
            if ($existingJournal) {
                $journal = $existingJournal;
            } else {
                $journal = Journal::create([
                    'journal_number' => $this->generateJournalNumber(),
                    'date' => $periodEnd->toDateString(),
                    'period_id' => $period->id,
                    'reference_type' => 'fixed_asset_depreciation_period',
                    'reference_id' => $period->id,
                    'reference_number' => 'DEP/' . $period->year . '/' . str_pad((string)$period->month, 2, '0', STR_PAD_LEFT),
                    'description' => 'Penyusutan aset tetap periode ' . $period->year . '-' . str_pad((string)$period->month, 2, '0', STR_PAD_LEFT),
                    'status' => 'posted',
                    'posted_at' => now(),
                ]);
            }

            // Tulis journal lines agregat per akun
            foreach ($byCategoryAccount as $accountId => $row) {
                $debit = (float) ($row['debit'] ?? 0);
                $credit = (float) ($row['credit'] ?? 0);
                if ($debit <= 0 && $credit <= 0) continue;

                JournalLine::create([
                    'journal_id' => $journal->id,
                    'account_id' => $accountId,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => 'Penyusutan periode ' . $period->year . '-' . str_pad((string)$period->month, 2, '0', STR_PAD_LEFT),
                    'reference_type' => 'fixed_asset_depreciation_period',
                    'reference_id' => $period->id,
                    'reference_number' => 'DEP/' . $period->year . '/' . str_pad((string)$period->month, 2, '0', STR_PAD_LEFT),
                ]);
            }

            // Validasi balance pada keseluruhan journal
            $this->validateBalance($journal->id);

            // Insert FixedAssetDepreciation row + update master
            foreach ($details as $row) {
                /** @var FixedAsset $asset */
                $asset = $row['asset'];
                $amount = (float) $row['amount'];

                $newAccum = round((float) $asset->accumulated_depreciation + $amount, 2);
                $newBook = round((float) $asset->acquisition_cost - $newAccum, 2);

                FixedAssetDepreciation::create([
                    'fixed_asset_id' => $asset->id,
                    'period_id' => $period->id,
                    'period_year' => $period->year,
                    'period_month' => $period->month,
                    'depreciation_date' => $periodEnd->toDateString(),
                    'amount' => $amount,
                    'accumulated_after' => $newAccum,
                    'book_value_after' => $newBook,
                    'journal_id' => $journal->id,
                    'status' => 'posted',
                ]);

                $asset->accumulated_depreciation = $newAccum;
                $asset->current_book_value = $newBook;
                $asset->last_depreciation_date = $periodEnd->toDateString();
                $asset->months_depreciated = (int) $asset->months_depreciated + 1;
                $asset->save();
            }

            return [
                'count' => count($details),
                'total_amount' => $totalAmount,
                'journal_id' => $journal->id,
                'details' => $details,
            ];
        });
    }

    /**
     * Straight-line monthly. Clamp di bulan terakhir supaya book value tidak turun di bawah salvage.
     */
    public function calculateMonthlyAmount(FixedAsset $asset): float
    {
        if (!$asset->is_depreciable) return 0;
        if ((int) $asset->useful_life_months <= 0) return 0;

        $base = (float) $asset->acquisition_cost - (float) $asset->salvage_value;
        if ($base <= 0) return 0;

        $straightLine = round($base / (int) $asset->useful_life_months, 2);

        $remaining = round((float) $asset->current_book_value - (float) $asset->salvage_value, 2);
        if ($remaining <= 0) return 0;

        return min($straightLine, $remaining);
    }

    /**
     * Dry-run: hitung tanpa persist, untuk modal preview.
     */
    public function previewForPeriod(AccountingPeriod $period): array
    {
        $periodEnd = Carbon::parse($period->end_date);
        $periodStart = Carbon::parse($period->start_date);

        $candidates = FixedAsset::with('category')
            ->where('status', 'active')
            ->where('is_depreciable', true)
            ->whereDate('depreciation_start_date', '<=', $periodEnd)
            ->get()
            ->filter(function (FixedAsset $a) use ($period, $periodStart) {
                $already = FixedAssetDepreciation::where('fixed_asset_id', $a->id)
                    ->where('period_id', $period->id)
                    ->where('status', 'posted')->exists();
                if ($already) return false;
                if ($a->last_depreciation_date && Carbon::parse($a->last_depreciation_date)->gte($periodStart)) return false;
                $bookValue = (float) $a->acquisition_cost - (float) $a->accumulated_depreciation;
                return $bookValue > (float) $a->salvage_value + 0.0001;
            });

        $rows = [];
        $total = 0;
        foreach ($candidates as $a) {
            $amt = $this->calculateMonthlyAmount($a);
            if ($amt <= 0) continue;
            $rows[] = ['asset' => $a, 'amount' => $amt];
            $total = round($total + $amt, 2);
        }

        return ['rows' => $rows, 'total' => $total, 'count' => count($rows)];
    }

    protected function generateJournalNumber(): string
    {
        $prefix = 'DJV/' . now()->format('Y/m') . '/';
        $latest = Journal::where('journal_number', 'LIKE', $prefix . '%')
            ->orderByDesc('journal_number')
            ->value('journal_number');
        $next = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
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
