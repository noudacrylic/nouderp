@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Faktur Pembelian</h1>
    <a href="{{ route('purchasing.invoices.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Faktur</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari No Faktur / No pemasok / pemasok...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $statusOptions = [
                    'draft'            => 'Draf',
                    'unpaid'           => 'Belum Lunas',
                    'paid'             => 'Lunas',
                    'returned_partial' => 'Retur Sebagian',
                    'returned_full'    => 'Retur',
                    'void'             => 'Void',
                ];
            @endphp
            @foreach($statusOptions as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Faktur</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pemasok</th>
                <th class="px-3 py-2 text-left">Gudang</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-80">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $inv)
                @php
                    $isDraft = $inv->status === 'draft';
                    $isPosted = $inv->status === 'posted';
                    $isVoid = $inv->status === 'void';
                    $hasOutstanding = (float) $inv->outstanding_amount > 0;
                    $isPartial = $inv->purchase_order_id && (int) ($inv->po_sibling_count ?? 0) > 1;
                    $qtyTotal = (float) ($inv->items_qty_total ?? 0);
                    $qtyReturned = (float) ($inv->items_returned_total ?? 0);
                    $isFullyReturned = $isPosted && $qtyTotal > 0 && $qtyReturned >= $qtyTotal;
                    $isPartiallyReturned = $isPosted && $qtyReturned > 0 && $qtyReturned < $qtyTotal;
                    $rowHref = $isDraft
                        ? route('purchasing.invoices.edit', $inv->id)
                        : route('purchasing.invoices.show', $inv->id);

                    if ($isVoid) {
                        $stCls = 'bg-red-100 text-red-700';
                        $stLabel = 'VOID';
                    } elseif ($isDraft) {
                        $stCls = 'bg-yellow-100 text-yellow-700';
                        $stLabel = 'DRAFT';
                    } elseif ($isFullyReturned) {
                        $stCls = 'bg-rose-100 text-rose-700';
                        $stLabel = 'RETUR';
                    } elseif ($hasOutstanding) {
                        $stCls = 'bg-orange-100 text-orange-700';
                        $stLabel = 'BELUM LUNAS';
                    } else {
                        $stCls = 'bg-green-100 text-green-700';
                        $stLabel = 'LUNAS';
                    }
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $inv->invoice_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $inv->invoice_number])
                        @if($isPartial)
                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-700 uppercase" title="Faktur sebagian dari PO yang dipecah jadi beberapa faktur">Sebagian</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $inv->invoice_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $inv->supplier->name }}</td>
                    <td class="px-3 py-2">{{ $inv->warehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($inv->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                        @if($isPartiallyReturned)
                            <div class="mt-0.5">
                                <span class="px-2 py-0.5 rounded text-xs uppercase bg-rose-100 text-rose-700">Retur Sebagian</span>
                            </div>
                        @endif
                        @if($stLabel === 'BELUM LUNAS')
                            <div class="text-xs text-red-600 font-semibold mt-0.5">{{ number_format($inv->outstanding_amount, 0, ',', '.') }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            <a href="{{ route('purchasing.invoices.print', $inv->id) }}"
                               class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Cetak Faktur">Cetak</a>

                            @if($isPosted)
                                <a href="{{ route('purchasing.returns.create', ['invoice_id' => $inv->id]) }}"
                                   class="bg-orange-600 text-white px-2 py-1 rounded text-xs" title="Buat retur">Retur</a>

                                @if($hasOutstanding)
                                    <a href="{{ route('purchasing.payments.create', ['supplier_id' => $inv->supplier_id]) }}"
                                       class="bg-indigo-600 text-white px-2 py-1 rounded text-xs" title="Buat pembayaran">Bayar</a>
                                @endif

                                @if($inv->canBeVoided())
                                    <form method="POST" action="{{ route('purchasing.invoices.void', $inv->id) }}"
                                          onsubmit="return confirm('VOID faktur {{ $inv->invoice_number }}? Stok dikembalikan & jurnal di-void.')">
                                        @csrf
                                        <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif
                            @endif

                            @if($isDraft)
                                <form method="POST" action="{{ route('purchasing.invoices.destroy', $inv->id) }}"
                                      onsubmit="return confirm('Hapus faktur draf {{ $inv->invoice_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada faktur.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $invoices->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
