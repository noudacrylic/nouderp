<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Core\Accounting\Account;
use App\Enums\AccountTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FinancialReportController extends Controller
{
    public function balanceSheet(Request $request)
    {
        $asOf = Carbon::parse($request->input('as_of', now()->toDateString()));

        // Saldo per akun untuk Asset/Liability/Equity per tanggal as_of (cumulative).
        $balanceAsOf = $this->balancesByAccount(
            types: [AccountTypeEnum::ASSET, AccountTypeEnum::LIABILITY, AccountTypeEnum::EQUITY],
            dateFrom: null,
            dateTo: $asOf
        );

        // Laba/Rugi belum closed = (Revenue - Expense) sejak awal s/d as_of.
        // Tanpa filter fiscal year, supaya P&L dari periode lama yang belum di-close ke laba ditahan
        // tetap masuk ke neraca (kalau dibatasi YTD, laba PY akan "hilang" dan neraca tidak balanced).
        $rev = $this->balancesByAccount([AccountTypeEnum::REVENUE], null, $asOf);
        $exp = $this->balancesByAccount([AccountTypeEnum::EXPENSE], null, $asOf);
        $revTotal = collect($rev)->sum('balance');
        $expTotal = collect($exp)->sum('balance');
        $netIncomeYtd = round($revTotal - $expTotal, 2);

        $assets = collect($balanceAsOf)->where('type', AccountTypeEnum::ASSET)->values()->all();
        $liabilities = collect($balanceAsOf)->where('type', AccountTypeEnum::LIABILITY)->values()->all();
        $equities = collect($balanceAsOf)->where('type', AccountTypeEnum::EQUITY)->values()->all();

        $assetGroups = $this->groupAssets($assets);
        $liabilityGroups = $this->groupLiabilities($liabilities);
        $equityGroups = $this->groupEquity($equities);

        $totalAsset = round(array_sum(array_column($assetGroups, 'subtotal')), 2);
        $totalLiability = round(array_sum(array_column($liabilityGroups, 'subtotal')), 2);
        $totalEquity = round(array_sum(array_column($equityGroups, 'subtotal')), 2);
        $totalLiabEqIncome = round($totalLiability + $totalEquity + $netIncomeYtd, 2);
        $diff = round($totalAsset - $totalLiabEqIncome, 2);

        return view('erp.accounting.reports.balance-sheet', compact(
            'asOf', 'assetGroups', 'liabilityGroups', 'equityGroups',
            'totalAsset', 'totalLiability', 'totalEquity', 'netIncomeYtd',
            'totalLiabEqIncome', 'diff'
        ));
    }

    public function incomeStatement(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', now()->startOfMonth()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', now()->toDateString()));

        // Klasifikasi bertingkat (format Accurate) — sumber tunggal di IncomeStatementService.
        $data = app(\App\Core\Accounting\IncomeStatementService::class)->summary($dateFrom, $dateTo);

        return view('erp.accounting.reports.income-statement', array_merge(
            compact('dateFrom', 'dateTo'),
            $data
        ));
    }

    /**
     * Saldo per akun (leaf only) dari journal_lines + journals posted.
     * Saldo dihitung berdasar TYPE (bukan normal_balance) supaya contra-account ber-sign benar:
     *   asset, expense   → debit - credit  (contra-asset/expense → negatif)
     *   liability, equity, revenue → credit - debit  (contra-liab/equity/rev → negatif)
     */
    protected function balancesByAccount(array $types, ?Carbon $dateFrom, Carbon $dateTo): array
    {
        // Logika dipindah ke AccountBalanceService agar dipakai bersama dengan Dashboard. Hasil identik.
        return app(\App\Core\Accounting\AccountBalanceService::class)->balances($types, $dateFrom, $dateTo);
    }

    /**
     * Klasifikasi aset jadi 2 level: section (Aset Lancar / Aset Tetap) → subgroup → akun.
     * Aturan kode (Indonesian COA convention):
     *   11xx → Aset Lancar (Kas/Setara, Piutang, Persediaan, Lainnya berdasar account_category)
     *   12xx+ → Aset Tetap (cost = normal_balance debit, akumulasi = credit)
     */
    protected function groupAssets(array $rows): array
    {
        $coll = collect($rows);
        $current = $coll->filter(fn($a) => str_starts_with((string)$a->code, '11'))->values();
        $fixed = $coll->filter(fn($a) => !str_starts_with((string)$a->code, '11'))->values();

        $groups = [];

        if ($current->isNotEmpty()) {
            $cashLike = $current->filter(fn($a) => in_array($a->account_category, ['cash', 'cash_equivalent'], true));
            $receivable = $current->filter(fn($a) => $a->account_category === 'receivable');
            $inventory = $current->filter(fn($a) => $a->account_category === 'inventory');
            $taken = $cashLike->merge($receivable)->merge($inventory)->pluck('id')->all();
            $other = $current->filter(fn($a) => !in_array($a->id, $taken, true));

            $sub = [];
            if ($cashLike->count())   $sub[] = ['label' => 'Kas dan Setara Kas', 'accounts' => $cashLike->values()->all(),   'subtotal' => round($cashLike->sum('balance'), 2)];
            if ($receivable->count()) $sub[] = ['label' => 'Piutang',            'accounts' => $receivable->values()->all(), 'subtotal' => round($receivable->sum('balance'), 2)];
            if ($inventory->count())  $sub[] = ['label' => 'Persediaan',         'accounts' => $inventory->values()->all(),  'subtotal' => round($inventory->sum('balance'), 2)];
            if ($other->count())      $sub[] = ['label' => 'Aset Lancar Lainnya','accounts' => $other->values()->all(),      'subtotal' => round($other->sum('balance'), 2)];

            $groups[] = ['label' => 'Aset Lancar', 'subgroups' => $sub, 'subtotal' => round($current->sum('balance'), 2)];
        }

        if ($fixed->isNotEmpty()) {
            $cost = $fixed->filter(fn($a) => $a->normal_balance === 'debit');
            $contra = $fixed->filter(fn($a) => $a->normal_balance === 'credit');

            $sub = [];
            if ($cost->count())   $sub[] = ['label' => 'Aset Tetap',           'accounts' => $cost->values()->all(),   'subtotal' => round($cost->sum('balance'), 2)];
            if ($contra->count()) $sub[] = ['label' => 'Akumulasi Penyusutan', 'accounts' => $contra->values()->all(), 'subtotal' => round($contra->sum('balance'), 2)];

            $groups[] = ['label' => 'Aset Tetap', 'subgroups' => $sub, 'subtotal' => round($fixed->sum('balance'), 2)];
        }

        return $groups;
    }

    /**
     * Liability: 21xx = Hutang Lancar, 22xx+ = Hutang Jangka Panjang.
     */
    protected function groupLiabilities(array $rows): array
    {
        $coll = collect($rows);
        $current = $coll->filter(fn($a) => str_starts_with((string)$a->code, '21'))->values();
        $longTerm = $coll->filter(fn($a) => !str_starts_with((string)$a->code, '21'))->values();

        $groups = [];
        if ($current->isNotEmpty()) {
            $groups[] = [
                'label' => 'Hutang Lancar',
                'subgroups' => [['label' => null, 'accounts' => $current->all(), 'subtotal' => round($current->sum('balance'), 2)]],
                'subtotal' => round($current->sum('balance'), 2),
            ];
        }
        if ($longTerm->isNotEmpty()) {
            $groups[] = [
                'label' => 'Hutang Jangka Panjang',
                'subgroups' => [['label' => null, 'accounts' => $longTerm->all(), 'subtotal' => round($longTerm->sum('balance'), 2)]],
                'subtotal' => round($longTerm->sum('balance'), 2),
            ];
        }
        return $groups;
    }

    /**
     * Equity: 1 group flat.
     */
    protected function groupEquity(array $rows): array
    {
        $coll = collect($rows);
        if ($coll->isEmpty()) return [];
        return [[
            'label' => 'Ekuitas',
            'subgroups' => [['label' => null, 'accounts' => $coll->all(), 'subtotal' => round($coll->sum('balance'), 2)]],
            'subtotal' => round($coll->sum('balance'), 2),
        ]];
    }

}
