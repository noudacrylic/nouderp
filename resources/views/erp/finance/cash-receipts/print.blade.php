<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bukti Pemasukan {{ $cr->number }}</title>
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
        .total { font-weight: bold; background: #f9fafb; }
        .actions { margin-bottom: 12px; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
<div class="actions">
    <button onclick="window.print()" style="padding:6px 12px">Cetak</button>
    <a href="{{ route('finance.cash-bank.receipts.show', $cr->id) }}" style="margin-left:8px">← Kembali</a>
</div>

<h1>Bukti Penerimaan Kas / Bank</h1>
<div class="muted">No. {{ $cr->number }} · {{ $cr->date->format('d M Y') }} · Status: {{ strtoupper($cr->status) }}</div>

<div class="grid">
    <div><span class="label">Tipe</span><br>{{ ['general'=>'Umum','supplier_refund'=>'Refund Pemasok'][$cr->type] ?? $cr->type }}</div>
    <div><span class="label">Tujuan Kas/Bank</span><br>{{ $cr->cashAccount->code ?? '' }} — {{ $cr->cashAccount->name ?? '' }}</div>
    @if($cr->supplier)<div><span class="label">Pemasok</span><br>{{ $cr->supplier->name }}</div>@endif
    @if($cr->payer)<div><span class="label">Pembayar</span><br>{{ $cr->payer }}</div>@endif
    @if($cr->reference)<div><span class="label">Referensi</span><br>{{ $cr->reference }}</div>@endif
</div>

<table>
    <thead>
        <tr><th>Akun (Kredit)</th><th>Keterangan</th><th class="right" style="width:160px">Nominal</th></tr>
    </thead>
    <tbody>
        @foreach($cr->lines as $l)
            <tr>
                <td>{{ $l->account->code ?? '' }} — {{ $l->account->name ?? '' }}</td>
                <td>{{ $l->description }}</td>
                <td class="right">{{ number_format($l->amount, 0, ',', '.') }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td colspan="2" class="right">Total (Dr {{ $cr->cashAccount->code ?? '' }})</td>
            <td class="right">{{ number_format($cr->total, 0, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>

@if($cr->notes)
    <div style="margin-top:12px"><span class="label">Catatan</span><br>{{ $cr->notes }}</div>
@endif

<div style="margin-top:48px; display:grid; grid-template-columns: 1fr 1fr 1fr; text-align:center;">
    <div>Dibuat<br><br><br>____________</div>
    <div>Disetujui<br><br><br>____________</div>
    <div>Pembayar<br><br><br>____________</div>
</div>
</body>
</html>
