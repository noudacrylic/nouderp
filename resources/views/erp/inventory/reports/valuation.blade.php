@extends('layouts.erp')

@section('content')

    <x-erp.index-layout title="Laporan Penilaian Persediaan">

        <div class="bg-white shadow rounded-lg border overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="p-3 text-left">SKU</th>
                        <th class="p-3 text-left">Produk</th>
                        <th class="p-3 text-right">Stok Saat Ini</th>
                        <th class="p-3 text-right">Total Nilai (FIFO)</th>
                        <th class="p-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr class="border-b last:border-0 hover:bg-gray-50">
                            <td class="p-3 font-mono">{{ $product->sku }}</td>
                            <td class="p-3 font-semibold">{{ $product->name }}</td>
                            <td class="p-3 text-right">{{ format_qty($product->stock) }} {{ $product->base_unit }}</td>
                            <td class="p-3 text-right font-bold text-green-600">
                                {{ format_money($product->stock_value) }}
                            </td>
                            <td class="p-3 text-center">
                                <a href="{{ route('inventory.reports.stock-card', $product->id) }}"
                                    class="text-blue-600 hover:underline">
                                    Kartu Stok
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-bold border-t">
                    <tr>
                        <td colspan="3" class="p-3 text-right">GRAND TOTAL</td>
                        <td class="p-3 text-right text-green-700">
                            {{ format_money($products->sum('stock_value')) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    </x-erp.index-layout>

@endsection