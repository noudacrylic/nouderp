@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $supplier->name }} <span class="text-gray-400 text-sm">({{ $supplier->code }})</span></h1>
    <div class="flex gap-2">
        <a href="{{ route('purchasing.suppliers.edit', $supplier->id) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</a>
        <a href="{{ route('purchasing.suppliers.index') }}" class="px-3 py-2 border rounded text-sm">Kembali</a>
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Info Kontak</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Telp</div><div>{{ $supplier->phone ?? '-' }}</div>
            <div class="text-gray-500">Email</div><div>{{ $supplier->email ?? '-' }}</div>
            <div class="text-gray-500">Kota</div><div>{{ $supplier->city ?? '-' }}</div>
            <div class="text-gray-500">Alamat</div><div>{{ $supplier->address ?? '-' }}</div>
            <div class="text-gray-500">NPWP</div><div>{{ $supplier->npwp ?? '-' }}</div>
            <div class="text-gray-500">Term Bayar</div><div>{{ $supplier->payment_term_days }} hari</div>
        </div>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <h3 class="font-semibold mb-2">Bank</h3>
        <div class="grid grid-cols-2 gap-2">
            <div class="text-gray-500">Bank</div><div>{{ $supplier->bank_name ?? '-' }}</div>
            <div class="text-gray-500">No. Rek</div><div>{{ $supplier->bank_account_no ?? '-' }}</div>
            <div class="text-gray-500">a/n</div><div>{{ $supplier->bank_account_name ?? '-' }}</div>
        </div>
    </div>
</div>

<div class="mt-6 bg-white rounded shadow p-4">
    <h3 class="font-semibold mb-3">Riwayat Purchase Order ({{ $supplier->purchaseOrders->count() }})</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No PO</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($supplier->purchaseOrders->sortByDesc('po_date')->take(20) as $po)
                <tr class="border-b">
                    <td class="px-3 py-2"><a href="{{ route('purchasing.orders.show', $po->id) }}" class="text-blue-600">{{ $po->po_number }}</a></td>
                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($po->po_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($po->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center text-xs uppercase">{{ $po->status }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">Belum ada PO.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6 bg-white rounded shadow p-4">
    <h3 class="font-semibold mb-3">Riwayat Invoice ({{ $supplier->purchaseInvoices->count() }})</h3>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Invoice</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-right">Total</th>
                <th class="px-3 py-2 text-right">Outstanding</th>
                <th class="px-3 py-2 text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($supplier->purchaseInvoices->sortByDesc('invoice_date')->take(20) as $inv)
                <tr class="border-b">
                    <td class="px-3 py-2"><a href="{{ route('purchasing.invoices.show', $inv->id) }}" class="text-blue-600">{{ $inv->invoice_number }}</a></td>
                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($inv->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($inv->outstanding_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center text-xs uppercase">{{ $inv->status }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-4 text-center text-gray-400">Belum ada invoice.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
