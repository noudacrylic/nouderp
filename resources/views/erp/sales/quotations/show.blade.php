@extends('erp.layouts.transaction')

@php
    $title = 'Quotation ' . $quotation->quotation_number;
    $status = $quotation->status;

    $editUrl = $status == 'draft' ? route('sales.quotations.edit', $quotation->id) : null;

    $createNewUrl = route('sales.quotations.create');
    $orderUrl = null;
    $cancelUrl = null;

    if ($status != 'cancelled') {
        $cancelUrl = route('sales.quotations.cancel', $quotation->id);
    }

    $discountItem = 0; // Diskon item sudah masuk ke subtotal per baris
    $subtotal = $quotation->items->sum('line_total');
    $dpp = $subtotal - ($quotation->discount_global ?? 0);
    $ppn = $dpp * (($quotation->ppn_percent ?? 0) / 100);
@endphp

@section('transaction-content')
    @push('transaction-header')
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">
                Quotation {{ $quotation->quotation_number }}
            </h2>

            <div class="text-gray-500 mb-4 text-sm">
                Status : <span class="font-bold text-gray-800">{{ strtoupper($quotation->status) }}</span>
            </div>

            <div class="flex justify-between items-center">
                <div class="flex gap-2">
                    <a href="{{ route('sales.quotations.index') }}"
                        class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300 transition shadow-sm text-sm font-bold flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
                        Daftar
                    </a>

                    @include('erp.sales.quotations.partials.actions', ['quotation' => $quotation, 'showText' => true])

                    @if($quotation->status == 'converted')
                        @php
                            $so_id = $quotation->sales_order_id ?? ($quotation->salesOrder->id ?? null);
                        @endphp
                        @if($so_id)
                            <a href="{{ route('sales.orders.show', $so_id) }}"
                                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 transition shadow-sm font-bold text-sm flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                Lihat SO
                            </a>
                        @endif
                    @endif
                </div>

                <div>
                    <a href="{{ route('sales.quotations.create') }}"
                        class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-900 transition font-bold shadow-sm text-sm flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        + Buat Baru
                    </a>
                </div>
            </div>
        </div>
    @endpush

    <div class="border rounded-lg overflow-hidden shadow-sm mb-6">
        <table class="w-full text-left border-collapse bg-white">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider" style="width:150px;">SKU</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama Produk</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Qty</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Unit</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Price</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Discount</th>
                    <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($quotation->items as $item)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 text-xs font-mono font-bold text-blue-600">
                        {{ $item->product->sku ?? '-' }}
                    </td>

                    <td class="px-4 py-3">
                        <div class="font-semibold text-gray-800 text-sm">
                            {{ $item->description ?: ($item->product->name ?? '-') }}
                        </div>
                    </td>

                    <td class="px-4 py-3 text-right font-medium text-sm">
                        {{ number_format($item->qty, 2) }}
                    </td>

                    <td class="px-4 py-3 text-gray-500 italic text-xs">
                        {{ $item->unit_name ?? 'pcs' }}
                    </td>

                    <td class="px-4 py-3 text-right text-sm">
                        {{ number_format($item->unit_price, 0) }}
                    </td>

                    <td class="px-4 py-3 text-right text-red-500 text-sm">
                        {{ number_format($item->line_discount ?? 0, 0) }}
                    </td>

                    <td class="px-4 py-3 text-right font-bold text-gray-900 text-sm">
                        {{ number_format($item->line_total, 0) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

                @include('erp.components.transaction-summary', [
                    'notes' => $quotation->notes ?? '-',
                    'updateNotesUrl' => route('sales.quotations.update-notes', $quotation->id),
                    'subtotal' => $subtotal,
                    'discountItem' => $discountItem,
                    'discountGlobal' => $quotation->discount_global ?? 0,
                    'dpp' => $dpp,
                    'ppn' => $ppn,
                    'shipping' => $quotation->shipping_charge ?? 0,
                    'expense' => ($quotation->service_charge ?? 0) + ($quotation->other_expense ?? 0),
                    'grandTotal' => $quotation->grand_total
                ])

@include('erp._partials.print-shortcut', ['printUrl' => route('sales.quotations.print', $quotation->id)])

@endsection