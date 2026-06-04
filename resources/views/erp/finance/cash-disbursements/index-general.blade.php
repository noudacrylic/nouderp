@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pengeluaran Umum</h1>
    <a href="{{ route('finance.cash-bank.disbursements.create', ['type' => 'general']) }}"
       class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Pengeluaran Umum</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nomor / penerima / keterangan"
               class="border rounded px-2 py-1.5 w-64">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status')=='draft')>Draft</option>
            <option value="posted" @selected(request('status')=='posted')>Posted</option>
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
        <a href="{{ route('finance.cash-bank.disbursements.general') }}" class="text-gray-500 ml-1 text-xs">Reset</a>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Penerima / Keterangan</th>
                <th class="px-3 py-2 text-left">Kas/Bank</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $cd)
                @php
                    $stCls = match($cd->status) {
                        'draft'  => 'bg-yellow-100 text-yellow-700',
                        'posted' => 'bg-green-100 text-green-700',
                        'void'   => 'bg-red-100 text-red-700',
                        default  => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('finance.cash-bank.disbursements.show', $cd->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $cd->number }}</td>
                    <td class="px-3 py-2">{{ $cd->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $cd->payee ?: '-' }}</td>
                    <td class="px-3 py-2">{{ $cd->cashAccount->code ?? '' }} {{ $cd->cashAccount->name ?? '' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($cd->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $cd->status }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @unless($cd->isVoid())
                                <a href="{{ route('finance.cash-bank.disbursements.print', $cd->id) }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Print</a>
                            @endunless
                            @if($cd->isDraft())
                                <form method="POST" action="{{ route('finance.cash-bank.disbursements.post', $cd->id) }}" class="inline"
                                      onsubmit="return confirm('Post pengeluaran {{ $cd->number }}?')">
                                    @csrf
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <a href="{{ route('finance.cash-bank.disbursements.edit', $cd->id) }}"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                                <form method="POST" action="{{ route('finance.cash-bank.disbursements.destroy', $cd->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus draft {{ $cd->number }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($cd->isPosted() && $cd->canBeVoided())
                                <form method="POST" action="{{ route('finance.cash-bank.disbursements.void', $cd->id) }}" class="inline"
                                      onsubmit="return confirm('Void pengeluaran {{ $cd->number }}? Jurnal di-void.')">
                                    @csrf
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada pengeluaran umum.</td></tr>
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
