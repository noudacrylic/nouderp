<?php

namespace App\Services;

use App\Core\Accounting\AccountBalanceService;
use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Enums\AccountTypeEnum;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrderStep;
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

    /** @var array<int,array{sku:string,name:string,stock:float,min:float,status:string}>|null */
    private ?array $stockRows = null;

    /**
     * Klasifikasi status stok SEMUA produk aktif dari stok FISIK (Σ qty_on_hand semua
     * gudang; bundle = min komponen) — SELARAS dengan halaman Stok
     * (App\Http\Controllers\Inventory\StockController). Hanya baris non-'ok' yang
     * dikembalikan. Produk preorder dikecualikan. Memoized per-request.
     *
     * Catatan: kolom `products.stock` TIDAK dipakai karena tidak dipelihara (selalu 0
     * di data nyata); sumber kebenaran stok = tabel product_stocks.
     *
     * @return array<int,array{sku:string,name:string,stock:float,min:float,status:string}>
     */
    private function stockStatusRows(): array
    {
        if ($this->stockRows !== null) {
            return $this->stockRows;
        }

        $products = Product::query()
            ->where('is_active', 1)
            ->with(['bundleItems', 'bundleComponents'])
            ->get(['id', 'sku', 'name', 'sale_type', 'min_stock']);

        // Agregasi stok fisik sekali jalan untuk produk + komponen bundle (hindari N+1).
        $componentIds = $products->flatMap(fn ($p) => $p->bundleItems->isNotEmpty()
            ? $p->bundleItems->pluck('component_product_id')
            : $p->bundleComponents->pluck('component_product_id'));
        $allIds = $products->pluck('id')->merge($componentIds)->unique()->all();

        $physical = ProductStock::whereIn('product_id', $allIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_on_hand) as total')
            ->pluck('total', 'product_id');

        $rows = [];
        foreach ($products as $product) {
            if ($product->sale_type === 'preorder') {
                continue; // preorder tidak dinilai habis/menipis
            }

            if ($product->sale_type === 'bundle') {
                $components = $product->bundleItems->isNotEmpty() ? $product->bundleItems : $product->bundleComponents;
                $stokFisik = PHP_INT_MAX;
                foreach ($components as $c) {
                    $req = ($c->qty_required ?? $c->qty ?? 1);
                    if ($req > 0) {
                        $stokFisik = min($stokFisik, (int) floor(((float) ($physical[$c->component_product_id] ?? 0)) / $req));
                    }
                }
                $stokFisik = $stokFisik === PHP_INT_MAX ? 0 : $stokFisik;
            } else {
                $stokFisik = (float) ($physical[$product->id] ?? 0);
            }

            $min = $product->min_stock;
            if ($stokFisik < 0) {
                $status = 'minus';
            } elseif ($stokFisik == 0) {
                $status = 'habis';
            } elseif ($min !== null && $stokFisik <= (float) $min) {
                $status = 'menipis';
            } else {
                continue; // 'ok' — tak perlu disimpan
            }

            $rows[] = [
                'sku'    => $product->sku,
                'name'   => $product->name,
                'stock'  => (float) $stokFisik,
                'min'    => (float) ($min ?? 0),
                'status' => $status,
            ];
        }

        return $this->stockRows = $rows;
    }

    /** Produk berstatus "menipis" (0 < stok ≤ minimum) — untuk daftar rinci dashboard. */
    public function lowStock(int $limit = 15): array
    {
        $menipis = array_values(array_filter(
            $this->stockStatusRows(),
            fn ($r) => $r['status'] === 'menipis',
        ));

        usort($menipis, fn ($a, $b) => $a['stock'] <=> $b['stock']); // paling tipis di atas

        return [
            'count' => count($menipis),
            'items' => array_slice($menipis, 0, $limit),
        ];
    }

    /**
     * Jumlah produk per status stok — SELARAS dengan filter halaman Stok, agar angka
     * kartu cocok dengan yang muncul saat di-klik.
     *
     * @return array{menipis:int,habis:int,minus:int}
     */
    public function stockStatusCounts(): array
    {
        $tally = ['menipis' => 0, 'habis' => 0, 'minus' => 0];
        foreach ($this->stockStatusRows() as $r) {
            $tally[$r['status']]++;
        }
        return $tally;
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

    /**
     * Ringkasan proses produksi per divisi: jumlah pekerjaan (langkah) yang sedang
     * antre / dikerjakan / pending di tiap divisi produksi, plus daftar pekerjaannya.
     *
     * Selaras dengan halaman Proses Produksi: hanya langkah dari OP berstatus
     * confirmed/in_progress, dan langkah 'pending' hanya dihitung bila langkah
     * sebelumnya sudah selesai (giliran sudah tiba).
     */
    public function productionSummary(): array
    {
        $steps = ProductionOrderStep::query()
            ->with([
                'productionOrder:id,order_number,type,score_type,priority_level,bom_id,created_at',
                'productionOrder.bom:id,score',
                'productionOrder.outputs.product:id,name',
                'productionOrder.steps:id,production_order_id,step_number,status',
                'executors.karyawan:id,name',
            ])
            ->whereIn('status', ['pending', 'in_progress', 'paused'])
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress']))
            ->get()
            ->filter(function ($s) {
                if ($s->status !== 'pending') {
                    return true;
                }
                $prev = optional($s->productionOrder)->steps
                    ->firstWhere('step_number', $s->step_number - 1);
                return $prev === null || $prev->status === 'completed';
            });

        $byDept = $steps->groupBy('department_id');

        // Urutan tampil pekerjaan: yang sedang dikerjakan dulu, lalu pending, lalu antre.
        $statusRank = ['in_progress' => 0, 'paused' => 1, 'pending' => 2];

        // Pembanding urutan SELARAS halaman Proses Produksi:
        //  • in_progress → mulai paling awal di atas (started_at asc)
        //  • paused      → dipending paling awal di atas (paused_at asc)
        //  • pending     → antrean prioritas: skor BOM tertinggi dulu, lalu order terlama
        $compare = function ($a, $b) use ($statusRank) {
            $ra = $statusRank[$a->status] ?? 9;
            $rb = $statusRank[$b->status] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($a->status === 'in_progress') {
                return $a->started_at <=> $b->started_at;
            }
            if ($a->status === 'paused') {
                return $a->paused_at <=> $b->paused_at;
            }
            // pending
            $sa = (float) ($a->productionOrder->effective_score ?? 0);
            $sb = (float) ($b->productionOrder->effective_score ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa; // skor lebih tinggi di atas
            }
            return optional($a->productionOrder)->created_at <=> optional($b->productionOrder)->created_at;
        };

        $divisions = Department::produksi()->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($dept) use ($byDept, $compare) {
                $deptSteps = $byDept->get($dept->id, collect());

                $antre      = $deptSteps->where('status', 'pending')->count();
                $dikerjakan = $deptSteps->where('status', 'in_progress')->count();
                $pending    = $deptSteps->where('status', 'paused')->count();

                $jobs = $deptSteps
                    ->sort($compare)
                    ->map(function ($s) {
                        $o = $s->productionOrder;
                        $output = $o?->outputs->firstWhere('output_type', 'utama')
                            ?? $o?->outputs->first();
                        return [
                            'order_number' => $o?->order_number ?? '-',
                            'product'      => $output?->product?->name ?? ($o?->type === 'custom' ? 'Custom' : '-'),
                            'step'         => $s->name,
                            'status'       => $s->status,
                            'status_label' => $s->status_label,
                            'executors'    => $s->executors->map(fn ($e) => $e->display_name)->filter()->implode(', '),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'id'         => $dept->id,
                    'name'       => $dept->name,
                    'antre'      => $antre,
                    'dikerjakan' => $dikerjakan,
                    'pending'    => $pending,
                    'total'      => $deptSteps->count(),
                    'jobs'       => $jobs,
                ];
            })
            ->values()
            ->all();

        return [
            'divisions' => $divisions,
            'totals'    => [
                'antre'      => $steps->where('status', 'pending')->count(),
                'dikerjakan' => $steps->where('status', 'in_progress')->count(),
                'pending'    => $steps->where('status', 'paused')->count(),
            ],
        ];
    }

    private function isHpp(object $row): bool
    {
        $name = strtolower((string) $row->name);
        return str_contains($name, 'harga pokok')
            || str_contains($name, 'pokok penjualan')
            || str_contains($name, 'hpp');
    }
}
