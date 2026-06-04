@extends('layouts.erp')

@section('content')
@php
    [$sy, $sm] = array_map('intval', explode('-', $statusMonth));
    $monthName = \Carbon\Carbon::create($sy, $sm, 1)->translatedFormat('F Y');

    $totalNone      = $accountStatuses->where('status', 'none')->count();
    $totalDraft     = $accountStatuses->where('status', 'draft')->count();
    $totalCompleted = $accountStatuses->where('status', 'completed')->count();
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Rekonsiliasi Bank</h1>
    <div class="text-xs text-gray-500">Klik tombol <b>Mulai</b> di kartu akun di bawah untuk memulai.</div>
</div>

{{-- ============ Per-akun status untuk bulan terpilih ============ --}}
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
            <h2 class="text-sm font-semibold">Status Rekonsiliasi · {{ $monthName }}</h2>
            <div class="text-xs text-gray-500">
                <span class="text-red-600 font-semibold">⚠ {{ $totalNone }} belum</span> ·
                <span class="text-yellow-700 font-semibold">✏ {{ $totalDraft }} draft</span> ·
                <span class="text-green-700 font-semibold">✅ {{ $totalCompleted }} selesai</span>
            </div>
        </div>
        <form method="GET" class="flex items-end gap-2">
            @foreach(['status','account_id','period_year','period_month'] as $k)
                @if(request($k)) <input type="hidden" name="{{ $k }}" value="{{ request($k) }}"> @endif
            @endforeach
            <div>
                <label class="block text-xs text-gray-500 mb-1">Lihat bulan</label>
                <input type="month" name="status_month" value="{{ $statusMonth }}" class="border rounded px-2 h-8 text-sm">
            </div>
            <button class="bg-gray-600 text-white px-3 h-8 rounded text-xs">Tampilkan</button>
        </form>
    </div>

    @if($accountStatuses->isEmpty())
        <div class="text-center text-gray-400 text-sm py-4">Belum ada akun bank/kas aktif.</div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
            @foreach($accountStatuses as $row)
                @php
                    $acc = $row['account'];
                    $st  = $row['status'];
                    [$color, $badge, $badgeCls] = match($st) {
                        'completed' => ['bg-green-50 border-green-300',  '✅ Sudah',  'bg-green-100 text-green-700'],
                        'draft'     => ['bg-yellow-50 border-yellow-300', '✏ Draft',  'bg-yellow-100 text-yellow-700'],
                        default     => ['bg-red-50 border-red-300',      '⚠ Belum',  'bg-red-100 text-red-700'],
                    };
                @endphp
                <div class="border rounded px-3 py-2 text-sm flex items-center justify-between gap-2 {{ $color }}">
                    <div class="min-w-0">
                        <div class="font-semibold truncate">{{ $acc->code }} — {{ $acc->name }}</div>
                        <div class="text-xs text-gray-600 flex items-center gap-2 mt-0.5">
                            <span class="px-1.5 py-0.5 rounded {{ $badgeCls }}">{{ $badge }}</span>
                            @if($row['br_number'])
                                <span class="font-mono text-[11px] text-gray-700">{{ $row['br_number'] }}</span>
                            @endif
                            @if($row['latest_period'] && $row['latest_period'] !== $statusMonth)
                                <span class="text-[11px] text-gray-500">· terakhir {{ $row['latest_period'] }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex gap-1 shrink-0">
                        @if($st === 'completed')
                            <a href="{{ route('finance.cash-bank.reconciliations.show', $row['br_id']) }}" class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Lihat</a>
                        @elseif($st === 'draft')
                            <a href="{{ route('finance.cash-bank.reconciliations.edit', $row['br_id']) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Lanjut</a>
                        @else
                            <form method="POST" action="{{ route('finance.cash-bank.reconciliations.store') }}" class="inline">
                                @csrf
                                <input type="hidden" name="account_id" value="{{ $acc->id }}">
                                <input type="hidden" name="period" value="{{ $statusMonth }}">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">Mulai</button>
                            </form>
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
        <label class="block text-xs text-gray-500 mb-1">Akun</label>
        <select name="account_id" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($accounts as $acc)
                <option value="{{ $acc->id }}" @selected(request('account_id')==$acc->id)>{{ $acc->code }} — {{ $acc->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tahun</label>
        <input type="number" name="period_year" value="{{ request('period_year') }}" class="border rounded px-2 py-1.5 w-24">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Bulan</label>
        <input type="number" min="1" max="12" name="period_month" value="{{ request('period_month') }}" class="border rounded px-2 py-1.5 w-20">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status')=='draft')>Draft</option>
            <option value="completed" @selected(request('status')=='completed')>Completed</option>
            <option value="void" @selected(request('status')=='void')>Void</option>
        </select>
    </div>
    <div><button class="bg-gray-600 text-white px-3 py-1.5 rounded">Filter</button></div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Akun</th>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-right">Saldo Buku</th>
                <th class="px-3 py-2 text-right">Saldo Koran</th>
                <th class="px-3 py-2 text-right">Selisih</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-60">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $br)
                @php
                    $stCls = match($br->status) {
                        'draft'     => 'bg-yellow-100 text-yellow-700',
                        'completed' => 'bg-green-100 text-green-700',
                        'void'      => 'bg-red-100 text-red-700',
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route($br->isDraft() ? 'finance.cash-bank.reconciliations.edit' : 'finance.cash-bank.reconciliations.show', $br->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $br->number }}</td>
                    <td class="px-3 py-2">{{ $br->account->code ?? '' }} {{ $br->account->name ?? '' }}</td>
                    <td class="px-3 py-2">{{ $br->start_date->format('d M Y') }} – {{ $br->end_date->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($br->book_balance, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($br->statement_balance, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right {{ abs($br->difference) > 0.01 ? 'text-red-600 font-semibold' : '' }}">{{ number_format($br->difference, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $br->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @if($br->isDraft())
                                <a href="{{ route('finance.cash-bank.reconciliations.edit', $br->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Lanjutkan</a>
                                <form method="POST" action="{{ route('finance.cash-bank.reconciliations.destroy', $br->id) }}" class="inline" onsubmit="return confirm('Hapus draft {{ $br->number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($br->isCompleted() && $br->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.reconciliations.void', $br->id) }}" class="inline" onsubmit="return confirm('Void rekonsiliasi {{ $br->number }}?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada rekonsiliasi.</td></tr>
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
