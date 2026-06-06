@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pembayaran Pelanggan</h1>
    <div class="flex gap-2">
        <a href="{{ route('pos.fulfillment.belum-siap') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm" title="Kembali ke Pemrosesan Pesanan (POS)">← Pemrosesan Pesanan</a>
        <a href="{{ route('sales.payment.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Pembayaran</a>
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari No Payment atau pelanggan...',
    ])
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
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Payment</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-left">Akun Kas</th>
                <th class="px-3 py-2 text-left">Jenis</th>
                <th class="px-3 py-2 text-right">Nominal</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-48">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $payment)
                @php
                    $isDraft = $payment->status === 'draft';
                    $isPosted = $payment->status === 'posted';
                    $isInvoicePay = ($payment->payment_type ?? '') === 'invoice';
                    $rowHref = route('sales.payment.show', $payment->id);

                    // Sumber referensi
                    $refLabel = '';
                    if ($isInvoicePay) {
                        $invAllocs = $payment->allocations->filter(fn($a) => $a->invoice_id);
                        $billAllocs = $payment->allocations->filter(fn($a) => $a->billing_id);

                        if ($invAllocs->count() > 0) {
                            $invNos = $invAllocs->map(fn($a) => $a->invoice->invoice_number ?? '?')->values();
                            $refLabel = $invNos->first();
                            if ($invNos->count() > 1) $refLabel .= ' +' . ($invNos->count() - 1);
                        } elseif ($billAllocs->count() > 0) {
                            $billNos = $billAllocs->map(fn($a) => $a->billing->billing_number ?? ('Billing #' . $a->billing_id))->values();
                            $refLabel = $billNos->first();
                            if ($billNos->count() > 1) $refLabel .= ' +' . ($billNos->count() - 1);
                        }
                    } else {
                        // Uang Muka — ambil dari sales_order_id langsung atau dari allocations
                        $soNumber = $payment->salesOrder->order_number ?? null;
                        if (!$soNumber) {
                            $soAllocs = $payment->allocations->filter(fn($a) => $a->sales_order_id);
                            if ($soAllocs->count() > 0) {
                                $soNumbers = $soAllocs->map(fn($a) => $a->salesOrder->order_number ?? '?')->values();
                                $soNumber = $soNumbers->first();
                                if ($soNumbers->count() > 1) $soNumber .= ' +' . ($soNumbers->count() - 1);
                            }
                        }
                        $refLabel = $soNumber ?: '';
                    }

                    [$stCls, $stLabel] = match(strtolower($payment->status ?? '')) {
                        'posted' => ['bg-green-100 text-green-700', 'Diposting'],
                        'draft'  => ['bg-yellow-100 text-yellow-700', 'Draf'],
                        'void'   => ['bg-red-100 text-red-700', 'Void'],
                        default  => ['bg-gray-100 text-gray-500', strtoupper($payment->status ?? '-')],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $payment->payment_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $payment->payment_number])
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $payment->date ? $payment->date->format('d M Y') : '-' }}</td>
                    <td class="px-3 py-2">{{ $payment->customer->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $payment->cashAccount->name ?? '-' }}</td>
                    <td class="px-3 py-2">
                        @if($isInvoicePay)
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700 uppercase">Pelunasan</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-700 uppercase">Uang Muka</span>
                        @endif
                        @if($refLabel)
                            <div class="text-[10px] text-gray-500 mt-0.5 font-mono">{{ $refLabel }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        {{ number_format($payment->amount, 0, ',', '.') }}
                        @if(($payment->admin_fee ?? 0) > 0)
                            <div class="text-[10px] text-red-500">Fee: -{{ number_format($payment->admin_fee, 0, ',', '.') }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.payment.post', $payment->id) }}"
                                      onsubmit="return confirm('Post pembayaran {{ $payment->payment_number }}?')">
                                    @csrf
                                    <button class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('sales.payment.destroy', $payment->id) }}"
                                      onsubmit="return confirm('Hapus pembayaran draf {{ $payment->payment_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                                <a href="{{ route('sales.payment.print', $payment->id) }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            @elseif($isPosted)
                                @if($payment->canBeVoided())
                                    <form method="POST" action="{{ route('sales.payment.void', $payment->id) }}"
                                          onsubmit="return confirm('Void pembayaran {{ $payment->payment_number }}? Pembayaran dibatalkan & jurnal di-void.')">
                                        @csrf
                                        <button class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif
                                <a href="{{ route('sales.payment.print', $payment->id) }}"
                                   class="bg-gray-700 hover:bg-gray-800 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            @else
                                <span class="text-gray-300 text-xs">-</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada data pembayaran.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($payments instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $payments->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
