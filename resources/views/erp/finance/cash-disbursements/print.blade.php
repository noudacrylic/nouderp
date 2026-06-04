<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Bukti Pengeluaran {{ $cd->number }}</title>
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
    <button onclick="window.print()" style="padding:6px 12px">Print</button>
    <a href="{{ route('finance.cash-bank.disbursements.show', $cd->id) }}" style="margin-left:8px">← Kembali</a>
</div>

<h1>Bukti Pengeluaran Kas / Bank</h1>
<div class="muted">No. {{ $cd->number }} · {{ $cd->date->format('d M Y') }} · Status: {{ strtoupper($cd->status) }}</div>

<div class="grid">
    <div><span class="label">Tipe</span><br>{{ ['general'=>'Umum','freight'=>'Bayar Ongkir','customer_refund'=>'Refund Customer'][$cd->type] ?? $cd->type }}</div>
    <div><span class="label">Sumber Kas/Bank</span><br>{{ $cd->cashAccount->code ?? '' }} — {{ $cd->cashAccount->name ?? '' }}</div>
    @if($cd->customer)<div><span class="label">Customer</span><br>{{ $cd->customer->name }}</div>@endif
    @if($cd->payee)<div><span class="label">Penerima</span><br>{{ $cd->payee }}</div>@endif
    @if($cd->reference)<div><span class="label">Referensi</span><br>{{ $cd->reference }}</div>@endif
</div>

@php $isFreight = $cd->type === 'freight'; @endphp

@if($isFreight)
    @php
        $cd->loadMissing(['lines.salesInvoice.customer', 'lines.salesInvoice.delivery']);
        $totalTitipan = 0; $totalBayar = 0;
        foreach ($cd->lines as $l) {
            $totalTitipan += (float) ($l->salesInvoice->shipping_cost ?? 0);
            $totalBayar   += (float) $l->amount;
        }
        $selisih = $totalTitipan - $totalBayar;
    @endphp
    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th>Invoice</th>
                <th>SJ / Resi</th>
                <th class="right" style="width:110px">Titipan</th>
                <th class="right" style="width:120px">Bayar Aktual</th>
                <th class="right" style="width:110px">Selisih</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cd->lines as $l)
                @php
                    $inv = $l->salesInvoice;
                    $titipan = (float) ($inv->shipping_cost ?? 0);
                    $bayar = (float) $l->amount;
                    $sel = $titipan - $bayar;
                @endphp
                <tr>
                    <td>{{ $inv->customer->name ?? '-' }}</td>
                    <td>{{ $inv->invoice_number ?? '-' }}</td>
                    <td>
                        {{ $inv->delivery->delivery_number ?? '—' }}<br>
                        {{ trim(($inv->delivery->courier_name ?? '') . ' ' . ($inv->delivery->tracking_number ?? '')) ?: '—' }}
                    </td>
                    <td class="right">{{ number_format($titipan, 0, ',', '.') }}</td>
                    <td class="right">{{ number_format($bayar, 0, ',', '.') }}</td>
                    <td class="right">{{ $sel > 0 ? '+' : '' }}{{ number_format($sel, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="3" class="right">Total</td>
                <td class="right">{{ number_format($totalTitipan, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($totalBayar, 0, ',', '.') }}<br><small>Cr {{ $cd->cashAccount->code ?? '' }}</small></td>
                <td class="right">{{ $selisih > 0 ? '+' : '' }}{{ number_format($selisih, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
@else
    <table>
        <thead>
            <tr>
                <th>Akun (Debit)</th>
                <th>Keterangan</th>
                <th class="right" style="width:160px">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cd->lines as $l)
                <tr>
                    <td>{{ $l->account->code ?? '' }} — {{ $l->account->name ?? '' }}</td>
                    <td>{{ $l->description }}</td>
                    <td class="right">{{ number_format($l->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="2" class="right">Total (Cr {{ $cd->cashAccount->code ?? '' }})</td>
                <td class="right">{{ number_format($cd->total, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
@endif

@if($cd->notes)
    <div style="margin-top:12px"><span class="label">Catatan</span><br>{{ $cd->notes }}</div>
@endif

<div style="margin-top:48px; display:grid; grid-template-columns: 1fr 1fr 1fr; text-align:center;">
    <div>Dibuat<br><br><br>____________</div>
    <div>Disetujui<br><br><br>____________</div>
    <div>Penerima<br><br><br>____________</div>
</div>
</body>
</html>
