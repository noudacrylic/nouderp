@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Retur Customer</h1>
    <a href="{{ route('sales.returns.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Retur</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor retur, invoice, atau customer...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft' => 'Draft', 'posted' => 'Posted', 'void' => 'Void'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Retur</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Customer</th>
                <th class="px-3 py-2 text-left">Referensi</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($returns as $return)
                @php
                    $isDraft = $return->status === 'draft';
                    $isPosted = $return->status === 'posted';
                    $isVoid = $return->status === 'void';
                    $rowHref = $isDraft
                        ? route('sales.returns.edit', $return->id)
                        : route('sales.returns.show', $return->id);
                    $refNumber = $return->invoice->invoice_number ?? $return->salesOrder->order_number ?? '-';

                    [$stCls, $stLabel] = match(true) {
                        $isPosted => ['bg-green-100 text-green-700', 'Posted'],
                        $isDraft  => ['bg-yellow-100 text-yellow-700', 'Draft'],
                        $isVoid   => ['bg-red-100 text-red-700', 'Void'],
                        default   => ['bg-gray-100 text-gray-500', strtoupper($return->status ?? '-')],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $return->return_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $return->return_number])
                    </td>
                    <td class="px-3 py-2">{{ $return->return_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $return->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $refNumber }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($return->grand_total ?? 0, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.returns.post', $return->id) }}" onsubmit="return confirm('Post retur ini?')">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">POST</button>
                                </form>
                                <form method="POST" action="{{ route('sales.returns.destroy', $return->id) }}" onsubmit="return confirm('Hapus draft retur ini?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif

                            @if($return->canBeVoided())
                                <form method="POST" action="{{ route('sales.returns.void', $return->id) }}" onsubmit="return confirm('Void retur {{ $return->return_number }}?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif

                            @if($isPosted && $return->repair_items_count > 0)
                                <a href="{{ route('production.orders.create', [
                                        'type'               => 'repair',
                                        'repair_source_type' => 'return',
                                        'repair_source_id'   => $return->id,
                                        'repair_ref'         => $return->return_number,
                                    ]) }}"
                                   class="bg-orange-500 hover:bg-orange-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap"
                                   title="Buat Order Produksi perbaikan dari retur ini">+ OP Perbaikan</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada retur.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($returns instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $returns->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
