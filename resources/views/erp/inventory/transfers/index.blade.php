@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Transfer Stok</h1>
    <a href="{{ route('inventory.transfers.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Transfer</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['name' => 'search', 'placeholder' => 'Cari No / Produk / SKU...'])
    @include('erp.purchasing._partials.date-range')

    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="draft" @selected(request('status') == 'draft')>Draf</option>
            <option value="posted" @selected(request('status') == 'posted')>Diposting</option>
            <option value="void" @selected(request('status') == 'void')>Void</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Transfer</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Divisi</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $transfer)
                @php
                    $rowHref = $transfer->status === 'draft'
                        ? route('inventory.transfers.edit', $transfer->id)
                        : route('inventory.transfers.show', $transfer->id);

                    [$statusLabel, $statusCls] = match($transfer->status) {
                        'posted' => ['Diposting', 'bg-green-100 text-green-700'],
                        'void'   => ['Void',   'bg-red-100 text-red-700'],
                        default  => ['Draf',  'bg-gray-100 text-gray-500'],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $transfer->number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $transfer->number])
                    </td>
                    <td class="px-3 py-2">{{ $transfer->date }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        @if($transfer->creator)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                                {{ $transfer->creator->name }}
                            </span>
                        @else
                            <span class="text-gray-400 text-xs italic">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            <a href="{{ route('inventory.transfers.show', $transfer->id) }}"
                               class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Lihat</a>

                            @if ($transfer->status == 'draft')
                                <form method="POST" action="{{ route('inventory.transfers.post', $transfer->id) }}"
                                      onsubmit="return confirm('Post transfer?')">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>

                                <form method="POST" action="{{ route('inventory.transfers.destroy', $transfer->id) }}"
                                      onsubmit="return confirm('Hapus transfer?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif

                            @if ($transfer->status == 'posted')
                                <form method="POST" action="{{ route('inventory.transfers.void', $transfer->id) }}"
                                      onsubmit="return confirm('Void transfer?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada transfer.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $transfers->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
