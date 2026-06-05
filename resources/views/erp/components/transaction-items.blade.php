<h3 class="font-semibold mb-2">Items</h3>

<table class="w-full border">

    <thead class="bg-gray-100">
        <tr>
            <th class="p-2">Produk</th>
            <th class="p-2">Unit</th>
            <th class="p-2">Qty</th>
            <th class="p-2">Harga Unit</th>
            <th class="p-2">Diskon/Unit</th>
            <th class="p-2">Subtotal Baris</th>
            <th class="p-2">Diskon Baris</th>
            <th class="p-2">Total Baris</th>
        </tr>
    </thead>

    <tbody>

        @foreach($items as $item)

            <tr class="border-t">

                <td class="p-2">
                    {{ $item->product->name }}
                </td>

                <td class="p-2 text-center text-sm">
                    {{ $item->unit_name ?? ($item->product->base_unit ?? '') }}
                </td>

                <td class="p-2 text-right">
                    {{ number_format($item->qty, 4) }} <span class="text-xs text-gray-500">{{ $item->unit_name ?? ($item->product->base_unit ?? '') }}</span>
                </td>

                <td class="p-2 text-right">
                    {{ number_format($item->unit_price) }}
                </td>

                <td class="p-2 text-right">
                    {{ number_format($item->discount_per_unit ?? 0) }}
                </td>

                <td class="p-2 text-right">
                    {{ number_format($item->line_subtotal) }}
                </td>

                <td class="p-2 text-right">
                    {{ number_format($item->line_discount) }}
                </td>

                <td class="p-2 text-right font-semibold">
                    {{ number_format($item->line_total) }}
                </td>

            </tr>

        @endforeach

    </tbody>
</table>