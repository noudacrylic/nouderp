@extends('layouts.erp')

@section('content')

    <h1 class="text-xl font-bold mb-4">
        Stok Gudang
    </h1>

    <table class="table-auto w-full border">

        <thead class="bg-gray-100">

            <tr>

                <th class="p-2 text-left">SKU</th>
                <th class="p-2 text-left">Nama Produk</th>

                @foreach($warehouses as $wh)
                    <th class="p-2 text-right">
                        {{ $wh->name }}
                    </th>
                @endforeach

            </tr>

        </thead>

        <tbody>

            @foreach($products as $product)

                <tr class="border-b">

                    <td class="p-2">
                        {{ $product->sku }}
                    </td>

                    <td class="p-2">
                        {{ $product->name }}
                    </td>

                    @foreach($warehouses as $wh)

                        @php

                            $stock = $stocks
                                ->where('product_id', $product->id)
                                ->where('warehouse_id', $wh->id)
                                ->first();

                        @endphp

                        <td class="p-2 text-right">

                            {{ number_format($stock->qty_on_hand ?? 0, 2) }}

                        </td>

                    @endforeach

                </tr>

            @endforeach

        </tbody>

    </table>

@endsection