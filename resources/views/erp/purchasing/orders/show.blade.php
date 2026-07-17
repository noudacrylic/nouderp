@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Purchase Order — {{ $po->po_number }}</h1>
        @php
            $cls = match($po->status) {
                'posted' => 'bg-green-100 text-green-700',
                'cancelled' => 'bg-red-100 text-red-700',
                'closed' => 'bg-gray-200 text-gray-600',
                default => 'bg-yellow-100 text-yellow-700',
            };
        @endphp
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $cls }}">{{ $po->status }}</span>
    </div>
    @php
        $hasInvoice = $po->invoices()->whereIn('status', ['posted', 'draft'])->exists();
        $isLocked = in_array($po->status, ['cancelled', 'closed']);
    @endphp
    <div class="flex gap-2">
        @if(!$isLocked)
            <a href="{{ route('purchasing.invoices.create', ['po_id' => $po->id]) }}" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm">→ Buat Faktur</a>
            @if(!$hasInvoice)
                <a href="{{ route('purchasing.orders.edit', $po->id) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</a>
                <form method="POST" action="{{ route('purchasing.orders.destroy', $po->id) }}" onsubmit="return confirm('Hapus PO ini?')">
                    @csrf @method('DELETE')
                    <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Hapus</button>
                </form>
            @endif
        @endif
        <a href="{{ route('purchasing.orders.index') }}" class="px-3 py-2 border rounded text-sm">Kembali</a>
    </div>
</div>


<div class="grid grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Header</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Pemasok</div><div>{{ $po->supplier->name }}</div>
            <div class="text-gray-500">Gudang</div><div>{{ $po->warehouse->name ?? '-' }}</div>
            <div class="text-gray-500">Tanggal PO</div><div>{{ $po->po_date->format('d M Y') }}</div>
            <div class="text-gray-500">Tgl Diharapkan</div><div>{{ $po->expected_date ? $po->expected_date->format('d M Y') : '-' }}</div>
            <div class="text-gray-500">No. Faktur Pemasok</div><div>{{ $po->supplier_invoice_no ?: '-' }}</div>
        </div>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Ringkasan</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Subtotal Items</div><div class="text-right">{{ number_format($po->subtotal, 0, ',', '.') }}</div>
            <div class="text-gray-500">Diskon Line</div><div class="text-right">{{ number_format($po->discount_total, 0, ',', '.') }}</div>
            @if($po->global_discount_amount > 0)
                <div class="text-gray-500">
                    Diskon Global
                    @if($po->global_discount_type === 'percent') ({{ rtrim(rtrim(number_format($po->global_discount_value, 2), '0'), ',.') }}%) @endif
                </div>
                <div class="text-right">−{{ number_format($po->global_discount_amount, 0, ',', '.') }}</div>
            @endif
            @php $totalBeban = $po->expense_capitalized_total + $po->expense_direct_total; @endphp
            @if($totalBeban > 0)
                <div class="text-gray-500">Total Beban</div><div class="text-right">{{ number_format($totalBeban, 0, ',', '.') }}</div>
            @endif
            <div class="text-gray-500">PPN ({{ $po->ppn_percent }}%)</div><div class="text-right">{{ number_format($po->ppn_amount, 0, ',', '.') }}</div>
            <div class="font-semibold border-t pt-1">TOTAL</div><div class="text-right font-semibold border-t pt-1">{{ number_format($po->total, 0, ',', '.') }}</div>
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <h3 class="font-semibold mb-3">Items</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600 text-xs uppercase">
            <tr>
                <th class="px-3 py-2 text-left w-32">SKU</th>
                <th class="px-3 py-2 text-left">Nama Produk / Item</th>
                <th class="px-3 py-2 text-right w-20">Qty</th>
                <th class="px-3 py-2 text-right w-24">Sudah Inv</th>
                <th class="px-3 py-2 text-left w-20">Unit</th>
                <th class="px-3 py-2 text-right w-32">Harga</th>
                <th class="px-3 py-2 text-right w-28">Diskon</th>
                <th class="px-3 py-2 text-right w-32">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po->items as $i)
                <tr class="border-b">
                    <td class="px-3 py-2 font-mono text-xs text-blue-600">{{ $i->product->sku ?? '-' }}</td>
                    <td class="px-3 py-2 font-medium">{{ $i->description ?? ($i->product->name ?? '?') }}</td>
                    <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($i->qty, 4), '0'), '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($i->qty_invoiced, 4), '0'), '.') }}</td>
                    <td class="px-3 py-2">{{ $i->unit }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($i->price, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-red-600">
                        @if($i->discount > 0)
                            @if($i->discount_type === 'percent')
                                <span class="text-xs text-gray-400">{{ rtrim(rtrim(number_format($i->discount_value, 2), '0'), ',.') }}%</span>
                            @endif
                            {{ number_format($i->discount, 0, ',', '.') }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($i->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if($po->expenses->count())
<div class="bg-white rounded shadow p-4 mb-4">
    <h3 class="font-semibold mb-3">Biaya Tambahan</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Akun</th>
                <th class="px-3 py-2 text-left">Keterangan</th>
                <th class="px-3 py-2 text-left">Mode</th>
                <th class="px-3 py-2 text-right w-32">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po->expenses as $e)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $e->account->code ?? '' }} — {{ $e->account->name ?? '' }}</td>
                    <td class="px-3 py-2">{{ $e->description }}</td>
                    <td class="px-3 py-2">
                        @if($e->mode === 'capitalized')
                            <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs">Dikapitalisasi (HPP)</span>
                        @else
                            <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded text-xs">Beban Langsung</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($e->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if($po->notes)
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Catatan</h3>
        <div class="whitespace-pre-line text-gray-700">{{ $po->notes }}</div>
    </div>
@endif

@if(request('print'))
<script>
    window.addEventListener('load', () => {
        window.addEventListener('afterprint', () => {
            window.location = "{{ route('purchasing.orders.index') }}";
        });
        setTimeout(() => window.print(), 300);
    });
</script>
@endif
@endsection
