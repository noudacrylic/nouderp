@extends('layouts.erp')

@section('content')
@php
    $status = strtolower($payment->status ?? '');
    [$stCls, $stLabel] = match($status) {
        'posted' => ['bg-green-100 text-green-700', 'Diposting'],
        'draft'  => ['bg-yellow-100 text-yellow-700', 'Draf'],
        'void'   => ['bg-red-100 text-red-700', 'Void'],
        default  => ['bg-gray-100 text-gray-500', strtoupper($payment->status ?? '-')],
    };
    $isInvoicePay = ($payment->payment_type ?? '') === 'invoice';
    $netCash = (float) ($payment->amount ?? 0) - (float) ($payment->admin_fee ?? 0);
@endphp

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
        <h1 class="text-lg font-semibold">Pembayaran Pelanggan</h1>
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
        @if($isInvoicePay)
            <span class="px-2 py-0.5 rounded text-xs uppercase bg-blue-100 text-blue-700">Pelunasan</span>
        @else
            <span class="px-2 py-0.5 rounded text-xs uppercase bg-purple-100 text-purple-700">Uang Muka</span>
        @endif
    </div>
    <div class="flex items-center gap-2">
        @if($payment->canBeVoided())
            <form action="{{ route('sales.payment.void', $payment->id) }}" method="POST" onsubmit="return confirm('Void pembayaran ini? Alokasi akan dibalik & jurnal dibatalkan.')">
                @csrf
                <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route('sales.payment.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
    </div>
</div>


<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    {{-- LEFT: Info pembayaran --}}
    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Informasi Pembayaran</h2>
        <dl class="space-y-2">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">No. Payment</dt>
                <dd class="font-semibold text-right">
                    {{ $payment->payment_number }}
                    @include('erp.purchasing._partials.copy-btn', ['value' => $payment->payment_number])
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Tanggal</dt>
                <dd class="text-right">{{ $payment->date ? $payment->date->format('d M Y') : '-' }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Pelanggan</dt>
                <dd class="text-right">{{ $payment->customer->name ?? '-' }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Akun Kas</dt>
                <dd class="text-right">{{ $payment->cashAccount->name ?? '-' }}</dd>
            </div>
            @if($payment->feeAccount)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Akun Biaya Admin</dt>
                    <dd class="text-right">{{ $payment->feeAccount->name }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- RIGHT: Nominal --}}
    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Nominal</h2>
        <dl class="space-y-2">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Jumlah Bayar</dt>
                <dd class="font-semibold text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</dd>
            </div>
            @if(($payment->admin_fee ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Biaya Admin</dt>
                    <dd class="text-right text-red-600">- Rp {{ number_format($payment->admin_fee, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-t pt-2">
                    <dt class="text-gray-500 font-semibold">Kas Masuk Bersih</dt>
                    <dd class="font-bold text-right text-green-700">Rp {{ number_format($netCash, 0, ',', '.') }}</dd>
                </div>
            @endif
            @if(($payment->used_balance ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Saldo Digunakan</dt>
                    <dd class="text-right text-blue-600">Rp {{ number_format($payment->used_balance, 0, ',', '.') }}</dd>
                </div>
            @endif
            @if(($payment->overpay ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Lebih Bayar (Overpay)</dt>
                    <dd class="text-right text-purple-700">Rp {{ number_format($payment->overpay, 0, ',', '.') }}</dd>
                </div>
            @endif
        </dl>
    </div>
</div>

{{-- Tabel Allocations --}}
<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <div class="px-4 py-3 border-b">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Alokasi Pembayaran</h2>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Referensi</th>
                <th class="px-3 py-2 text-right w-40">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payment->allocations as $alloc)
                @php
                    if ($alloc->invoice) {
                        $type = 'Faktur';
                        $typeCls = 'bg-blue-100 text-blue-700';
                        $ref = $alloc->invoice->invoice_number;
                        $refUrl = route('sales.invoices.show', $alloc->invoice->id);
                    } elseif ($alloc->billing) {
                        $type = 'Billing';
                        $typeCls = 'bg-purple-100 text-purple-700';
                        $ref = $alloc->billing->billing_number ?? ('Billing #' . $alloc->billing->id);
                        $refUrl = route('sales.billing.show', $alloc->billing->id);
                    } elseif ($alloc->salesOrder) {
                        $type = 'Uang Muka SO';
                        $typeCls = 'bg-amber-100 text-amber-700';
                        $ref = $alloc->salesOrder->order_number;
                        $refUrl = route('sales.orders.show', $alloc->salesOrder->id);
                    } else {
                        $type = '-';
                        $typeCls = 'bg-gray-100 text-gray-500';
                        $ref = '-';
                        $refUrl = null;
                    }
                @endphp
                <tr class="border-b">
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $typeCls }}">{{ $type }}</span></td>
                    <td class="px-3 py-2">
                        @if($refUrl)
                            <a href="{{ $refUrl }}" class="text-blue-600 hover:underline">{{ $ref }}</a>
                        @else
                            {{ $ref }}
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($alloc->amount, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">Belum ada alokasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($payment->notes)
    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Catatan</h2>
        <p class="whitespace-pre-line text-gray-700">{{ $payment->notes }}</p>
    </div>
@endif

@include('erp.sales._partials.relations-payment')

@endsection
