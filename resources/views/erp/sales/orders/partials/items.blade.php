<style>
    .table-so th {
        font-weight: 600;
        font-size: 13px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        padding: 12px 16px;
        background-color: #f8fafc;
    }

    .table-so td {
        vertical-align: middle;
        font-size: 13px;
        padding: 12px 16px;
        color: #334155;
    }

    .table-so tbody tr {
        background-color: #fff;
        border-bottom: 1px solid #f1f5f9;
    }
</style>

<div class="border rounded-lg overflow-hidden shadow-sm">
    <table class="w-full text-left table-so border-collapse">
        <thead>
            <tr>
                <th style="width:150px;">SKU</th>
                <th>Nama Produk</th>
                <th class="text-right">Qty</th>
                <th>Unit</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Diskon</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($so->items as $item)
            <tr>
                <td class="font-mono text-xs text-blue-600 font-bold">
                    {{ $item->product->sku ?? '-' }}
                </td>

                <td class="font-semibold text-gray-800">
                    {{ $item->description ?: ($item->product->name ?? '-') }}
                </td>

                <td class="text-right font-medium">
                    {{ number_format($item->qty, 0) }}
                </td>

                <td class="text-gray-500 italic">
                    {{ $item->unit_name ?? 'pcs' }}
                </td>

                <td class="text-right">
                    {{ number_format($item->unit_price, 0) }}
                </td>

                <td class="text-right text-red-500">
                    {{ number_format($item->line_discount ?? 0, 0) }}
                </td>

                <td class="text-right font-bold text-gray-900">
                    {{ number_format($item->line_total, 0) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
