<?php

namespace App\Core\Accounting;

use App\Enums\AccountTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saldo per akun (leaf only) dari journal_lines + journals posted.
 *
 * Logika dipindahkan dari FinancialReportController::balancesByAccount agar bisa
 * dipakai bersama (laporan keuangan + dashboard) dengan hasil identik.
 *
 * Saldo dihitung berdasar TYPE (bukan normal_balance) supaya contra-account ber-sign benar:
 *   asset, expense            → debit - credit  (contra-asset/expense → negatif)
 *   liability, equity, revenue → credit - debit  (contra-liab/equity/rev → negatif)
 */
class AccountBalanceService
{
    /**
     * @param array $types daftar AccountTypeEnum (string), mis. [AccountTypeEnum::ASSET]
     * @param Carbon|null $dateFrom null = kumulatif sejak awal
     * @param Carbon $dateTo
     * @return array<int,object> baris saldo per akun
     */
    public function balances(array $types, ?Carbon $dateFrom, Carbon $dateTo): array
    {
        // Hanya akun leaf (tidak punya children). Posting selalu di leaf — parent skip biar tidak double-count.
        $childParentIds = Account::whereNotNull('parent_id')->distinct()->pluck('parent_id')->all();

        $rows = Account::query()
            ->whereIn('type', $types)
            ->whereNotIn('id', $childParentIds)
            ->where('is_active', 1)
            ->orderBy('code')
            ->get()
            ->map(function ($a) use ($dateFrom, $dateTo) {
                $q = DB::table('journal_lines as jl')
                    ->join('journals as j', 'j.id', '=', 'jl.journal_id')
                    ->where('jl.account_id', $a->id)
                    ->where('j.status', 'posted')
                    ->whereDate('j.date', '<=', $dateTo->toDateString());
                if ($dateFrom) {
                    $q->whereDate('j.date', '>=', $dateFrom->toDateString());
                }
                $tot = $q->selectRaw('COALESCE(SUM(jl.debit),0) AS d, COALESCE(SUM(jl.credit),0) AS c')->first();
                $debit = (float) ($tot->d ?? 0);
                $credit = (float) ($tot->c ?? 0);
                $balance = match ($a->type) {
                    AccountTypeEnum::ASSET, AccountTypeEnum::EXPENSE => $debit - $credit,
                    default => $credit - $debit, // liability, equity, revenue
                };

                return (object) [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'type' => $a->type,
                    'normal_balance' => $a->normal_balance,
                    'account_category' => $a->account_category,
                    'debit_total' => round($debit, 2),
                    'credit_total' => round($credit, 2),
                    'balance' => round($balance, 2),
                ];
            })
            // Filter akun tanpa pergerakan & saldo nol agar laporan ringkas
            ->filter(fn ($r) => abs($r->balance) > 0.001 || $r->debit_total > 0 || $r->credit_total > 0)
            ->values()
            ->all();

        return $rows;
    }
}
