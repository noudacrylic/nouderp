@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Retur Pembelian</h1>
    <a href="{{ route('purchasing.returns.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Retur</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari No Retur / Invoice / PO / supplier...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft','posted','void'] as $s)
                <option value="{{ $s }}" @selected(request('status')==$s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="return_type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="invoice" @selected(request('return_type')=='invoice')>Invoice</option>
            <option value="po" @selected(request('return_type')=='po')>Refund DP (PO)</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Retur</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Supplier</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Referensi</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-56">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($returns as $r)
                @php
                    $isDraft = $r->status === 'draft';
                    $isPosted = $r->status === 'posted';
                    $isPo = $r->return_type === 'po';
                    $rowHref = $isDraft
                        ? route('purchasing.returns.edit', $r->id)
                        : route('purchasing.returns.show', $r->id);

                    $refLabel = $isPo
                        ? ($r->purchaseOrder->po_number ?? '-')
                        : ($r->purchaseInvoice->invoice_number ?? '-');
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium">
                        {{ $r->return_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $r->return_number])
                    </td>
                    <td class="px-3 py-2">{{ $r->return_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $r->supplier->name ?? '-' }}</td>
                    <td class="px-3 py-2">
                        @if($isPo)
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-700 uppercase">Refund DP</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700 uppercase">Invoice</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 font-mono text-xs">
                        {{ $refLabel }}
                        @if($refLabel && $refLabel !== '-')
                            @include('erp.purchasing._partials.copy-btn', ['value' => $refLabel])
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($r->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $cls = match($r->status) {
                                'posted' => 'bg-green-100 text-green-700',
                                'void' => 'bg-red-100 text-red-700',
                                default => 'bg-yellow-100 text-yellow-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $cls }}">{{ $r->status }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($isDraft)
                                <form method="POST" action="{{ route('purchasing.returns.post', $r->id) }}"
                                      onsubmit="return confirm('POST retur {{ $r->return_number }}? Jurnal & saldo akan diperbarui.')">
                                    @csrf
                                    <button class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('purchasing.returns.destroy', $r->id) }}"
                                      onsubmit="return confirm('Hapus draft retur {{ $r->return_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif

                            @if($r->canBeVoided())
                                <form method="POST" action="{{ route('purchasing.returns.void', $r->id) }}"
                                      onsubmit="return confirm('VOID retur {{ $r->return_number }}? Jurnal di-void & saldo dikembalikan.')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada retur.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $returns->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
