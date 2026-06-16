@extends('layouts.erp')

@section('content')
@php
    [$sy, $sm] = array_map('intval', explode('-', $statusMonth));
    $monthName = \Carbon\Carbon::create($sy, $sm, 1)->translatedFormat('F Y');

    $totalNone   = $settlementStatuses->where('status', 'none')->count();
    $totalDraft  = $settlementStatuses->where('status', 'draft')->count();
    $totalPosted = $settlementStatuses->where('status', 'posted')->count();
@endphp

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <h1 class="text-lg font-semibold">Settlement Marketplace</h1>
    <div class="flex gap-2 flex-wrap">
        <a href="{{ route('settings.jubelio.edit') }}" class="bg-gray-600 text-white px-3 py-2 rounded text-sm">⚙ Marketplace Config</a>
        <a href="{{ route('finance.cash-bank.settlements.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Upload Settlement</a>
    </div>
</div>

{{-- ============ Per-marketplace status untuk bulan terpilih ============ --}}
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-sm font-semibold">Status Settlement · {{ $monthName }}</h2>
            <div class="text-xs text-gray-500">
                <span class="text-red-600 font-semibold">⚠ {{ $totalNone }} belum</span> ·
                <span class="text-yellow-700 font-semibold">✏ {{ $totalDraft }} draf</span> ·
                <span class="text-green-700 font-semibold">✅ {{ $totalPosted }} diposting</span>
            </div>
        </div>
        <form method="GET" class="flex items-end gap-2">
            @foreach(['status','marketplace_config_id','date_from','date_to'] as $k)
                @if(request($k)) <input type="hidden" name="{{ $k }}" value="{{ request($k) }}"> @endif
            @endforeach
            <div>
                <label class="block text-xs text-gray-500 mb-1">Lihat bulan</label>
                <input type="month" name="status_month" value="{{ $statusMonth }}" class="border rounded px-2 h-8 text-sm">
            </div>
            <button class="bg-gray-600 text-white px-3 h-8 rounded text-xs">Tampilkan</button>
        </form>
    </div>

    @if($settlementStatuses->isEmpty())
        <div class="text-center text-gray-400 text-sm py-4">
            Belum ada Marketplace Config aktif.
            <a href="{{ route('settings.jubelio.edit') }}" class="text-blue-600 underline">Atur sekarang</a>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
            @foreach($settlementStatuses as $row)
                @php
                    $cfg = $row['config'];
                    $st  = $row['status'];
                    [$color, $badge, $badgeCls] = match($st) {
                        'posted' => ['bg-green-50 border-green-300',  '✅ Diposting', 'bg-green-100 text-green-700'],
                        'draft'  => ['bg-yellow-50 border-yellow-300', '✏ Draf',  'bg-yellow-100 text-yellow-700'],
                        default  => ['bg-red-50 border-red-300',      '⚠ Belum',  'bg-red-100 text-red-700'],
                    };
                    $listUrl = route('finance.cash-bank.settlements.index', [
                        'marketplace_config_id' => $cfg->id,
                        'date_from' => sprintf('%04d-%02d-01', $sy, $sm),
                        'date_to'   => \Carbon\Carbon::create($sy, $sm, 1)->endOfMonth()->toDateString(),
                        'status_month' => $statusMonth,
                    ]);
                @endphp
                <div class="border rounded px-3 py-2 text-sm flex items-center justify-between gap-2 {{ $color }}">
                    <div class="min-w-0">
                        <div class="font-semibold truncate">{{ $cfg->customer->name ?? '#'.$cfg->id }}</div>
                        <div class="text-xs text-gray-600 flex items-center gap-2 mt-0.5 flex-wrap">
                            <span class="px-1.5 py-0.5 rounded {{ $badgeCls }}">{{ $badge }}</span>
                            @if($row['posted_count'] || $row['draft_count'])
                                <span class="text-[11px] text-gray-700">
                                    {{ $row['posted_count'] }} diposting{{ $row['draft_count'] ? ' · '.$row['draft_count'].' draf' : '' }}
                                </span>
                            @endif
                            @if($row['latest_date'])
                                <span class="text-[11px] text-gray-500">· terakhir {{ \Carbon\Carbon::parse($row['latest_date'])->format('d M Y') }}</span>
                            @endif
                        </div>
                        @if($row['total_net'] != 0)
                            <div class="text-[11px] text-gray-600 mt-0.5">Net bulan ini: Rp {{ number_format($row['total_net'], 0, ',', '.') }}</div>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1 shrink-0">
                        @if($row['posted_count'] || $row['draft_count'])
                            <a href="{{ $listUrl }}" class="bg-gray-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap">Lihat</a>
                        @else
                            <span class="text-[11px] text-gray-400 whitespace-nowrap">Belum ada</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ============ History list ============ --}}
<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @if(request('status_month')) <input type="hidden" name="status_month" value="{{ request('status_month') }}"> @endif
    <div>
        <label class="block text-xs text-gray-500 mb-1">Marketplace</label>
        <select name="marketplace_config_id" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($configs as $cfg)
                <option value="{{ $cfg->id }}" @selected(request('marketplace_config_id')==$cfg->id)>{{ $cfg->customer->name ?? '#'.$cfg->id }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status')=='draft')>Draf</option>
            <option value="posted" @selected(request('status')=='posted')>Diposting</option>
            <option value="void" @selected(request('status')=='void')>Void</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-700 cursor-pointer">
            <input type="checkbox" name="has_pending" value="1" {{ request('has_pending') ? 'checked' : '' }}>
            <span class="font-semibold">Punya Pending</span>
            <span class="text-gray-400">(draft + unmatched)</span>
        </label>
    </div>
    <div><button class="bg-gray-600 text-white px-3 py-1.5 rounded">Filter</button></div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Marketplace</th>
                <th class="px-3 py-2 text-center">Match</th>
                <th class="px-3 py-2 text-right">Gross</th>
                <th class="px-3 py-2 text-right">Fee Aktual</th>
                <th class="px-3 py-2 text-right">Selisih Fee</th>
                <th class="px-3 py-2 text-right">Net</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-60">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $ms)
                @php
                    $stCls = match($ms->status) {
                        'draft'  => 'bg-yellow-100 text-yellow-700',
                        'posted' => 'bg-green-100 text-green-700',
                        'void'   => 'bg-red-100 text-red-700',
                    };
                    $unmatched = (int) ($ms->lines_unmatched ?? 0);
                    $total = (int) ($ms->lines_total ?? 0);
                    $matched = $total - $unmatched;
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('finance.cash-bank.settlements.show', $ms->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $ms->number }}</td>
                    <td class="px-3 py-2">{{ $ms->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $ms->marketplaceConfig->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-center text-xs whitespace-nowrap">
                        <span class="text-green-700 font-semibold">{{ $matched }}</span>
                        <span class="text-gray-400">/ {{ $total }}</span>
                        @if($unmatched > 0)
                            <div class="text-[10px] text-amber-700 font-semibold mt-0.5">⚠ {{ $unmatched }} pending</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($ms->total_gross, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($ms->total_fee_actual, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right {{ abs($ms->total_fee_diff) > 0.01 ? 'text-amber-700 font-semibold' : '' }}">{{ number_format($ms->total_fee_diff, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($ms->total_net, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $ms->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @if($ms->isDraft())
                                @if($matched > 0)
                                    <form method="POST" action="{{ route('finance.cash-bank.settlements.post', $ms->id) }}" class="inline"
                                          onsubmit="return confirm('Submit {{ $matched }} matched dari {{ $ms->number }}?@if($unmatched > 0) Sisa {{ $unmatched }} unmatched akan dipindah ke settlement baru.@endif')">
                                        @csrf
                                        <button class="bg-green-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap">Submit</button>
                                    </form>
                                @endif
                                @if($unmatched > 0)
                                    <form method="POST" action="{{ route('finance.cash-bank.settlements.retry-match', $ms->id) }}" class="inline" onsubmit="return confirm('Retry match {{ $unmatched }} unmatched?')">
                                        @csrf
                                        <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap" title="Re-run matching">Retry</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('finance.cash-bank.settlements.destroy', $ms->id) }}" class="inline" onsubmit="return confirm('Hapus draf?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($ms->isPosted() && $ms->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.settlements.void', $ms->id) }}" class="inline" onsubmit="return confirm('Void {{ $ms->number }}?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada settlement.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($items instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $items->links() }}</div>
@endif

<script>
    document.querySelectorAll('tr[data-href]').forEach(tr => {
        tr.addEventListener('click', () => window.location.href = tr.dataset.href);
    });
</script>
@endsection
