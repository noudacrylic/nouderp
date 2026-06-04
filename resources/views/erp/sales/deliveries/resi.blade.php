<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Resi {{ $delivery->tracking_number }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; background: #f3f4f6; margin: 0; padding: 16px; color: #111; }
        .toolbar { max-width: 420px; margin: 0 auto 12px; display: flex; gap: 8px; justify-content: flex-end; }
        .btn { border: 0; border-radius: 6px; padding: 8px 14px; font-size: 13px; font-weight: bold; cursor: pointer; text-decoration: none; }
        .btn-print { background: #4f46e5; color: #fff; }
        .btn-back { background: #e5e7eb; color: #374151; }
        .label { max-width: 420px; margin: 0 auto; background: #fff; border: 2px solid #111; border-radius: 4px; padding: 12px; }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .courier { font-size: 20px; font-weight: 800; text-transform: uppercase; }
        .pill { font-size: 10px; font-weight: bold; border: 1px solid #111; border-radius: 4px; padding: 2px 6px; text-transform: uppercase; }
        .barcode-wrap { text-align: center; border-top: 1px dashed #999; border-bottom: 1px dashed #999; margin: 8px 0; padding: 6px 0; }
        .resi-num { font-family: monospace; font-size: 16px; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        .box { border: 1px solid #111; border-radius: 4px; padding: 8px; margin-top: 8px; }
        .box h4 { margin: 0 0 4px; font-size: 10px; text-transform: uppercase; color: #555; letter-spacing: .5px; }
        .name { font-weight: bold; font-size: 14px; }
        .addr { font-size: 12px; line-height: 1.4; }
        .to .name { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 12px; }
        th, td { border-bottom: 1px solid #eee; padding: 4px 2px; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
        .meta { display: flex; justify-content: space-between; font-size: 10px; color: #555; margin-top: 8px; }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none; }
            .label { border: 2px solid #000; max-width: 100%; }
            @page { margin: 8mm; }
        }
    </style>
</head>
<body>
    @php
        $totalQty = 0; $totalWeight = 0;
        foreach ($delivery->items as $it) {
            $totalQty += (float) $it->qty;
            $totalWeight += (int) ($it->product->weight_gram ?? 0) * (float) $it->qty;
        }
    @endphp

    <div class="toolbar">
        <a href="{{ route('sales.deliveries.show', $delivery->id) }}" class="btn btn-back">&larr; Kembali</a>
        <button onclick="window.print()" class="btn btn-print">🖨️ Print</button>
    </div>

    <div class="label">
        <div class="row">
            <div class="courier">{{ $delivery->shipping_courier_code ? strtoupper($delivery->shipping_courier_code) : $delivery->courier_name }}</div>
            <span class="pill">{{ $delivery->collection_method === 'dropoff' ? 'Drop Off' : 'Pickup' }}</span>
        </div>
        <div style="font-size:12px;color:#555">{{ $delivery->courier_name }}</div>

        <div class="barcode-wrap">
            <svg id="barcode"></svg>
            <div class="resi-num">{{ $delivery->tracking_number }}</div>
        </div>

        <div class="box from">
            <h4>Pengirim</h4>
            <div class="name">{{ $origin['name'] }} @if($origin['phone'])<span style="font-weight:normal;font-size:12px"> · {{ $origin['phone'] }}</span>@endif</div>
            <div class="addr">{{ $origin['address'] ?: '-' }}</div>
        </div>

        <div class="box to">
            <h4>Penerima</h4>
            <div class="name">{{ $dest['name'] }} @if($dest['phone'] && $dest['phone'] !== '-')<span style="font-weight:normal;font-size:13px"> · {{ $dest['phone'] }}</span>@endif</div>
            <div class="addr">{{ $dest['address'] }}</div>
        </div>

        <table>
            <thead><tr><th>Barang</th><th>Qty</th></tr></thead>
            <tbody>
                @foreach($delivery->items as $it)
                    <tr>
                        <td>{{ $it->product->name ?? 'Produk #'.$it->product_id }}</td>
                        <td>{{ rtrim(rtrim(number_format($it->qty, 2, '.', ''), '0'), '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="meta">
            <span>No. SJ: {{ $delivery->delivery_number }}</span>
            <span>Berat: {{ number_format($totalWeight, 0, ',', '.') }} g</span>
            <span>{{ \Carbon\Carbon::parse($delivery->delivery_date)->format('d/m/Y') }}</span>
        </div>
        <div class="meta"><span>Order ID: {{ $delivery->provider_order_id }}</span></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        try {
            JsBarcode("#barcode", @json($delivery->tracking_number), { format: "CODE128", width: 2, height: 50, displayValue: false, margin: 0 });
        } catch (e) { /* abaikan kalau lib gagal dimuat */ }
        window.addEventListener('load', function () { setTimeout(function(){ window.print(); }, 400); });
    </script>
</body>
</html>
