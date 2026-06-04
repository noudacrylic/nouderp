@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Billing Customer</h1>
    <a href="{{ route('sales.billing.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Billing</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor billing, customer, atau dokumen...',
    ])
    @include('erp.purchasing._partials.date-range', [
        'fromName' => 'from',
        'toName'   => 'to',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jenis</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="invoice" @selected(request('type')=='invoice')>Pelunasan Invoice</option>
            <option value="sales_order" @selected(request('type')=='sales_order')>Uang Muka (SO)</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['unpaid' => 'Belum Dibayar', 'partial' => 'Sebagian', 'paid' => 'Lunas'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Billing</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Customer</th>
                <th class="px-3 py-2 text-left">Jenis</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-right">Terbayar</th>
                <th class="px-3 py-2 text-right">Sisa</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-40">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($billings as $billing)
                @php
                    $paid = (float) ($billing->paid_amount ?? 0);
                    $remaining = round((float)$billing->total_amount - $paid, 2);
                    $hasInvoiceOnSO = $billing->billing_type == 'sales_order'
                        && $billing->items->whereNotNull('invoice_id')->count() > 0;

                    if ($remaining <= 0) {
                        $stCls = 'bg-green-100 text-green-700';
                        $stLabel = 'Lunas';
                    } elseif ($paid > 0) {
                        $stCls = 'bg-orange-100 text-orange-700';
                        $stLabel = 'Sebagian';
                    } else {
                        $stCls = 'bg-blue-100 text-blue-700';
                        $stLabel = 'Belum Dibayar';
                    }

                    $billStatusVal = $billing->status instanceof \App\Enums\BillingStatusEnum ? $billing->status->value : $billing->status;
                    $isDraft = strtolower($billStatusVal ?? '') === 'draft';
                    $rowHref = $isDraft
                        ? route('sales.billing.edit', $billing->id)
                        : route('sales.billing.show', $billing->id);
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $billing->billing_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $billing->billing_number])
                    </td>
                    <td class="px-3 py-2">{{ $billing->date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $billing->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2">
                        @if($billing->billing_type == 'invoice')
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-blue-100 text-blue-700">Pelunasan</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-purple-100 text-purple-700">Uang Muka</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($billing->total_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-green-600">{{ number_format($paid, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-orange-600 font-semibold">{{ number_format($remaining, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($remaining > 0 && !($billing->billing_type == 'sales_order' && $hasInvoiceOnSO))
                                <a href="{{ route('sales.payment.create', ['billing_id' => $billing->id]) }}"
                                   class="bg-indigo-600 text-white px-2 py-1 rounded text-xs">Bayar</a>
                            @elseif($billing->billing_type == 'sales_order' && $hasInvoiceOnSO)
                                <span class="bg-gray-100 text-gray-400 px-2 py-1 rounded text-xs" title="DP sudah jadi Invoice">Locked</span>
                            @endif

                            @if($paid == 0)
                                <a href="{{ route('sales.billing.edit', $billing->id) }}"
                                   class="bg-amber-500 text-white px-2 py-1 rounded text-xs">Edit</a>
                                <form method="POST" action="{{ route('sales.billing.destroy', $billing->id) }}" onsubmit="return confirm('Hapus billing ini?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif

                            @if($billing->canBeVoided())
                                <form method="POST" action="{{ route('sales.billing.void', $billing->id) }}"
                                      onsubmit="return confirm('Void billing {{ $billing->billing_number }}?')">
                                    @csrf
                                    <button class="bg-gray-600 hover:bg-gray-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada billing.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($billings instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $billings->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
