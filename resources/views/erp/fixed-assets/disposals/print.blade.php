<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Disposisi {{ $disposal->disposal_number }}</title>
<style>
body { font-family: sans-serif; font-size: 12px; padding: 20px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 6px 8px; border: 1px solid #aaa; }
.right { text-align: right; }
.title { font-size: 16px; font-weight: bold; margin-bottom: 8px; }
.muted { color: #666; }
</style>
</head>
<body>
<div class="title">Disposisi Aset Tetap</div>
<div>No: <strong>{{ $disposal->disposal_number }}</strong> · Tanggal: {{ optional($disposal->disposal_date)->format('d M Y') }} · Tipe: {{ ucfirst($disposal->disposal_type) }}</div>
<div class="muted">Aset: {{ $disposal->asset->asset_code ?? '-' }} — {{ $disposal->asset->name ?? '-' }}</div>

<h3>Ringkasan</h3>
<table>
    <tr><td>Nilai Buku saat Disposisi</td><td class="right">{{ number_format($disposal->book_value_at_disposal, 2, ',', '.') }}</td></tr>
    <tr><td>Nilai Jual / Penerimaan</td><td class="right">{{ number_format($disposal->proceeds_amount, 2, ',', '.') }}</td></tr>
    <tr><td><strong>Gain/Loss</strong></td><td class="right"><strong>{{ number_format($disposal->gain_loss_amount, 2, ',', '.') }}</strong></td></tr>
</table>

@if($disposal->journal)
<h3>Jurnal {{ $disposal->journal->journal_number }}</h3>
<table>
    <thead><tr><th>Akun</th><th>Deskripsi</th><th class="right">Debit</th><th class="right">Kredit</th></tr></thead>
    <tbody>
        @foreach($disposal->journal->lines as $l)
            <tr><td>{{ $l->account->code ?? '-' }} — {{ $l->account->name ?? '' }}</td><td>{{ $l->description }}</td><td class="right">{{ $l->debit > 0 ? number_format($l->debit, 2, ',', '.') : '' }}</td><td class="right">{{ $l->credit > 0 ? number_format($l->credit, 2, ',', '.') : '' }}</td></tr>
        @endforeach
    </tbody>
</table>
@endif

<script>window.onload = () => window.print();</script>
</body>
</html>
