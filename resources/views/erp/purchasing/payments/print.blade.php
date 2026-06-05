@extends('erp.print._shell', [
    'printTitle' => 'Pembayaran Pemasok ' . $payment->payment_number,
    'pdfUrl'     => route('purchasing.payments.pdf', $payment->id),
    'indexUrl'   => route('purchasing.payments.index'),
])

@php
    $hasAllocations = $payment->allocations && $payment->allocations->count() > 0;
@endphp

@section('papers')

@include('erp._partials.print-styles-accurate')

<style>
    .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .meta-table td { border: 1px solid #cbd5e1; padding: 6px 10px; font-size: 12px; }
    .meta-table td.k { background: #f8fafc; font-weight: 700; width: 200px; color: #334155; }

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
    'docTitle'  => 'Pembayaran Pemasok',
    'docNumber' => $payment->payment_number,
    'docDate'   => $payment->payment_date,
    'extraMeta' => [
        'Metode' => $payment->payment_method ?? null,
    ],
])

{{-- Supplier --}}
<div class="recipient-block">
    <div class="lbl">Kepada Pemasok</div>
    <div class="name">{{ $payment->supplier->name ?? '-' }}</div>
    @if(!empty($payment->supplier?->address))
        <div class="addr">{{ $payment->supplier->address }}</div>
    @endif
</div>

{{-- Akun bank --}}
@if($payment->bankAccount)
<table class="meta-table">
    <tr>
        <td class="k">Akun Bank/Kas</td>
        <td>{{ $payment->bankAccount->name ?? '-' }}</td>
    </tr>
</table>
@endif

{{-- Alokasi ke faktur --}}
<div style="font-size: 11px; font-weight: 700; color: #0f172a; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: 0.4px;">Alokasi Pembayaran</div>
<table class="items-acc">
    <thead>
        <tr>
            <th style="width: 130px;">Tipe</th>
            <th>Faktur Pembelian</th>
            <th class="num" style="width: 140px;">Nominal</th>
        </tr>
    </thead>
    <tbody>
        @forelse($payment->allocations as $alloc)
            @php
                $type = $alloc->is_auto_dp ? 'Uang Muka' : 'Pelunasan';
                $ref  = $alloc->invoice->invoice_number ?? '-';
            @endphp
            <tr>
                <td>{{ $type }}</td>
                <td>{{ $ref }}</td>
                <td class="num">{{ number_format((float)$alloc->amount_applied, 0, ',', '.') }}</td>
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
        @if(($payment->allocated_amount ?? 0) > 0)
            <div class="row"><span>Teralokasi</span><span>{{ number_format((float)$payment->allocated_amount, 0, ',', '.') }}</span></div>
        @endif
        @if(($payment->used_balance ?? 0) > 0)
            <div class="row paid"><span>Saldo Digunakan</span><span>{{ number_format((float)$payment->used_balance, 0, ',', '.') }}</span></div>
        @endif
        <hr>
        <div class="grand"><span>Total Bayar</span><span>Rp {{ number_format((float)$payment->amount, 0, ',', '.') }}</span></div>
        @if(($payment->overpay ?? 0) > 0)
            <div class="row remaining"><span>Lebih Bayar</span><span>{{ number_format((float)$payment->overpay, 0, ',', '.') }}</span></div>
        @endif
        @if(($payment->remaining_amount ?? 0) > 0)
            <div class="row remaining"><span>Sisa</span><span>{{ number_format((float)$payment->remaining_amount, 0, ',', '.') }}</span></div>
        @endif
    </div>
</div>

{{-- 2-kolom signature --}}
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
