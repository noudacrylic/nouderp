@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Transfer Antar Bank</h1>
    <a href="{{ route('finance.cash-bank.transfers.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Transfer</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" class="border rounded px-2 py-1.5 w-64" placeholder="Nomor / referensi">
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
    <div><button class="bg-gray-600 text-white px-3 py-1.5 rounded">Filter</button></div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Dari</th>
                <th class="px-3 py-2 text-left">Ke</th>
                <th class="px-3 py-2 text-right">Jumlah</th>
                <th class="px-3 py-2 text-right">Biaya Admin</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $bt)
                @php
                    $stCls = match($bt->status) {
                        'draft'  => 'bg-yellow-100 text-yellow-700',
                        'posted' => 'bg-green-100 text-green-700',
                        'void'   => 'bg-red-100 text-red-700',
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('finance.cash-bank.transfers.show', $bt->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $bt->number }}</td>
                    <td class="px-3 py-2">{{ $bt->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $bt->fromAccount->code ?? '' }} {{ $bt->fromAccount->name ?? '' }}</td>
                    <td class="px-3 py-2">{{ $bt->toAccount->code ?? '' }} {{ $bt->toAccount->name ?? '' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($bt->amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($bt->admin_fee, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $bt->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @unless($bt->isVoid())
                                <a href="{{ route('finance.cash-bank.transfers.print', $bt->id) }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            @endunless
                            @if($bt->isDraft())
                                <form method="POST" action="{{ route('finance.cash-bank.transfers.post', $bt->id) }}" class="inline"
                                      onsubmit="return confirm('Post {{ $bt->number }}?')">
                                    @csrf
                                    <button class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <a href="{{ route('finance.cash-bank.transfers.edit', $bt->id) }}"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                                <form method="POST" action="{{ route('finance.cash-bank.transfers.destroy', $bt->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus draft {{ $bt->number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($bt->isPosted() && $bt->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.transfers.void', $bt->id) }}" class="inline"
                                      onsubmit="return confirm('Void {{ $bt->number }}?')">
                                    @csrf
                                    <button class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada transfer.</td></tr>
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
