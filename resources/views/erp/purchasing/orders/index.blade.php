@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Purchase Order</h1>
    <a href="{{ route('purchasing.orders.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah PO</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari No PO atau supplier...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $statusOptions = [
                    'draft'       => 'Draft',
                    'posted'      => 'Posted',
                    'closed'      => 'Closed',
                    'cancelled'   => 'Cancelled',
                    'returned_dp' => 'Retur DP',
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
                <th class="px-3 py-2 text-left">No PO</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Supplier</th>
                <th class="px-3 py-2 text-left">Gudang</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $po)
                @php
                    $isLocked = in_array($po->status, ['cancelled', 'closed']);
                    $hasInvoice = ($po->active_invoices_count ?? 0) > 0;
                    $totalQty = (float) ($po->total_qty ?? 0);
                    $invoicedQty = (float) ($po->invoiced_qty ?? 0);
                    $isFull = $hasInvoice && $totalQty > 0 && $invoicedQty >= $totalQty;
                    $isPartial = $hasInvoice && !$isFull;
                    $dpAmount = (float) ($po->dp_amount ?? 0);
                    $hasDp = $dpAmount > 0;
                    $dpLunas = $dpAmount + 0.01 >= (float) $po->total;
                    $hasPoReturn = ((int) ($po->po_return_count ?? 0)) > 0;

                    $rowHref = (!$isLocked && !$hasInvoice && !$hasPoReturn)
                        ? route('purchasing.orders.edit', $po->id)
                        : route('purchasing.orders.show', $po->id);

                    [$statusLabel, $statusCls] = match(true) {
                        $isLocked    => [ucfirst($po->status), 'bg-gray-200 text-gray-600'],
                        $hasPoReturn => ['Retur DP', 'bg-rose-100 text-rose-700'],
                        $isFull      => ['Full Invoiced', 'bg-green-100 text-green-700'],
                        $isPartial   => ['Partial Invoiced', 'bg-yellow-100 text-yellow-700'],
                        $hasDp       => ['DP — ' . number_format($dpAmount, 0, ',', '.'), 'bg-purple-100 text-purple-700'],
                        default      => ['Draft', 'bg-gray-100 text-gray-500'],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium">
                        {{ $po->po_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $po->po_number])
                    </td>
                    <td class="px-3 py-2">{{ $po->po_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $po->supplier->name }}</td>
                    <td class="px-3 py-2">{{ $po->warehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($po->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            <a href="{{ route('purchasing.orders.print', $po->id) }}"
                               class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Print PO">Print</a>

                            @if(!$isLocked && !$isFull && !$hasPoReturn)
                                <a href="{{ route('purchasing.invoices.create', ['po_id' => $po->id]) }}"
                                   class="bg-indigo-600 text-white px-2 py-1 rounded text-xs">+ Invoice</a>
                            @endif

                            @if(!$isLocked && !$hasInvoice && !$dpLunas && !$hasPoReturn)
                                <a href="{{ route('purchasing.payments.create', ['supplier_id' => $po->supplier_id, 'po_id' => $po->id]) }}"
                                   class="bg-purple-600 text-white px-2 py-1 rounded text-xs" title="Buat uang muka untuk PO ini">DP</a>
                            @endif

                            @if(!$isLocked && !$hasInvoice && !$hasDp)
                                <form method="POST" action="{{ route('purchasing.orders.destroy', $po->id) }}"
                                      onsubmit="return confirm('Hapus PO {{ $po->po_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada PO.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $orders->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
