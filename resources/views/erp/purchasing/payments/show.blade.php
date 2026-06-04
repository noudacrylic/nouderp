@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Payment — {{ $payment->payment_number }}</h1>
        @php
            $cls = match($payment->status) {
                'posted' => 'bg-green-100 text-green-700',
                'void' => 'bg-red-100 text-red-700',
                default => 'bg-yellow-100 text-yellow-700',
            };
        @endphp
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $cls }}">{{ $payment->status }}</span>
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2">
        @if($payment->status === 'draft')
            <a href="{{ route('purchasing.payments.edit', $payment->id) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</a>
            <form method="POST" action="{{ route('purchasing.payments.post', $payment->id) }}" onsubmit="return confirm('POST payment ini? Jurnal akan terbuat & invoice yang dialokasi akan di-update.')">
                @csrf
                <button class="bg-green-600 text-white px-3 py-2 rounded text-sm">POST</button>
            </form>
            <form method="POST" action="{{ route('purchasing.payments.destroy', $payment->id) }}" onsubmit="return confirm('Hapus payment draft ini?')">
                @csrf @method('DELETE')
                <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Hapus</button>
            </form>
        @elseif($payment->status === 'posted')
            @if($payment->canBeVoided())
                <form method="POST" action="{{ route('purchasing.payments.void', $payment->id) }}" onsubmit="return confirm('VOID payment ini? Jurnal di-void & invoice outstanding akan dikembalikan.')">
                    @csrf
                    <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Void</button>
                </form>
            @endif
        @endif
        <a href="{{ route('purchasing.payments.index') }}" class="px-3 py-2 border rounded text-sm">Kembali</a>
    </div>
</div>


<div class="grid grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Detail</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Supplier</div><div>{{ $payment->supplier->name }}</div>
            <div class="text-gray-500">Tanggal</div><div>{{ $payment->payment_date->format('d M Y') }}</div>
            <div class="text-gray-500">Method</div><div>{{ ucfirst($payment->payment_method) }}</div>
            <div class="text-gray-500">Akun Kas/Bank</div><div>{{ $payment->bankAccount->code ?? '' }} — {{ $payment->bankAccount->name ?? '' }}</div>
        </div>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Ringkasan</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Nominal (Cash)</div><div class="text-right font-semibold">{{ number_format($payment->amount, 0, ',', '.') }}</div>
            @if((float) ($payment->used_balance ?? 0) > 0)
                <div class="text-gray-500">Saldo Dipakai</div>
                <div class="text-right text-purple-700">{{ number_format($payment->used_balance, 0, ',', '.') }}</div>
            @endif
            <div class="text-gray-500">Teralokasi (Pelunasan)</div><div class="text-right">{{ number_format($payment->allocated_amount, 0, ',', '.') }}</div>
            <div class="text-gray-500">Sisa → DP (1107)</div>
            <div class="text-right text-blue-600 font-semibold">
                {{ number_format($payment->remaining_amount, 0, ',', '.') }}
            </div>
            @if((float) ($payment->overpay ?? 0) > 0)
                <div class="text-gray-500">Kelebihan → Piutang (1108)</div>
                <div class="text-right text-pink-600 font-semibold">
                    {{ number_format($payment->overpay, 0, ',', '.') }}
                </div>
            @endif
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <h3 class="font-semibold mb-3">Alokasi ({{ $payment->allocations->count() }})</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Invoice</th>
                <th class="px-3 py-2 text-left">Tgl Invoice</th>
                <th class="px-3 py-2 text-right w-32">Total Invoice</th>
                <th class="px-3 py-2 text-right w-32">Bayar</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payment->allocations as $a)
                <tr class="border-b">
                    <td class="px-3 py-2">
                        <a href="{{ route('purchasing.invoices.show', $a->purchase_invoice_id) }}" class="text-blue-600">{{ $a->invoice->invoice_number ?? '?' }}</a>
                    </td>
                    <td class="px-3 py-2">{{ $a->invoice ? $a->invoice->invoice_date->format('d M Y') : '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($a->invoice->total ?? 0, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($a->amount_applied, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">Tidak ada alokasi (100% jadi DP).</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($payment->notes)
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Catatan</h3>
        <div class="whitespace-pre-line text-gray-700">{{ $payment->notes }}</div>
    </div>
@endif

@include('erp.purchasing._partials.relations-payment', ['payment' => $payment])

@if(request('print'))
<script>
    window.addEventListener('load', () => {
        window.addEventListener('afterprint', () => {
            window.location = "{{ route('purchasing.payments.index') }}";
        });
        setTimeout(() => window.print(), 300);
    });
</script>
@endif
@endsection
