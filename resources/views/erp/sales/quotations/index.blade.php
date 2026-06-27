@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Penawaran (Quotation)</h1>
    <a href="{{ route('sales.quotations.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Penawaran</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor penawaran atau pelanggan...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Belum Diproses</label>
        <select name="age" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="lt_1w" @selected(request('age')=='lt_1w')>&lt; 1 minggu</option>
            <option value="1w_1m" @selected(request('age')=='1w_1m')>1 minggu – 1 bulan</option>
            <option value="1m_3m" @selected(request('age')=='1m_3m')>1 bulan – 3 bulan</option>
            <option value="gt_3m" @selected(request('age')=='gt_3m')>&gt; 3 bulan</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Penawaran</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-48">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($quotations as $q)
                @php
                    $isDraft = strtolower($q->status ?? '') === 'draft';
                    $rowHref = $isDraft
                        ? route('sales.quotations.edit', $q->id)
                        : route('sales.quotations.show', $q->id);
                    [$stCls, $stLabel] = match(strtolower($q->status ?? '')) {
                        'draft'     => ['bg-yellow-100 text-yellow-700', 'Draf'],
                        'confirmed' => ['bg-blue-100 text-blue-700', 'Confirmed'],
                        'cancelled' => ['bg-gray-200 text-gray-600', 'Cancelled'],
                        default     => ['bg-gray-100 text-gray-500', strtoupper($q->status ?? '-')],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $q->quotation_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $q->quotation_number])
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($q->quotation_date)->format('d M Y') }}
                        @include('erp._partials.age-badge', ['date' => $q->quotation_date, 'show' => !in_array(strtolower($q->status ?? ''), ['converted', 'cancelled'], true)])
                    </td>
                    <td class="px-3 py-2">{{ $q->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($q->grand_total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            <a href="{{ route('sales.quotations.print', $q->id) }}"
                               class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Cetak">Cetak</a>

                            @if($isDraft)
                                <a href="{{ route('sales.orders.create', ['quotation_id' => $q->id]) }}"
                                   class="bg-green-600 text-white px-2 py-1 rounded text-xs" title="Buat Sales Order dari penawaran ini">+ SO</a>
                                <form method="POST" action="{{ route('sales.quotations.destroy', $q->id) }}"
                                      onsubmit="return confirm('Hapus penawaran {{ $q->quotation_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada penawaran.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($quotations instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $quotations->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
