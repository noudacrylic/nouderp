@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Kredit Pelanggan</h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Saldo yang diberikan tanpa uang bergerak — barang kembali tanpa refund, koreksi, atau penjualan lama di luar ERP.
            Saldonya otomatis terpakai saat pelanggan membayar faktur.
        </p>
    </div>
    <a href="{{ route('sales.kredit.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Kredit Baru</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor / pelanggan / alasan...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jenis</label>
        <select name="direction" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($directions as $val => $label)
                <option value="{{ $val }}" @selected(request('direction')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="posted" @selected(request('status')=='posted')>Posted</option>
            <option value="void" @selected(request('status')=='void')>Void</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No. Kredit</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-left">Alasan</th>
                <th class="px-3 py-2 text-left">Akun Lawan</th>
                <th class="px-3 py-2 text-right">Nominal</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-28">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($credits as $c)
                <tr class="border-b hover:bg-gray-50 {{ $c->status === 'void' ? 'opacity-50' : '' }}">
                    <td class="px-3 py-2 font-mono text-xs">{{ $c->credit_number }}</td>
                    <td class="px-3 py-2">{{ $c->credit_date?->format('d/m/Y') }}</td>
                    <td class="px-3 py-2">{{ $c->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-gray-600">{{ \Illuminate\Support\Str::limit($c->reason, 60) ?: '—' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">
                        {{ $c->counterAccount?->code }} {{ $c->counterAccount?->name }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $c->direction === 'kurang' ? 'text-red-600' : 'text-green-700' }}">
                        {{ $c->direction === 'kurang' ? '−' : '+' }} {{ number_format($c->amount, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $c->status === 'posted' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' }}">
                            {{ $c->status }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            @if($c->status === 'posted')
                                @if($c->canBeVoided())
                                    <form method="POST" action="{{ route('sales.kredit.void', $c->id) }}"
                                          onsubmit="return confirm('Void {{ $c->credit_number }}? Saldo pelanggan akan dikembalikan seperti semula.')">
                                        @csrf
                                        <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @else
                                    <span class="text-[11px] text-gray-400 cursor-help" title="{{ $c->voidBlocker() }}">Terpakai</span>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada kredit pelanggan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $credits->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
