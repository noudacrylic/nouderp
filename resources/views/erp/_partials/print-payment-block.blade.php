{{--
    Blok "Cara Pembayaran" pada dokumen cetak (Pesanan Penjualan / Faktur).

    Dua varian dirender sekaligus bila datanya ada — link+QR dan rekening transfer —
    lalu yang tidak aktif disembunyikan lewat style inline. Dengan begitu tombol
    "Info pembayaran" di toolbar (print-payment-mode-toggle) bisa menukarnya tanpa
    reload, sementara PDF (Browsershot, tanpa JS toolbar) tetap mengikuti mode bawaan
    atau query ?bayar=.

    Param: $profile, $payInstr, $payUrl, $qrSrc, $remaining
--}}
@php
    $ppBanks   = \App\Support\PrintPaymentInfo::banks($profile);
    $ppHasLink = $remaining > 0 && !empty($payUrl);
    $ppHasBank = $remaining > 0 && count($ppBanks) > 0;
    $ppMode    = \App\Support\PrintPaymentInfo::mode($ppHasLink, $ppHasBank, request()->query('bayar'));
    $ppShowLink = $ppHasLink && in_array($ppMode, ['link', 'both'], true);
    $ppShowBank = $ppHasBank && in_array($ppMode, ['bank', 'both'], true);
@endphp

@if($ppHasLink)
<div class="pay-info pay-info--link"
     style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:8px; display:{{ $ppShowLink ? 'flex' : 'none' }}; align-items:flex-start; gap:12px;">
    <div style="flex:1; font-size:10.5px; color:#64748b; line-height:1.5;">
        <span style="font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.4px;">Cara Pembayaran</span>
        <div style="margin-top:2px; white-space:pre-line;">{{ $payInstr !== '' ? $payInstr : 'Silakan lakukan pembayaran melalui link berikut. Jika kesulitan, hubungi admin kami.' }}</div>
        <div style="margin-top:3px;">🔗 Link pembayaran: <a href="{{ $payUrl }}" style="color:#2563eb; font-weight:600; word-break:break-all;">{{ $payUrl }}</a></div>
    </div>
    @if($qrSrc)
        <div style="text-align:center; flex-shrink:0; width:78px;">
            <img src="{{ $qrSrc }}" alt="QR Link Pembayaran" style="width:78px; height:78px; display:block;">
            <div style="font-size:8px; color:#94a3b8; margin-top:2px; line-height:1.25;">Arahkan kamera HP &mdash; membuka link bayar<br><b>(bukan kode QRIS)</b></div>
        </div>
    @endif
</div>
@endif

@if($ppHasBank)
<div class="pay-info pay-info--bank"
     style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:8px; display:{{ $ppShowBank ? 'block' : 'none' }};">
    @include('erp._partials.print-payment-accounts', ['banks' => $ppBanks])
</div>
@endif
