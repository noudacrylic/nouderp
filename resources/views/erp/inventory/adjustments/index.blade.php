@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Penyesuaian Stock</h1>
    <a href="{{ route('inventory.adjustments.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Penyesuaian</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['name' => 'search', 'placeholder' => 'Cari No / Title / Produk...'])
    @include('erp.purchasing._partials.date-range')

    <div>
        <label class="block text-xs text-gray-500 mb-1">Warehouse</label>
        <select name="warehouse" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($warehouses as $w)
                <option value="{{ $w->id }}" @selected(request('warehouse') == $w->id)>{{ $w->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status') == 'draft')>Draft</option>
            <option value="posted" @selected(request('status') == 'posted')>Posted</option>
            <option value="void" @selected(request('status') == 'void')>Void</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Penyesuaian</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Title / Catatan</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Warehouse</th>
                <th class="px-3 py-2 text-left">PIC</th>
                <th class="px-3 py-2 text-right">Items</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center w-56">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($adjustments as $adj)
                @php
                    [$statusLabel, $statusCls] = match($adj->status) {
                        'posted' => ['Posted', 'bg-green-100 text-green-700'],
                        'void'   => ['Void',   'bg-gray-200 text-gray-600'],
                        default  => ['Draft',  'bg-yellow-100 text-yellow-700'],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('inventory.adjustments.edit', $adj->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $adj->number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $adj->number])
                    </td>
                    <td class="px-3 py-2">{{ $adj->date }}</td>
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $adj->title ?? '-' }}</div>
                        <div class="text-xs text-gray-400">{{ Str::limit($adj->notes, 30) }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-600">{{ $adj->type }}</span>
                    </td>
                    <td class="px-3 py-2">{{ $adj->warehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-gray-600">{{ $adj->responsible ?? '-' }}</td>
                    <td class="px-3 py-2 text-right font-medium">{{ $adj->items->count() }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            @if($adj->status == 'draft')
                                <form method="POST" action="{{ route('inventory.adjustments.post', $adj->id) }}"
                                      onsubmit="return confirm('Post penyesuaian ini?')">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>

                                <form method="POST" action="{{ route('inventory.adjustments.destroy', $adj->id) }}"
                                      onsubmit="return confirm('Hapus draft ini?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($adj->status == 'posted')
                                <form method="POST" action="{{ route('inventory.adjustments.void', $adj->id) }}"
                                      onsubmit="return confirm('Void penyesuaian? Stock & journal akan dibalik.')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>

                                @if($adj->items->first())
                                    <a href="{{ route('inventory.reports.stock-card', $adj->items->first()->product_id) }}"
                                       class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Ledger</a>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada penyesuaian stok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $adjustments->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
