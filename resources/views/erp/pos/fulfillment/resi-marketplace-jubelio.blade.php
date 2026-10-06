{{-- Label resi resmi Jubelio dibingkai di halaman ERP. Pesanan sudah ditandai dicetak saat halaman ini dibuka (lihat controller). Penampil report Jubelio bisa macet
     "0 pages loaded" tanpa error → tombol Label ERP sebagai jalan keluar.
     Param: $url, $links (JubelioOrderLink), $kembali.
     Lihat FulfillmentController::tampilkanLabelJubelio(). --}}
@php
    $soIds  = $links->pluck('sales_order_id')->implode(',');
    $erpUrl = $links->count() === 1
        ? route('pos.fulfillment.jubelio-resi', ['so' => $soIds, 'erp' => 1])
        : route('pos.fulfillment.jubelio-resi-bulk', ['so' => $soIds, 'erp' => 1]);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resi Marketplace — {{ $links->count() }} label</title>
    @include('layouts.partials._favicon')
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; font-family: Arial, Helvetica, sans-serif; font-size: 13px; background: #404551; }
        .bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 12px; background: #fff; border-bottom: 1px solid #ddd; }
        .bar .info { color: #444; margin-right: auto; }
        .bar .info b { color: #111; }
        .bar a, .bar button { font: inherit; font-size: 12px; font-weight: bold; padding: 6px 12px; border-radius: 6px; cursor: pointer; text-decoration: none; }
        .btn-erp { background: #fff; color: #6d28d9; border: 1px solid #c4b5fd; }
        .btn-erp:hover { background: #f5f3ff; }
        .btn-back { background: #fff; color: #555; border: 1px solid #ccc; }
        .btn-back:hover { background: #f5f5f5; }
        iframe { flex: 1; width: 100%; border: 0; background: #404551; }
    </style>
</head>
<body>
    <div class="bar">
        <div class="info">
            <b>{{ $links->count() }} label resi dari Jubelio</b> — sudah ditandai dicetak.
            Cetak lewat tombol printer di bawah. Macet di "Loading report / 0 pages"? Pakai <b>Label ERP</b>.
        </div>
        <a href="{{ $kembali }}" class="btn-back">← Kembali</a>
        <a href="{{ $erpUrl }}" class="btn-erp">🏷️ Label tidak keluar? Cetak Label ERP</a>
    </div>
    <iframe src="{{ $url }}" title="Label resi Jubelio"></iframe>
</body>
</html>
