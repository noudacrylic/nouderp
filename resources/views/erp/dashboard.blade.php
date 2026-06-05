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
