<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bukti Transfer {{ $bt->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 24px; color:#222; }
        h1 { font-size: 16px; margin: 0 0 4px 0; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; }
        th { background: #f3f4f6; text-align: left; }
        .right { text-align: right; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; margin-top: 8px; }
        .label { color: #666; font-size: 11px; }
        .actions { margin-bottom: 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<div class="actions">
    <button onclick="window.print()" style="padding:6px 12px">Cetak</button>
    <a href="{{ route('finance.cash-bank.transfers.show', $bt->id) }}" style="margin-left:8px">← Kembali</a>
</div>

<h1>Bukti Transfer Antar Bank</h1>
<div class="muted">No. {{ $bt->number }} · {{ $bt->date->format('d M Y') }} · Status: {{ strtoupper($bt->status) }}</div>

<div class="grid">
    <div><span class="label">Dari Akun</span><br>{{ $bt->fromAccount->code ?? '' }} — {{ $bt->fromAccount->name ?? '' }}</div>
    <div><span class="label">Ke Akun</span><br>{{ $bt->toAccount->code ?? '' }} — {{ $bt->toAccount->name ?? '' }}</div>
    <div><span class="label">Jumlah Transfer</span><br>{{ number_format($bt->amount, 0, ',', '.') }}</div>
    <div><span class="label">Biaya Admin</span><br>{{ number_format($bt->admin_fee, 0, ',', '.') }}</div>
    @if($bt->adminFeeAccount)<div><span class="label">Akun Beban Admin</span><br>{{ $bt->adminFeeAccount->code }} — {{ $bt->adminFeeAccount->name }}</div>@endif
    @if($bt->reference)<div><span class="label">Referensi</span><br>{{ $bt->reference }}</div>@endif
</div>

@if($bt->notes)<div style="margin-top:12px"><span class="label">Catatan</span><br>{{ $bt->notes }}</div>@endif

<div style="margin-top:48px; display:grid; grid-template-columns: 1fr 1fr; text-align:center;">
    <div>Dibuat<br><br><br>____________</div>
    <div>Disetujui<br><br><br>____________</div>
</div>
</body>
</html>
