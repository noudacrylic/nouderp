@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Purchase Invoice — {{ $invoice->invoice_number }}</h1>
        @php
            $cls = match($invoice->status) {
                'posted' => 'bg-green-100 text-green-700',
                'void' => 'bg-red-100 text-red-700',
                default => 'bg-yellow-100 text-yellow-700',
            };
        @endphp
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $cls }}">{{ $invoice->status }}</span>
        @if($invoice->purchaseOrder)
            <a href="{{ route('purchasing.orders.show', $invoice->purchase_order_id) }}" class="text-xs text-blue-600 ml-2">← {{ $invoice->purchaseOrder->po_number }}</a>
        @endif
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2">
        @if($invoice->status === 'draft')
            <a href="{{ route('purchasing.invoices.edit', $invoice->id) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</a>
            <form method="POST" action="{{ route('purchasing.invoices.post', $invoice->id) }}" onsubmit="return confirm('POST invoice ini? Stok akan masuk + jurnal terbuat. Tidak bisa diedit lagi setelah ini.')">
                @csrf
                <button class="bg-green-600 text-white px-3 py-2 rounded text-sm">POST</button>
            </form>
            <form method="POST" action="{{ route('purchasing.invoices.destroy', $invoice->id) }}" onsubmit="return confirm('Hapus invoice draft ini?')">
                @csrf @method('DELETE')
                <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Hapus</button>
            </form>
        @elseif($invoice->status === 'posted')
            @if($invoice->outstanding_amount > 0)
                <a href="{{ route('purchasing.payments.create', ['supplier_id' => $invoice->supplier_id]) }}" class="bg-indigo-600 text-white px-3 py-2 rounded text-sm">→ Bayar</a>
            @endif
            <a href="{{ route('purchasing.returns.create', ['invoice_id' => $invoice->id]) }}" class="bg-orange-600 text-white px-3 py-2 rounded text-sm">→ Return</a>
            @if($invoice->canBeVoided())
                <form method="POST" action="{{ route('purchasing.invoices.void', $invoice->id) }}" onsubmit="return confirm('VOID invoice ini? Stok akan dikembalikan + jurnal di-void.')">
                    @csrf
                    <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Void</button>
                </form>
            @endif
        @endif
        <a href="{{ route('purchasing.invoices.index') }}" class="px-3 py-2 border rounded text-sm">Kembali</a>
    </div>
</div>


<div class="grid grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Header</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Supplier</div><div>{{ $invoice->supplier->name }}</div>
            <div class="text-gray-500">Gudang</div><div>{{ $invoice->warehouse->name ?? '-' }}</div>
            <div class="text-gray-500">Tgl Invoice</div><div>{{ $invoice->invoice_date->format('d M Y') }}</div>
            <div class="text-gray-500">Jatuh Tempo</div><div>{{ $invoice->due_date ? $invoice->due_date->format('d M Y') : '-' }}</div>
            <div class="text-gray-500">No Faktur Supplier</div><div>{{ $invoice->supplier_invoice_no ?? '-' }}</div>
            <div class="text-gray-500">Tgl Faktur Supplier</div><div>{{ $invoice->supplier_invoice_date ? $invoice->supplier_invoice_date->format('d M Y') : '-' }}</div>
        </div>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Ringkasan</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Subtotal Items</div><div class="text-right">{{ number_format($invoice->subtotal, 0, ',', '.') }}</div>
            <div class="text-gray-500">Diskon Line</div><div class="text-right">{{ number_format($invoice->discount_total, 0, ',', '.') }}</div>
            <div class="text-gray-500">
                Diskon Global
                @if($invoice->global_discount_value > 0)
                    <span class="text-xs text-gray-400">
                        ({{ $invoice->global_discount_type === 'percent'
                            ? rtrim(rtrim(number_format($invoice->global_discount_value, 2), '0'), '.') . '%'
                            : 'nominal' }})
                    </span>
                @endif
            </div>
            <div class="text-right">{{ $invoice->global_discount_amount > 0 ? '−' : '' }}{{ number_format($invoice->global_discount_amount, 0, ',', '.') }}</div>
            <div class="text-gray-500">Expense Capitalized</div><div class="text-right">{{ number_format($invoice->expense_capitalized_total, 0, ',', '.') }}</div>
            <div class="text-gray-500">Expense Direct</div><div class="text-right">{{ number_format($invoice->expense_direct_total, 0, ',', '.') }}</div>
            <div class="text-gray-500">PPN Masukan ({{ $invoice->ppn_percent }}%)</div><div class="text-right">{{ number_format($invoice->ppn_amount, 0, ',', '.') }}</div>
            <div class="font-semibold border-t pt-1">TOTAL</div><div class="text-right font-semibold border-t pt-1">{{ number_format($invoice->total, 0, ',', '.') }}</div>
            <div class="text-gray-500">Sudah Dibayar</div><div class="text-right text-green-600">{{ number_format($invoice->paid_amount, 0, ',', '.') }}</div>
            <div class="text-gray-500">Outstanding</div>
            <div class="text-right">
                @if($invoice->outstanding_amount > 0)
                    <span class="text-red-600 font-semibold">{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</span>
                @else
                    <span class="text-green-600 font-semibold">LUNAS</span>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <h3 class="font-semibold mb-3">Items</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-right w-20">Qty</th>
                <th class="px-3 py-2 text-left w-20">Unit</th>
                <th class="px-3 py-2 text-right w-32">Harga</th>
                <th class="px-3 py-2 text-right w-28">Diskon</th>
                <th class="px-3 py-2 text-right w-32">Subtotal</th>
                <th class="px-3 py-2 text-right w-32">Landed Share</th>
                <th class="px-3 py-2 text-right w-32">Final Cost / Unit</th>
                <th class="px-3 py-2 text-right w-20">Returned</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $i)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $i->product->name ?? '?' }}</td>
                    <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($i->qty, 4), '0'), '.') }}</td>
                    <td class="px-3 py-2">{{ $i->unit }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($i->price, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($i->discount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($i->subtotal, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-gray-600">{{ number_format($i->landed_cost_share, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right font-semibold">{{ number_format($i->final_unit_cost, 2, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($i->qty_returned, 4), '0'), '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if($invoice->expenses->count())
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
            @foreach($invoice->expenses as $e)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $e->account->code ?? '' }} — {{ $e->account->name ?? '' }}</td>
                    <td class="px-3 py-2">{{ $e->description }}</td>
                    <td class="px-3 py-2">
                        @if($e->mode === 'capitalized')
                            <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs">Capitalized (HPP)</span>
                        @else
                            <span class="bg-gray-200 text-gray-700 px-2 py-0.5 rounded text-xs">Direct Expense</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($e->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if($invoice->notes)
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Catatan</h3>
        <div class="whitespace-pre-line text-gray-700">{{ $invoice->notes }}</div>
    </div>
@endif

@include('erp.purchasing._partials.relations-invoice', ['invoice' => $invoice])

@if(request('print'))
<script>
    window.addEventListener('load', () => {
        window.addEventListener('afterprint', () => {
            window.location = "{{ route('purchasing.invoices.index') }}";
        });
        setTimeout(() => window.print(), 300);
    });
</script>
@endif
@endsection
