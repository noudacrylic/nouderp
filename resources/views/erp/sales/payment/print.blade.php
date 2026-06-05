@extends('erp.print._shell', [
    'printTitle' => 'Bukti Pembayaran ' . $payment->payment_number,
    'pdfUrl'     => route('sales.payment.pdf', $payment->id),
    'indexUrl'   => route('sales.payment.index'),
])

@php
    $isInvoicePay = ($payment->payment_type ?? '') === 'invoice';
    $netCash = (float) ($payment->amount ?? 0) - (float) ($payment->admin_fee ?? 0);
@endphp

@section('papers')

@include('erp._partials.print-styles-accurate')

<style>
    .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .meta-table td { border: 1px solid #cbd5e1; padding: 6px 10px; font-size: 12px; }
    .meta-table td.k { background: #f8fafc; font-weight: 700; width: 180px; color: #334155; }

    .pay-sigs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-top: 26px;
    }
    .pay-sigs .sig-greeting { color: #1e293b; }
    .pay-sigs .sig-block { margin-top: 8px; }
    .pay-sigs .sig-name { font-weight: 700; text-decoration: underline; color: #0f172a; }
    .pay-sigs .sig-title { color: #475569; font-size: 11px; }
    .pay-sigs .sig-img { height: 70px; max-width: 220px; display: block; margin: 4px 0; }
    .pay-sigs .blank-line {
        border-top: 1px solid #94a3b8;
        padding-top: 4px; font-size: 11px; color: #64748b;
        margin-top: 60px;
    }
</style>

<article class="paper" id="paper-1">

@include('erp._partials.print-header-accurate', [
    'profile'   => $profile,
    'docTitle'  => 'Bukti Pembayaran',
    'docNumber' => $payment->payment_number,
    'docDate'   => $payment->date,
    'extraMeta' => [
        'Tipe' => $isInvoicePay ? 'Pelunasan Faktur' : 'Uang Muka',
    ],
])

{{-- Customer --}}
<div class="recipient-block">
    <div class="lbl">Pelanggan</div>
    <div class="name">{{ $payment->customer->name ?? '-' }}</div>
    @if(!empty($payment->customer?->phone))
        <div class="addr">Telp. {{ $payment->customer->phone }}</div>
    @endif
</div>

{{-- Akun Kas --}}
<table class="meta-table">
    <tr>
        <td class="k">Akun Kas</td>
        <td>{{ $payment->cashAccount->name ?? '-' }}</td>
    </tr>
    @if($payment->feeAccount)
    <tr>
        <td class="k">Akun Biaya Admin</td>
        <td>{{ $payment->feeAccount->name }}</td>
    </tr>
    @endif
</table>

{{-- Alokasi Pembayaran --}}
<div style="font-size: 11px; font-weight: 700; color: #0f172a; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: 0.4px;">Alokasi Pembayaran</div>
<table class="items-acc">
    <thead>
        <tr>
            <th style="width: 130px;">Tipe</th>
            <th>Referensi</th>
            <th class="num" style="width: 140px;">Nominal</th>
        </tr>
    </thead>
    <tbody>
        @forelse($payment->allocations as $alloc)
            @php
                if ($alloc->invoice) {
                    $type = 'Faktur'; $ref = $alloc->invoice->invoice_number;
                } elseif ($alloc->billing) {
                    $type = 'Billing'; $ref = $alloc->billing->billing_number ?? ('#' . $alloc->billing_id);
                } elseif ($alloc->salesOrder) {
                    $type = 'Uang Muka SO'; $ref = $alloc->salesOrder->order_number;
                } else {
                    $type = '-'; $ref = '-';
                }
            @endphp
            <tr>
                <td>{{ $type }}</td>
                <td>{{ $ref }}</td>
                <td class="num">{{ number_format((float)$alloc->amount, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="center" style="color:#94a3b8; padding:16px;">Tidak ada alokasi.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Summary --}}
<div class="summary-wrap">
    <div class="notes-box">
        @if(!empty($payment->notes))
            <div class="h">Catatan</div>
            <div class="body">{{ $payment->notes }}</div>
        @endif
    </div>
    <div class="totals-box">
        <div class="row"><span>Jumlah Bayar</span><span>{{ number_format((float)$payment->amount, 0, ',', '.') }}</span></div>
        @if(($payment->admin_fee ?? 0) > 0)
            <div class="row"><span>Biaya Admin</span><span class="neg">- {{ number_format((float)$payment->admin_fee, 0, ',', '.') }}</span></div>
            <hr>
            <div class="grand"><span>Kas Masuk Bersih</span><span>Rp {{ number_format($netCash, 0, ',', '.') }}</span></div>
        @else
            <hr>
            <div class="grand"><span>Total</span><span>Rp {{ number_format((float)$payment->amount, 0, ',', '.') }}</span></div>
        @endif
        @if(($payment->used_balance ?? 0) > 0)
            <div class="row paid"><span>Saldo Digunakan</span><span>{{ number_format((float)$payment->used_balance, 0, ',', '.') }}</span></div>
        @endif
        @if(($payment->overpay ?? 0) > 0)
            <div class="row remaining"><span>Lebih Bayar</span><span>{{ number_format((float)$payment->overpay, 0, ',', '.') }}</span></div>
        @endif
    </div>
</div>

{{-- 2-kolom signature: kiri = penerima, kanan = company --}}
<div class="pay-sigs">
    <div>
        <div class="sig-greeting">Diterima Oleh,</div>
        <div class="sig-block">
            <div class="blank-line">( _________________ )</div>
        </div>
    </div>
    <div>
        <div class="sig-greeting">Hormat Kami,</div>
        <div class="sig-block">
            @if($profile->signature_data_uri)
                <img src="{{ $profile->signature_data_uri }}" alt="Tanda Tangan" class="sig-img">
            @else
                <div style="height: 70px;"></div>
            @endif
            <div class="sig-name">{{ $profile->signer_name ?: '—' }}</div>
            @if($profile->signer_title)
                <div class="sig-title">{{ $profile->signer_title }}</div>
            @endif
        </div>
    </div>
</div>

</article>

@endsection
