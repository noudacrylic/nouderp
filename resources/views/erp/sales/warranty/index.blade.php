@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Garansi (Warranty)</h1>
    <a href="{{ route('sales.warranty.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Garansi</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor garansi, customer, atau invoice...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft' => 'Draft', 'received' => 'Diterima', 'repaired' => 'Diperbaiki', 'shipped' => 'Dikirim'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jenis</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="repair" @selected(request('type')=='repair')>Perbaikan</option>
            <option value="replacement" @selected(request('type')=='replacement')>Kirim Baru</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Garansi</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Customer</th>
                <th class="px-3 py-2 text-left">Invoice</th>
                <th class="px-3 py-2 text-center">Jenis</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-32">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($warranties as $w)
                @php
                    $isDraft = $w->status === 'draft';
                    $rowHref = $isDraft
                        ? route('sales.warranty.edit', $w->id)
                        : route('sales.warranty.show', $w->id);

                    [$stCls, $stLabel] = match($w->status ?? '') {
                        'draft'    => ['bg-yellow-100 text-yellow-700', 'Draft'],
                        'received' => ['bg-blue-100 text-blue-700', 'Diterima'],
                        'repaired' => ['bg-indigo-100 text-indigo-700', 'Diperbaiki'],
                        'shipped'  => ['bg-green-100 text-green-700', 'Dikirim'],
                        default    => ['bg-gray-100 text-gray-500', strtoupper($w->status ?? '-')],
                    };

                    $isReplacement = ($w->type ?? '') === 'replacement';
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $w->warranty_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $w->warranty_number])
                    </td>
                    <td class="px-3 py-2">{{ $w->warranty_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $w->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $w->invoice->invoice_number ?? '-' }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @if($isReplacement)
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-purple-100 text-purple-700">Kirim Baru</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-orange-100 text-orange-700">Perbaikan</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.warranty.destroy', $w->id) }}" onsubmit="return confirm('Hapus draft garansi ini?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                            @if($w->canBeVoided())
                                <form method="POST" action="{{ route('sales.warranty.void', $w->id) }}"
                                      onsubmit="return confirm('Void garansi {{ $w->warranty_number }}?')">
                                    @csrf
                                    <button class="bg-gray-600 hover:bg-gray-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                            @if(!$isReplacement && in_array($w->status, ['received', 'repaired', 'posted'], true))
                                <a href="{{ route('production.orders.create', [
                                        'type'               => 'repair',
                                        'repair_source_type' => 'warranty',
                                        'repair_source_id'   => $w->id,
                                        'repair_ref'         => $w->warranty_number,
                                    ]) }}"
                                   class="bg-orange-500 hover:bg-orange-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap"
                                   title="Buat Order Produksi perbaikan dari garansi ini">+ OP Perbaikan</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada garansi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($warranties instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $warranties->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
