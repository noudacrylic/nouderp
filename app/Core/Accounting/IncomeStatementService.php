<?php

namespace App\Core\Accounting;

use App\Enums\AccountTypeEnum;
use Carbon\Carbon;

/**
 * Sumber TUNGGAL klasifikasi & perhitungan Laba Rugi bertingkat (format Accurate).
 * Dipakai bersama oleh Laporan Laba Rugi (FinancialReportController) & kartu/drill-down
 * Dashboard supaya angkanya SELALU konsisten. Klasifikasi di config/income_statement.php.
 *
 * Tingkat: Pendapatan → HPP → Laba Kotor → Beban Operasional → Laba Operasional →
 *          Pendapatan/Beban Non-Operasional → Laba Bersih.
 */
class IncomeStatementService
{
    public function __construct(private AccountBalanceService $balances) {}

    private function cfg(): array
    {
        return config('income_statement');
    }

    public function operatingRevenuePrefix(): string
    {
        return (string) ($this->cfg()['operating_revenue_prefix'] ?? '40');
    }

    public function cogsCodes(): array
    {
        return array_map('strval', $this->cfg()['cogs_codes'] ?? []);
    }

    public function nonopIncomeCodes(): array
    {
        return array_map('strval', $this->cfg()['nonoperating_income_codes'] ?? []);
    }

    public function nonopExpenseCodes(): array
    {
        return array_map('strval', $this->cfg()['nonoperating_expense_codes'] ?? []);
    }

    /** Pendapatan operasional: kode diawali prefix & bukan override non-operasional. */
    public function isOperatingRevenue(object $a): bool
    {
        return str_starts_with((string) $a->code, $this->operatingRevenuePrefix())
            && ! in_array((string) $a->code, $this->nonopIncomeCodes(), true);
    }

    public function isCogs(object $a): bool
    {
        return in_array((string) $a->code, $this->cogsCodes(), true);
    }

    public function isNonopExpense(object $a): bool
    {
        return in_array((string) $a->code, $this->nonopExpenseCodes(), true);
    }

    /** Beban operasional: beban yang bukan HPP & bukan non-operasional. */
    public function isOperatingExpense(object $a): bool
    {
        return ! $this->isCogs($a) && ! $this->isNonopExpense($a);
    }

    /**
     * Ringkasan bertingkat untuk rentang tanggal. Mengembalikan koleksi akun per
     * tingkat + total tiap tingkat + laba kotor/operasional/bersih.
     */
    public function summary(?Carbon $from, Carbon $to): array
    {
        $rev = collect($this->balances->balances([AccountTypeEnum::REVENUE], $from, $to))->values();
        $exp = collect($this->balances->balances([AccountTypeEnum::EXPENSE], $from, $to))->values();

        $operatingRevenue = $rev->filter(fn ($a) => $this->isOperatingRevenue($a))->values();
        $nonopIncome      = $rev->reject(fn ($a) => $this->isOperatingRevenue($a))->values();

        $cogs         = $exp->filter(fn ($a) => $this->isCogs($a))->values();
        $nonopExpense = $exp->filter(fn ($a) => $this->isNonopExpense($a))->values();
        $opex         = $exp->filter(fn ($a) => $this->isOperatingExpense($a))->values();

        $totalRevenue    = round($operatingRevenue->sum('balance'), 2);
        $totalCogs       = round($cogs->sum('balance'), 2);
        $grossProfit     = round($totalRevenue - $totalCogs, 2);
        $totalOpex       = round($opex->sum('balance'), 2);
        $operatingIncome = round($grossProfit - $totalOpex, 2);
        $totalNonopInc   = round($nonopIncome->sum('balance'), 2);
        $totalNonopExp   = round($nonopExpense->sum('balance'), 2);
        $nonopNet        = round($totalNonopInc - $totalNonopExp, 2);
        $netIncome       = round($operatingIncome + $nonopNet, 2);

        return compact(
            'operatingRevenue', 'totalRevenue',
            'cogs', 'totalCogs', 'grossProfit',
            'opex', 'totalOpex', 'operatingIncome',
            'nonopIncome', 'totalNonopInc',
            'nonopExpense', 'totalNonopExp', 'nonopNet',
            'netIncome'
        );
    }
}
