<?php

namespace App\Services;

use App\Core\Accounting\AccountBalanceService;
use App\Core\Inventory\Product;
use App\Enums\AccountTypeEnum;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(private AccountBalanceService $balances) {}

    /**
     * Deret penjualan untuk grafik: Potensi Penjualan (SO) + Penjualan (Invoice posted).
     * @param string $period weekly|monthly|yearly|custom
     * @param string|null $startDate tanggal awal (Y-m-d) untuk period=custom
     * @param string|null $endDate   tanggal akhir (Y-m-d) untuk period=custom
     */
    public function salesSeries(string $period = 'monthly', ?string $startDate = null, ?string $endDate = null): array
    {
        $today = Carbon::today();

        if ($period === 'custom') {
            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $end   = Carbon::parse($endDate)->startOfDay();
            } catch (\Throwable $e) {
                $start = $today->copy()->startOfMonth();
                $end   = $today->copy();
            }
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }
            // Granularitas adaptif: rentang panjang (> 92 hari) per bulan, selain itu per hari.
            $mode = $start->diffInDays($end) > 92 ? 'month' : 'day';
        } else {
            [$start, $mode] = match ($period) {
                'weekly' => [$today->copy()->subDays(6), 'day'],
                'yearly' => [$today->copy()->startOfYear(), 'month'],
                default  => [$today->copy()->startOfMonth(), 'day'], // monthly
            };
            $end = $today->copy();
        }

        $soQ  = SalesOrder::whereNotIn('status', ['void', 'cancelled'])->whereBetween('order_date', [$start->toDateString(), $end->toDateString()]);
        $invQ = SalesInvoice::where('status', 'posted')->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()]);

        $labels = [];
        $potensi = [];
        $penjualan = [];

        if ($mode === 'day') {
            $soMap  = (clone $soQ)->selectRaw('DATE(order_date) as d, SUM(grand_total) as t')->groupBy('d')->pluck('t', 'd');
            $invMap = (clone $invQ)->selectRaw('DATE(invoice_date) as d, SUM(grand_total) as t')->groupBy('d')->pluck('t', 'd');
            $fmt = $period === 'monthly' ? 'j' : 'd/m';
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $key = $d->toDateString();
                $labels[]    = $d->format($fmt);
                $potensi[]   = round((float) ($soMap[$key] ?? 0));
                $penjualan[] = round((float) ($invMap[$key] ?? 0));
            }
        } else { // month — pakai kunci Y-m agar aman lintas tahun
            $soMap  = (clone $soQ)->selectRaw("DATE_FORMAT(order_date, '%Y-%m') as ym, SUM(grand_total) as t")->groupBy('ym')->pluck('t', 'ym');
            $invMap = (clone $invQ)->selectRaw("DATE_FORMAT(invoice_date, '%Y-%m') as ym, SUM(grand_total) as t")->groupBy('ym')->pluck('t', 'ym');
            $crossYear = $start->year !== $end->year;
            for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
                $key = $m->format('Y-m');
                $labels[]    = self::MONTHS[$m->month] . ($crossYear ? " '" . $m->format('y') : '');
                $potensi[]   = round((float) ($soMap[$key] ?? 0));
                $penjualan[] = round((float) ($invMap[$key] ?? 0));
            }
        }

        return [
            'period'         => $period,
            'labels'         => $labels,
            'potensi'        => $potensi,
            'penjualan'      => $penjualan,
            'totalPotensi'   => round(array_sum($potensi)),
            'totalPenjualan' => round(array_sum($penjualan)),
            'rangeLabel'     => $start->isoFormat($start->year !== $end->year ? 'D MMM Y' : 'D MMM') . ' – ' . $end->isoFormat('D MMM Y'),
            'topProducts'    => $this->topProducts($start, $end),
        ];
    }

    /** Laba/Rugi tahun berjalan (YTD): Pendapatan, HPP, Beban, Laba. */
    public function profitLoss(): array
    {
        $from = Carbon::now()->startOfYear();
        $to   = Carbon::today();

        $rev = collect($this->balances->balances([AccountTypeEnum::REVENUE], $from, $to));
        $exp = collect($this->balances->balances([AccountTypeEnum::EXPENSE], $from, $to));

        $pendapatan = round($rev->sum('balance'), 2);
        $hpp = round($exp->filter(fn ($r) => $this->isHpp($r))->sum('balance'), 2);
        $beban = round($exp->reject(fn ($r) => $this->isHpp($r))->sum('balance'), 2);
        $laba = round($pendapatan - $hpp - $beban, 2);

        return compact('pendapatan', 'hpp', 'beban', 'laba')
            + ['rangeLabel' => $from->isoFormat('D MMM') . ' – ' . $to->isoFormat('D MMM Y')];
    }

    /** Beban perusahaan bulan berjalan (operasional, tanpa HPP) — rincian akun + total. */
    public function expenses(): array
    {
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::today();

        $exp = collect($this->balances->balances([AccountTypeEnum::EXPENSE], $from, $to))
            ->reject(fn ($r) => $this->isHpp($r))
            ->sortByDesc('balance')
            ->values();

        $total = round($exp->sum('balance'), 2);

        $top = $exp->take(6)->map(fn ($r) => ['name' => $r->name, 'amount' => round($r->balance, 2)])->values()->all();
        $rest = round($exp->slice(6)->sum('balance'), 2);
        if ($rest != 0) {
            $top[] = ['name' => 'Lainnya', 'amount' => $rest];
        }

        return [
            'total'      => $total,
            'items'      => $top,
            'rangeLabel' => $from->isoFormat('D MMM') . ' – ' . $to->isoFormat('D MMM Y'),
        ];
    }

    /** Total aset per hari ini. */
    public function totalAssets(): float
    {
        $assets = collect($this->balances->balances([AccountTypeEnum::ASSET], null, Carbon::today()));
        return round($assets->sum('balance'), 2);
    }

    /** Top produk by omzet (invoice posted) dalam rentang. */
    public function topProducts(Carbon $from, Carbon $to, int $limit = 10): array
    {
        return DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'sii.product_id')
            ->where('si.status', 'posted')
            ->whereBetween('si.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->where('sii.item_type', 'product')
            ->whereNotNull('sii.product_id')
            ->groupBy('sii.product_id', 'p.name')
            ->selectRaw('p.name as name, SUM(sii.subtotal) as revenue, SUM(sii.qty) as qty')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['name' => $r->name ?? '-', 'revenue' => round((float) $r->revenue), 'qty' => (float) $r->qty])
            ->all();
    }

    /** Produk dengan stok <= minimum. */
    public function lowStock(int $limit = 15): array
    {
        $items = Product::query()
            ->whereNotNull('min_stock')
            ->where('min_stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', 1)
            ->orderBy('stock')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'stock', 'min_stock']);

        $count = Product::query()
            ->whereNotNull('min_stock')->where('min_stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')->where('is_active', 1)
            ->count();

        return [
            'count' => $count,
            'items' => $items->map(fn ($p) => [
                'sku'   => $p->sku,
                'name'  => $p->name,
                'stock' => (float) $p->stock,
                'min'   => (float) $p->min_stock,
            ])->all(),
        ];
    }

    /** Aktivitas terbaru: gabungan faktur, SO, & pembayaran. */
    public function recentActivity(int $limit = 15): array
    {
        $inv = SalesInvoice::latest('created_at')->limit($limit)
            ->get(['invoice_number', 'grand_total', 'created_at'])
            ->map(fn ($r) => ['label' => 'Buat Faktur Penjualan', 'ref' => $r->invoice_number, 'amount' => (float) $r->grand_total, 'at' => $r->created_at]);

        $so = SalesOrder::latest('created_at')->limit($limit)
            ->get(['order_number', 'grand_total', 'created_at'])
            ->map(fn ($r) => ['label' => 'Buat Sales Order', 'ref' => $r->order_number, 'amount' => (float) $r->grand_total, 'at' => $r->created_at]);

        $pay = CustomerPayment::latest('created_at')->limit($limit)
            ->get(['payment_number', 'amount', 'created_at'])
            ->map(fn ($r) => ['label' => 'Pembayaran Customer', 'ref' => $r->payment_number, 'amount' => (float) $r->amount, 'at' => $r->created_at]);

        return $inv->concat($so)->concat($pay)
            ->filter(fn ($r) => $r['at'] !== null)
            ->sortByDesc('at')
            ->take($limit)
            ->map(fn ($r) => [
                'label'  => $r['label'],
                'ref'    => $r['ref'],
                'amount' => round($r['amount']),
                'time'   => Carbon::parse($r['at'])->isoFormat('D MMM HH:mm'),
            ])
            ->values()
            ->all();
    }

    private function isHpp(object $row): bool
    {
        $name = strtolower((string) $row->name);
        return str_contains($name, 'harga pokok')
            || str_contains($name, 'pokok penjualan')
            || str_contains($name, 'hpp');
    }
}
