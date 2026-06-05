@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Refund Pemasok</h1>
    <a href="{{ route('finance.cash-bank.receipts.create', ['type' => 'supplier_refund']) }}"
       class="bg-purple-600 text-white px-3 py-2 rounded text-sm">+ Refund Pemasok</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nomor / pemasok / referensi"
               class="border rounded px-2 py-1.5 w-64">
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
        <button type="submit" class="bg-gray-600 text-white px-3 py-1.5 rounded">Filter</button>
        <a href="{{ route('finance.cash-bank.receipts.refund') }}" class="text-gray-500 ml-1 text-xs">Reset</a>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pemasok</th>
                <th class="px-3 py-2 text-left">Kas/Bank</th>
                <th class="px-3 py-2 text-right">Total Refund</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $cr)
                @php
                    $stCls = match($cr->status) {
                        'draft'  => 'bg-yellow-100 text-yellow-700',
                        'posted' => 'bg-green-100 text-green-700',
                        'void'   => 'bg-red-100 text-red-700',
                        default  => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('finance.cash-bank.receipts.show', $cr->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $cr->number }}</td>
                    <td class="px-3 py-2">{{ $cr->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $cr->supplier->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $cr->cashAccount->code ?? '' }} {{ $cr->cashAccount->name ?? '' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($cr->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $cr->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @unless($cr->isVoid())
                                <a href="{{ route('finance.cash-bank.receipts.print', $cr->id) }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            @endunless
                            @if($cr->isDraft())
                                <form method="POST" action="{{ route('finance.cash-bank.receipts.post', $cr->id) }}" class="inline"
                                      onsubmit="return confirm('Post refund {{ $cr->number }}?')">
                                    @csrf
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <a href="{{ route('finance.cash-bank.receipts.edit', $cr->id) }}"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                                <form method="POST" action="{{ route('finance.cash-bank.receipts.destroy', $cr->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus draft {{ $cr->number }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($cr->isPosted() && $cr->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.receipts.void', $cr->id) }}" class="inline"
                                      onsubmit="return confirm('Void refund {{ $cr->number }}? Jurnal di-void.')">
                                    @csrf
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada refund pemasok.</td></tr>
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
