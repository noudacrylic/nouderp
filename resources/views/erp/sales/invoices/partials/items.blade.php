<div class="border rounded-lg overflow-hidden shadow-sm mb-6">
    <table class="w-full text-left border-collapse bg-white">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-100">
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider" style="width:150px;">SKU</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama Produk</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Qty</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Unit</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Harga</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Diskon</th>
                <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($invoice->items as $item)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 text-xs font-mono font-bold text-blue-600">
                    {{ $item->product->sku ?? '-' }}
                </td>

                <td class="px-4 py-3">
                    <div class="font-semibold text-gray-800 text-sm">
                        {{ $item->product->name ?? '-' }}
                    </div>
                </td>

                <td class="px-4 py-3 text-right font-medium text-sm">
                    {{ number_format($item->qty, 2) }}
                </td>

                <td class="px-4 py-3 text-gray-500 italic text-xs">
                    {{ $item->product->unit ?? 'pcs' }}
                </td>

                <td class="px-4 py-3 text-right text-sm">
                    {{ number_format($item->unit_price, 0) }}
                </td>

                <td class="px-4 py-3 text-right text-red-500 text-sm">
                    {{ number_format($item->discount_per_unit ?? 0, 0) }}
                </td>

                <td class="px-4 py-3 text-right font-bold text-gray-900 text-sm">
                    {{ number_format($item->line_total, 0) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
