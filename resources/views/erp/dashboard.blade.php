@extends('layouts.erp')

@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Dashboard</h1>
        <p class="text-xs text-gray-500">Ringkasan penjualan, keuangan, & operasional.</p>
    </div>
</div>

{{-- ───────── 4 Kartu Operasional (klik → halaman terkait) ───────── --}}
@php
    $opsCards = [
        ['label' => 'Perlu Diproses', 'count' => $ops['perlu_diproses'], 'url' => route('pos.fulfillment.perlu-diproses'),  'hint' => 'Pesanan menunggu diproses', 'num' => 'text-indigo-700', 'hover' => 'hover:border-indigo-300'],
        ['label' => 'Stok Menipis',   'count' => $ops['menipis'],        'url' => route('inventory.stocks.index', ['status' => 'menipis']), 'hint' => 'Stok ≤ minimum',          'num' => 'text-amber-600',  'hover' => 'hover:border-amber-300'],
        ['label' => 'Stok Habis',     'count' => $ops['habis'],          'url' => route('inventory.stocks.index', ['status' => 'habis']),   'hint' => 'Stok fisik nol',          'num' => 'text-red-600',    'hover' => 'hover:border-red-300'],
        ['label' => 'Oversell',       'count' => $ops['minus'],          'url' => route('inventory.stocks.index', ['status' => 'minus']),   'hint' => 'Stok minus (terjual berlebih)', 'num' => 'text-rose-700', 'hover' => 'hover:border-rose-300'],
    ];
@endphp
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    @foreach($opsCards as $c)
        <a href="{{ $c['url'] }}" class="bg-white rounded-lg shadow border border-gray-200 p-4 transition hover:shadow-md {{ $c['hover'] }}">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-600">{{ $c['label'] }}</span>
                <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </div>
            <div class="text-3xl font-bold mt-2 {{ (int) $c['count'] > 0 ? $c['num'] : 'text-gray-300' }}">{{ $c['count'] }}</div>
            <div class="text-[11px] text-gray-400 mt-1">{{ $c['hint'] }}</div>
        </a>
    @endforeach
</div>

{{-- ───────── Grafik Penjualan + 2 stat ───────── --}}
<div class="bg-white rounded-lg shadow border border-gray-200 p-4 mb-4">
    <div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
        <div>
            <h2 class="font-semibold text-gray-800">Penjualan</h2>
            <p class="text-xs text-gray-400" id="chart-range">{{ $sales['rangeLabel'] }}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <div id="customRange" class="hidden items-center gap-1">
                <input type="date" id="startDate" class="border rounded px-2 py-1.5 text-sm bg-white">
                <span class="text-gray-400 text-sm">–</span>
                <input type="date" id="endDate" class="border rounded px-2 py-1.5 text-sm bg-white">
            </div>
            <select id="periodSel" class="border rounded px-3 py-1.5 text-sm bg-white">
                <option value="weekly">Mingguan (7 hari)</option>
                <option value="monthly" selected>Bulanan (bulan ini)</option>
                <option value="yearly">Tahunan (12 bulan)</option>
                <option value="custom">Kustom (rentang tanggal)</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-4">
        <div class="rounded-lg border border-gray-100 border-l-4 border-l-indigo-500 bg-indigo-50/30 p-3">
            <div class="text-xs text-gray-500">Potensi Penjualan <span class="text-gray-400">(dari Sales Order)</span></div>
            <div class="text-xl font-bold text-indigo-700" id="stat-potensi">{{ $rp($sales['totalPotensi']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-100 border-l-4 border-l-emerald-500 bg-emerald-50/30 p-3">
            <div class="text-xs text-gray-500">Penjualan <span class="text-gray-400">(Faktur terbentuk)</span></div>
            <div class="text-xl font-bold text-emerald-700" id="stat-penjualan">{{ $rp($sales['totalPenjualan']) }}</div>
        </div>
    </div>

    <div style="height:300px"><canvas id="salesChart"></canvas></div>
</div>

{{-- ───────── Baris 1: Laba/Rugi · Beban · Total Aset ───────── --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    {{-- Laba/Rugi tahun ini --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-800">Laba/Rugi Tahun Ini</h2>
            <span class="text-[11px] text-gray-400">{{ $pl['rangeLabel'] }}</span>
        </div>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Pendapatan</span><span class="font-semibold text-emerald-600">{{ $rp($pl['pendapatan']) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">HPP</span><span class="font-semibold text-amber-600">{{ $rp($pl['hpp']) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Beban</span><span class="font-semibold text-rose-600">{{ $rp($pl['beban']) }}</span></div>
            <div class="flex justify-between border-t pt-2 mt-1"><span class="font-bold text-gray-800">Laba</span><span class="font-bold text-lg {{ $pl['laba'] >= 0 ? 'text-green-700' : 'text-red-600' }}">{{ $rp($pl['laba']) }}</span></div>
        </div>
    </div>

    {{-- Beban Perusahaan --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-800">Beban Perusahaan</h2>
            <span class="text-[11px] text-gray-400">{{ $expenses['rangeLabel'] }}</span>
        </div>
        <div class="text-xl font-bold text-gray-800 mb-3">{{ $rp($expenses['total']) }}</div>
        <div class="space-y-1.5 text-sm">
            @forelse($expenses['items'] as $it)
                <div class="flex justify-between"><span class="text-gray-500 truncate pr-2">{{ $it['name'] }}</span><span class="font-medium text-gray-700 whitespace-nowrap">{{ $rp($it['amount']) }}</span></div>
            @empty
                <div class="text-gray-400 text-sm">Belum ada beban bulan ini.</div>
            @endforelse
        </div>
    </div>

    {{-- Total Aset --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4 flex flex-col">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-800">Total Aset</h2>
            <span class="text-[11px] text-gray-400">Per hari ini</span>
        </div>
        <div class="flex-1 flex items-center">
            <div class="text-3xl font-bold text-gray-900">{{ $rp($totalAsset) }}</div>
        </div>
        <a href="{{ route('accounting.reports.balance-sheet') }}" class="text-xs text-blue-600 hover:underline mt-2">Lihat Neraca →</a>
    </div>
</div>

{{-- ───────── Baris 2: Top Produk · Aktivitas · Stok Menipis ───────── --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    {{-- Penjualan Top Produk --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
        <h2 class="font-semibold text-gray-800 mb-3">Penjualan Top Produk</h2>
        <div id="top-products" class="space-y-2.5 text-sm"></div>
    </div>

    {{-- Aktivitas --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
        <h2 class="font-semibold text-gray-800 mb-3">Aktivitas Terakhir</h2>
        <div class="space-y-2.5 text-sm max-h-80 overflow-y-auto">
            @forelse($activity as $a)
                <div class="flex items-start gap-2">
                    <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 mt-1.5 shrink-0"></div>
                    <div class="min-w-0 flex-1">
                        <div class="text-gray-700">{{ $a['label'] }} <span class="font-mono text-[11px] text-gray-500">{{ $a['ref'] }}</span></div>
                        <div class="text-[11px] text-gray-400">{{ $a['time'] }} · {{ $rp($a['amount']) }}</div>
                    </div>
                </div>
            @empty
                <div class="text-gray-400">Belum ada aktivitas.</div>
            @endforelse
        </div>
    </div>

    {{-- Stok Menipis --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-800">Stok Menipis</h2>
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-red-100 text-red-700">{{ $lowStock['count'] }}</span>
        </div>
        <div class="space-y-1.5 text-sm max-h-80 overflow-y-auto">
            @forelse($lowStock['items'] as $p)
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <span class="font-mono text-[11px] text-gray-400">{{ $p['sku'] }}</span>
                        <span class="text-gray-700">{{ $p['name'] }}</span>
                    </div>
                    <span class="text-xs font-semibold whitespace-nowrap {{ $p['stock'] <= 0 ? 'text-red-600' : 'text-amber-600' }}">
                        {{ rtrim(rtrim(number_format($p['stock'], 2, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format($p['min'], 2, '.', ''), '0'), '.') }}
                    </span>
                </div>
            @empty
                <div class="text-gray-400">Semua stok aman.</div>
            @endforelse
        </div>
    </div>
</div>

{{-- ───────── Proses Produksi per Divisi ───────── --}}
<div class="bg-white rounded-lg shadow border border-gray-200 p-4 mt-4">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="font-semibold text-gray-800">Proses Produksi per Divisi</h2>
            <p class="text-xs text-gray-400">Pekerjaan yang sedang berjalan di tiap divisi produksi.</p>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Dikerjakan <b class="text-gray-700">{{ $production['totals']['dikerjakan'] }}</b></span>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span>Pending <b class="text-gray-700">{{ $production['totals']['pending'] }}</b></span>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-400"></span>Antre <b class="text-gray-700">{{ $production['totals']['antre'] }}</b></span>
        </div>
    </div>

    @php
        $jobBadge = [
            'in_progress' => 'bg-emerald-100 text-emerald-700',
            'paused'      => 'bg-amber-100 text-amber-700',
            'pending'     => 'bg-slate-100 text-slate-600',
        ];
        $jobDot = [
            'in_progress' => 'bg-emerald-500',
            'paused'      => 'bg-amber-500',
            'pending'     => 'bg-slate-400',
        ];
    @endphp

    @if(count($production['divisions']) === 0)
        <div class="text-gray-400 text-sm">Belum ada divisi produksi aktif.</div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach($production['divisions'] as $d)
            <div class="rounded-lg border {{ $d['total'] > 0 ? 'border-gray-200' : 'border-gray-100 bg-gray-50/50' }} p-3 flex flex-col">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <a href="{{ route('production.process.index', ['department_id' => $d['id']]) }}" class="font-semibold text-gray-800 text-sm hover:text-indigo-600 truncate">{{ $d['name'] }}</a>
                    <div class="flex items-center gap-1 shrink-0 text-[11px] font-semibold">
                        @if($d['dikerjakan'] > 0)<span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700">{{ $d['dikerjakan'] }}</span>@endif
                        @if($d['pending'] > 0)<span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">{{ $d['pending'] }}</span>@endif
                        @if($d['antre'] > 0)<span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">{{ $d['antre'] }}</span>@endif
                    </div>
                </div>

                @if(count($d['jobs']) === 0)
                    <div class="text-[11px] text-gray-400 py-2">Tidak ada pekerjaan.</div>
                @else
                <div class="space-y-1.5 max-h-72 overflow-y-auto pr-1">
                    @foreach($d['jobs'] as $j)
                        <div class="flex items-start gap-2">
                            <span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 {{ $jobDot[$j['status']] ?? 'bg-gray-300' }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm text-gray-700 truncate">{{ $j['product'] }}</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded whitespace-nowrap {{ $jobBadge[$j['status']] ?? 'bg-gray-100 text-gray-500' }}">{{ $j['status_label'] }}</span>
                                </div>
                                <div class="text-[11px] text-gray-400 truncate">
                                    <span class="font-mono">{{ $j['order_number'] }}</span>
                                    @if($j['step']) · {{ $j['step'] }}@endif
                                    @if($j['executors']) · {{ $j['executors'] }}@endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
        @endforeach
    </div>
    @endif
</div>

<script>
(function () {
    const CHART_URL = @json(route('dashboard.chart'));
    const fmtRp = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let salesChart = null;

    function shortRp(v) {
        if (Math.abs(v) >= 1e9) return 'Rp ' + (v / 1e9).toFixed(1) + 'M';
        if (Math.abs(v) >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1) + 'jt';
        if (Math.abs(v) >= 1e3) return 'Rp ' + (v / 1e3).toFixed(0) + 'rb';
        return 'Rp ' + v;
    }

    function buildChart(d) {
        if (salesChart) {
            salesChart.data.labels = d.labels;
            salesChart.data.datasets[0].data = d.potensi;
            salesChart.data.datasets[1].data = d.penjualan;
            salesChart.update();
            return;
        }
        salesChart = new Chart(document.getElementById('salesChart'), {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: [
                    { label: 'Potensi Penjualan', data: d.potensi, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.10)', tension: .35, fill: true, pointRadius: 2 },
                    { label: 'Penjualan', data: d.penjualan, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.10)', tension: .35, fill: true, pointRadius: 2 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: { callbacks: { label: c => c.dataset.label + ': ' + fmtRp(c.raw) } },
                },
                scales: { y: { ticks: { callback: v => shortRp(v) } } },
            },
        });
    }

    function renderTop(items) {
        const box = document.getElementById('top-products');
        if (!items || !items.length) { box.innerHTML = '<div class="text-gray-400">Belum ada penjualan.</div>'; return; }
        const max = Math.max(...items.map(i => i.revenue), 1);
        const colors = ['bg-emerald-500','bg-amber-500','bg-sky-500','bg-indigo-500','bg-rose-500','bg-purple-500','bg-teal-500','bg-orange-500','bg-cyan-500','bg-lime-500'];
        box.innerHTML = items.map((it, i) => `
            <div>
                <div class="flex justify-between gap-2">
                    <span class="truncate text-gray-700"><b class="text-gray-400 mr-1">${i + 1}.</b>${esc(it.name)}</span>
                    <span class="font-semibold text-gray-800 whitespace-nowrap">${fmtRp(it.revenue)}</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded mt-1 overflow-hidden">
                    <div class="h-full ${colors[i % colors.length]}" style="width:${Math.max(3, it.revenue / max * 100)}%"></div>
                </div>
            </div>`).join('');
    }

    function apply(d) {
        document.getElementById('stat-potensi').textContent = fmtRp(d.totalPotensi);
        document.getElementById('stat-penjualan').textContent = fmtRp(d.totalPenjualan);
        document.getElementById('chart-range').textContent = d.rangeLabel;
        buildChart(d);
        renderTop(d.topProducts);
    }

    const periodSel  = document.getElementById('periodSel');
    const customRange = document.getElementById('customRange');
    const startDate  = document.getElementById('startDate');
    const endDate    = document.getElementById('endDate');

    function load() {
        const period = periodSel.value;
        let url = CHART_URL + '?period=' + encodeURIComponent(period);
        if (period === 'custom') {
            if (!startDate.value || !endDate.value) return; // tunggu kedua tanggal terisi
            url += '&start=' + startDate.value + '&end=' + endDate.value;
        }
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(apply).catch(() => {});
    }

    periodSel.addEventListener('change', function () {
        if (this.value === 'custom') {
            customRange.classList.remove('hidden');
            customRange.classList.add('flex');
            if (!startDate.value) startDate.value = @json(now()->startOfMonth()->toDateString());
            if (!endDate.value)   endDate.value   = @json(now()->toDateString());
        } else {
            customRange.classList.add('hidden');
            customRange.classList.remove('flex');
        }
        load();
    });
    startDate.addEventListener('change', load);
    endDate.addEventListener('change', load);

    apply(@json($sales));
})();
</script>
@endsection
