@extends('layouts.erp')

@section('content')

    <div class="max-w-4xl mx-auto">

        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold">Kartu Stok: {{ $product->sku }} - {{ $product->name }}</h2>
            <a href="{{ route('inventory.reports.valuation') }}" class="text-blue-600 hover:underline">← Kembali ke
                Penilaian</a>
        </div>

        <div class="bg-white shadow rounded-lg border overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="p-3 text-left">Tanggal</th>
                        <th class="p-3 text-left">Transaksi</th>
                        <th class="p-3 text-right">Masuk</th>
                        <th class="p-3 text-right">Keluar</th>
                        <th class="p-3 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @php $cumulative = 0; @endphp
                    @foreach($ledgers as $l)
                        <tr class="border-b last:border-0 hover:bg-gray-50">
                            <td class="p-3 text-gray-500">{{ $l->created_at->format('d/m/Y H:i') }}</td>
                            <td class="p-3">
                                <span class="capitalize font-medium">{{ str_replace('_', ' ', $l->transaction_type) }}</span>
                                <span class="text-xs text-gray-400 block">ID: #{{ $l->transaction_id }}</span>
                            </td>
                            <td class="p-3 text-right text-green-600">
                                @if($l->qty_in > 0) +{{ format_qty($l->qty_in) }} @else - @endif
                            </td>
                            <td class="p-3 text-right text-red-600">
                                @if($l->qty_out > 0) -{{ format_qty($l->qty_out) }} @else - @endif
                            </td>
                            <td class="p-3 text-right font-bold">
                                {{ format_qty($l->balance) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($ledgers->isEmpty())
                <div class="p-10 text-center text-gray-400">
                    Tidak ada transaksi untuk produk ini.
                </div>
            @endif
        </div>

    </div>

@endsection