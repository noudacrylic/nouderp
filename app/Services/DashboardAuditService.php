<?php

namespace App\Services;

use App\Core\Accounting\Account;
use App\Enums\AccountTypeEnum;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rincian (drill-down audit) untuk angka-angka dashboard.
 *
 * Dua bentuk hasil:
 *   • 'documents' → daftar dokumen sumber (Sales Order / Faktur) untuk angka penjualan.
 *   • 'ledger'    → RINGKASAN per akun (saldo + total debit/kredit) untuk angka akuntansi
 *                   (Pendapatan / HPP / Beban / Total Aset). Audit baris jurnal rinci
 *                   ditelusuri di Buku Besar akun (accounts.show), selaras dgn DashboardService.
 *
 * Total tiap metric DISENGAJA dihitung dengan logika identik dgn DashboardService/
 * AccountBalanceService agar angka rincian cocok dengan angka kartu dashboard.
 */
class DashboardAuditService
{
    public function __construct(private DashboardService $dashboard) {}

    /** Metric yang dikenal + apakah perlu rentang tanggal penjualan (period/start/end). */
    public const METRICS = [
        'potensi'     => 'Potensi Penjualan',
        'penjualan'   => 'Penjualan',
        'pendapatan'  => 'Pendapatan',
        'hpp'         => 'HPP',
        'beban'       => 'Beban (Laba/Rugi)',
        'beban_bulan' => 'Beban Perusahaan',
        'aset'        => 'Total Aset',
    ];

    /**
     * @param array{period?:?string,start?:?string,end?:?string,account_id?:?int} $params
     */
    public function build(string $metric, array $params = []): array
    {
        return match ($metric) {
            'potensi'     => $this->potensi($params),
            'penjualan'   => $this->penjualan($params),
            'pendapatan'  => $this->ledgerRevenue(),
            'hpp'         => $this->ledgerExpense(true, 'ytd', $params),
            'beban'       => $this->ledgerExpense(false, 'ytd', $params),
            'beban_bulan' => $this->ledgerExpense(false, 'month', $params),
            'aset'        => $this->ledgerAsset($params),
            default       => throw new \InvalidArgumentException("Metric tidak dikenal: {$metric}"),
        };
    }

    // ───────────────────────── Dokumen penjualan ─────────────────────────

    private function potensi(array $p): array
    {
        [$start, $end] = $this->dashboard->resolveRange($p['period'] ?? 'monthly', $p['start'] ?? null, $p['end'] ?? null);

        $rows = SalesOrder::query()
            ->whereNotIn('status', ['void', 'cancelled'])
            ->where('paid_amount', '>', 0.01) // hanya SO yang sudah ada DP/pembayaran
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('customers as c', 'c.id', '=', 'sales_orders.customer_id')
            ->orderByDesc('order_date')        // terbaru di atas
            ->orderByDesc('sales_orders.id')
            ->get(['sales_orders.id', 'sales_orders.order_number', 'sales_orders.order_date', 'sales_orders.status', 'sales_orders.grand_total', 'c.name as customer_name'])
            ->map(fn ($r) => [
                'url'      => route('sales.orders.show', $r->id),
                'number'   => $r->order_number,
                'date'     => $r->order_date,
                'customer' => $r->customer_name ?: '—',
                'status'   => $r->status,
                'amount'   => (float) $r->grand_total,
            ])->all();

        return [
            'type'       => 'documents',
            'title'      => 'Rincian Potensi Penjualan',
            'subtitle'   => 'Sales Order yang sudah ada DP/pembayaran (kecuali void/batal) pada rentang terpilih',
            'rangeLabel' => $this->rangeLabel($start, $end),
            'total'      => round(array_sum(array_column($rows, 'amount'))),
            'columns'    => ['No. Sales Order', 'Tanggal', 'Pelanggan', 'Status', 'Total'],
            'rows'       => $rows,
        ];
    }

    private function penjualan(array $p): array
    {
        [$start, $end] = $this->dashboard->resolveRange($p['period'] ?? 'monthly', $p['start'] ?? null, $p['end'] ?? null);

        $rows = SalesInvoice::query()
            ->where('status', 'posted')
            ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
            ->leftJoin('customers as c', 'c.id', '=', 'sales_invoices.customer_id')
            ->orderByDesc('invoice_date')      // terbaru di atas
            ->orderByDesc('sales_invoices.id')
            ->get(['sales_invoices.id', 'sales_invoices.invoice_number', 'sales_invoices.invoice_date', 'sales_invoices.grand_total', 'c.name as customer_name'])
            ->map(fn ($r) => [
                'url'      => route('sales.invoices.show', $r->id),
                'number'   => $r->invoice_number,
                'date'     => $r->invoice_date,
                'customer' => $r->customer_name ?: '—',
                'status'   => 'posted',
                'amount'   => (float) $r->grand_total,
            ])->all();

        return [
            'type'       => 'documents',
            'title'      => 'Rincian Penjualan',
            'subtitle'   => 'Faktur Penjualan (posted) pada rentang terpilih',
            'rangeLabel' => $this->rangeLabel($start, $end),
            'total'      => round(array_sum(array_column($rows, 'amount'))),
            'columns'    => ['No. Faktur', 'Tanggal', 'Pelanggan', 'Status', 'Total'],
            'rows'       => $rows,
        ];
    }

    // ───────────────────────── Buku besar (akuntansi) ─────────────────────────

    private function ledgerRevenue(): array
    {
        $from = Carbon::now()->startOfYear();
        $to   = Carbon::today();

        // Selaras kartu dashboard: hanya pendapatan PENJUALAN — kecualikan selisih
        // stok, pendapatan/selisih ongkir, & keuntungan pelepasan aset.
        $accounts = $this->leafAccounts([AccountTypeEnum::REVENUE])
            ->filter(fn ($a) => $this->dashboard->isSalesRevenue($a));

        return $this->ledger(
            accounts: $accounts,
            from: $from,
            to: $to,
            title: 'Rincian Pendapatan',
            subtitle: 'Buku besar akun pendapatan penjualan — tahun berjalan (YTD)',
        );
    }

    private function ledgerExpense(bool $hppOnly, string $window, array $p): array
    {
        $to = Carbon::today();
        $from = $window === 'month' ? Carbon::now()->startOfMonth() : Carbon::now()->startOfYear();

        $accounts = $this->leafAccounts([AccountTypeEnum::EXPENSE])
            ->filter(fn ($a) => $this->isHpp($a->name) === $hppOnly);

        if (!empty($p['account_id'])) {
            $accounts = $accounts->where('id', (int) $p['account_id']);
        }

        $title = $hppOnly
            ? 'Rincian HPP (Harga Pokok Penjualan)'
            : ($window === 'month' ? 'Rincian Beban Perusahaan' : 'Rincian Beban (Laba/Rugi)');

        $windowLabel = $window === 'month' ? 'bulan berjalan' : 'tahun berjalan (YTD)';

        return $this->ledger(
            accounts: $accounts,
            from: $from,
            to: $to,
            title: $title,
            subtitle: 'Buku besar akun beban — ' . $windowLabel,
        );
    }

    private function ledgerAsset(array $p): array
    {
        $to = Carbon::today();

        $accounts = $this->leafAccounts([AccountTypeEnum::ASSET]);
        if (!empty($p['account_id'])) {
            $accounts = $accounts->where('id', (int) $p['account_id']);
        }

        return $this->ledger(
            accounts: $accounts,
            from: null, // kumulatif sejak awal — selaras totalAssets()
            to: $to,
            title: 'Rincian Total Aset',
            subtitle: 'Buku besar akun aset — kumulatif s/d hari ini',
        );
    }

    /**
     * Bangun struktur buku besar untuk sekumpulan akun leaf.
     * Saldo per akun & total dihitung searah dgn AccountBalanceService.
     */
    private function ledger($accounts, ?Carbon $from, Carbon $to, string $title, string $subtitle): array
    {
        $accountIds = $accounts->pluck('id')->all();

        // Agregat per akun (debit/kredit/jumlah baris) — RINGKAS, tanpa baris jurnal individual.
        // Audit rinci ditelusuri di Buku Besar akun (accounts.show), per permintaan: daftar
        // jurnal lengkap lebih mudah dibaca di sana (ada filter periode & saldo berjalan).
        $agg = collect();
        if ($accountIds) {
            $q = DB::table('journal_lines as jl')
                ->join('journals as j', 'j.id', '=', 'jl.journal_id')
                ->whereIn('jl.account_id', $accountIds)
                ->where('j.status', 'posted')
                ->whereDate('j.date', '<=', $to->toDateString());
            if ($from) {
                $q->whereDate('j.date', '>=', $from->toDateString());
            }
            $agg = $q->groupBy('jl.account_id')
                ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit),0) as d, COALESCE(SUM(jl.credit),0) as c, COUNT(*) as n')
                ->get()
                ->keyBy('account_id');
        }

        $accountRows = [];
        $grandTotal = 0.0;

        foreach ($accounts as $a) {
            $row = $agg->get($a->id);
            if (!$row) {
                continue; // tampilkan hanya akun yg ada pergerakan (selaras dashboard)
            }

            $debitTotal  = (float) $row->d;
            $creditTotal = (float) $row->c;
            // arah natural: asset/expense naik dgn debit; lainnya (rev/liab/equity) naik dgn credit
            $credClass = in_array($a->type, [AccountTypeEnum::ASSET, AccountTypeEnum::EXPENSE], true);
            $balance = $credClass ? ($debitTotal - $creditTotal) : ($creditTotal - $debitTotal);
            $grandTotal += $balance;

            $accountRows[] = [
                'id'           => $a->id,
                'code'         => $a->code,
                'name'         => $a->name,
                'line_count'   => (int) $row->n,
                'debit_total'  => round($debitTotal, 2),
                'credit_total' => round($creditTotal, 2),
                'balance'      => round($balance, 2),
                'ledger_url'   => $this->ledgerUrl($a->id, $from, $to),
            ];
        }

        return [
            'type'       => 'ledger',
            'title'      => $title,
            'subtitle'   => $subtitle,
            'rangeLabel' => $this->rangeLabel($from, $to),
            'total'      => round($grandTotal),
            'accounts'   => $accountRows,
        ];
    }

    /** URL ke Buku Besar akun (accounts.show) dgn filter periode selaras metric. */
    private function ledgerUrl(int $accountId, ?Carbon $from, Carbon $to): string
    {
        $params = ['account' => $accountId, 'end_date' => $to->toDateString()];
        if ($from) {
            $params['start_date'] = $from->toDateString();
        }
        return route('accounts.show', $params);
    }

    // ───────────────────────── Helpers ─────────────────────────

    /** Akun leaf (tanpa anak) aktif untuk tipe tertentu — sama dgn AccountBalanceService. */
    private function leafAccounts(array $types)
    {
        $childParentIds = Account::whereNotNull('parent_id')->distinct()->pluck('parent_id')->all();

        return Account::query()
            ->whereIn('type', $types)
            ->whereNotIn('id', $childParentIds)
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }

    private function isHpp(string $name): bool
    {
        $name = strtolower($name);
        return str_contains($name, 'harga pokok')
            || str_contains($name, 'pokok penjualan')
            || str_contains($name, 'hpp');
    }

    private function rangeLabel(?Carbon $from, Carbon $to): string
    {
        if (!$from) {
            return 's/d ' . $to->isoFormat('D MMM Y');
        }
        $sameYear = $from->year === $to->year;
        return $from->isoFormat($sameYear ? 'D MMM' : 'D MMM Y') . ' – ' . $to->isoFormat('D MMM Y');
    }
}
