<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@php
    $fakturNo   = $order->order_number ?? '';
    $subtotal   = (float) $order->items->sum('line_total');
    $globalDisc = (float) ($order->global_discount_amount ?? 0);
    $ppn        = (float) ($order->ppn_amount ?? 0);
    $shipGross  = (float) ($order->shipping_gross ?? 0);
    $shipping   = (float) ($order->shipping_cost ?? 0);
    $shipDisc   = max(0, $shipGross - $shipping);
    $grandTotal = (float) ($order->grand_total ?? $wp->expected_amount);
    $cust       = $order->customer;
    $custAddr   = $cust && method_exists($cust, 'fullAddress') ? $cust->fullAddress() : ($cust->address ?? '');
    $methodLabel = match ($wp->method) {
        'midtrans' => 'Midtrans (Virtual Account / QRIS / Kartu)',
        'qris'     => 'QRIS',
        'transfer' => 'Transfer Bank',
        default    => strtoupper((string) $wp->method),
    };
    $paidAt = $wp->confirmed_at ?? $wp->matched_at ?? $wp->updated_at;
@endphp
<title>Faktur {{ $fakturNo }} — {{ $profile->name ?? 'Noud Acrylic' }}</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #eef2f6; color: #1f2937; font-family: "Segoe UI", Arial, sans-serif; font-size: 13px; line-height: 1.5; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 18px 14px 60px; }

    .bar { display: flex; gap: 10px; justify-content: flex-end; margin-bottom: 12px; }
    .btn { border: 0; border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-print { background: #059669; color: #fff; }
    .btn-print:hover { background: #047857; }

    .sheet { background: #fff; border-radius: 14px; box-shadow: 0 6px 24px rgba(15,23,42,.08); overflow: hidden; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 22px 26px; border-bottom: 1px solid #eef2f6; }
    .seller { display: flex; gap: 12px; align-items: center; }
    .seller img { width: 46px; height: 46px; border-radius: 10px; object-fit: cover; }
    .seller .nm { font-size: 16px; font-weight: 800; color: #111827; }
    .seller .ad { font-size: 11px; color: #6b7280; max-width: 320px; margin-top: 2px; }
    .doc { text-align: right; }
    .doc .t { font-size: 20px; font-weight: 800; letter-spacing: .5px; color: #059669; }
    .doc .n { font-size: 12px; color: #374151; margin-top: 2px; }
    .doc .d { font-size: 11px; color: #6b7280; }
    .badge { display: inline-block; margin-top: 6px; background: #dcfce7; color: #15803d; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 999px; letter-spacing: .4px; }

    .meta { display: flex; flex-wrap: wrap; gap: 20px; padding: 16px 26px; border-bottom: 1px solid #eef2f6; }
    .meta .col { flex: 1; min-width: 200px; }
    .meta .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 3px; }
    .meta .val { font-size: 13px; color: #1f2937; }
    .meta .val b { display: block; font-weight: 700; }

    table { width: 100%; border-collapse: collapse; }
    thead th { background: #f8fafc; text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; padding: 10px 26px; }
    thead th.num { text-align: right; }
    tbody td { padding: 12px 26px; border-top: 1px solid #f1f5f9; vertical-align: top; }
    tbody td.num { text-align: right; white-space: nowrap; }
    .prod .nm { font-weight: 600; color: #111827; }
    .prod .sku { font-size: 11px; color: #9ca3af; }

    .totals { display: flex; justify-content: flex-end; padding: 8px 26px 20px; }
    .totals .box { width: 300px; max-width: 100%; }
    .totals .row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; color: #4b5563; }
    .totals .row.neg span:last-child { color: #dc2626; }
    .totals .grand { display: flex; justify-content: space-between; padding: 10px 0 0; margin-top: 6px; border-top: 2px solid #111827; font-size: 16px; font-weight: 800; color: #111827; }

    .pay { margin: 0 26px 22px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 16px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
    .pay .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #16a34a; }
    .pay .val { font-weight: 700; color: #15803d; }

    .foot { padding: 14px 26px 22px; border-top: 1px solid #eef2f6; font-size: 11px; color: #9ca3af; text-align: center; }

    @media print {
        body { background: #fff; }
        .wrap { max-width: none; padding: 0; }
        .bar { display: none; }
        .sheet { box-shadow: none; border-radius: 0; }
    }
</style>
</head>
<body>
<div class="wrap">

    <div class="bar">
        <button type="button" class="btn btn-print" onclick="window.print()">⬇ Download / Cetak PDF</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div class="seller">
                @if($profile->logo_data_uri ?? null)
                    <img src="{{ $profile->logo_data_uri }}" alt="Logo">
                @endif
                <div>
                    <div class="nm">{{ $profile->name ?? 'Noud Acrylic Shop' }}</div>
                    <div class="ad">
                        {{ $profile->address ?? '' }}{{ $profile->city ? ', ' . $profile->city : '' }}
                        @if($profile->phone) &middot; {{ $profile->phone }} @endif
                        @if($profile->email) &middot; {{ $profile->email }} @endif
                    </div>
                </div>
            </div>
            <div class="doc">
                <div class="t">FAKTUR</div>
                <div class="n">{{ $fakturNo }}</div>
                <div class="d">{{ \Carbon\Carbon::parse($order->order_date)->format('d M Y') }}</div>
                <div class="badge">LUNAS</div>
            </div>
        </div>

        <div class="meta">
            <div class="col">
                <div class="lbl">Ditagihkan kepada</div>
                <div class="val"><b>{{ $cust->name ?? 'Pelanggan' }}</b>{{ $custAddr }}</div>
            </div>
            <div class="col">
                <div class="lbl">No. Pesanan</div>
                <div class="val">{{ $order->order_number }}</div>
                <div class="lbl" style="margin-top:8px;">Metode Pengiriman</div>
                <div class="val">{{ ($order->delivery_method ?? '') === 'ambil_toko' ? 'Ambil di Toko' : 'Dikirim (Kurir)' }}</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Produk</th>
                    <th class="num" style="width:60px;">Qty</th>
                    <th class="num" style="width:110px;">Harga</th>
                    <th class="num" style="width:120px;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->items as $it)
                    <tr>
                        <td class="prod">
                            <div class="nm">{{ $it->description ?: ($it->product->name ?? 'Item') }}</div>
                            @if($it->product->sku ?? null)<div class="sku">{{ $it->product->sku }}</div>@endif
                        </td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $it->qty, 2, ',', '.'), '0'), ',') }} {{ $it->unit_name ?? 'pcs' }}</td>
                        <td class="num">{{ number_format((float) $it->unit_price, 0, ',', '.') }}</td>
                        <td class="num">Rp {{ number_format((float) $it->line_total, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:20px;">Tidak ada item.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals">
            <div class="box">
                <div class="row"><span>Subtotal</span><span>Rp {{ number_format($subtotal, 0, ',', '.') }}</span></div>
                @if($globalDisc > 0)
                    <div class="row neg"><span>Diskon</span><span>- Rp {{ number_format($globalDisc, 0, ',', '.') }}</span></div>
                @endif
                @if($ppn > 0)
                    <div class="row"><span>PPN</span><span>Rp {{ number_format($ppn, 0, ',', '.') }}</span></div>
                @endif
                @if($shipGross > 0)
                    <div class="row"><span>Ongkos Kirim</span><span>Rp {{ number_format($shipGross, 0, ',', '.') }}</span></div>
                @endif
                @if($shipDisc > 0)
                    <div class="row neg"><span>Diskon Ongkir</span><span>- Rp {{ number_format($shipDisc, 0, ',', '.') }}</span></div>
                @endif
                <div class="grand"><span>Total</span><span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span></div>
            </div>
        </div>

        <div class="pay">
            <div>
                <div class="lbl">Metode Pembayaran</div>
                <div class="val">{{ $methodLabel }}</div>
            </div>
            <div style="text-align:right;">
                <div class="lbl">Dibayar pada</div>
                <div class="val">{{ $paidAt ? \Carbon\Carbon::parse($paidAt)->format('d M Y H:i') : '-' }}</div>
            </div>
        </div>

        <div class="foot">
            Dokumen ini adalah bukti pembayaran resmi dari {{ $profile->name ?? 'Noud Acrylic Shop' }} dan sah tanpa tanda tangan &amp; stempel.
        </div>
    </div>
</div>
</body>
</html>
