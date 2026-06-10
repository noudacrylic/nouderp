{{-- Satu halaman cetak Pesanan Penjualan. Param: $order, $profile.
     Dipakai oleh print.blade.php (tunggal) & print-bulk.blade.php (gabungan). --}}
@php
    $subtotal       = (float) ($order->subtotal ?? $order->items->sum('line_total'));
    $globalDiscount = (float) ($order->global_discount_amount ?? 0);
    $ppnPercent     = (float) ($order->ppn_percent ?? 0);
    $ppn            = (float) ($order->ppn_amount ?? 0);
    $shipping       = (float) ($order->shipping_cost ?? 0);
    $expense        = (float) ($order->additional_fee ?? 0);
    $grandTotal     = (float) ($order->grand_total ?? 0);
    $paid           = (float) ($order->paid_amount ?? 0);
    $remaining      = max(0, $grandTotal - $paid);
    $hasNotes       = !empty(trim((string) $order->notes));

    // Pembayaran: tergantung setting Midtrans → cara bayar Midtrans (link+QR) ATAU nomor rekening.
    $showMidtransPay = \App\Models\MidtransSetting::singleton()->show_payment_method;
    $payInstr   = trim((string) ($profile->payment_instructions ?? ''));
    $payTrx     = ($showMidtransPay && $remaining > 0) ? $order->activePaymentLink() : null;
    $payUrl     = $payTrx ? url('/pay/' . $payTrx->link_token) : null;
    $qrSrc      = $payUrl ? 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=' . urlencode($payUrl) : null;
    $showPayment = $showMidtransPay && $remaining > 0 && ($payInstr !== '' || $payUrl);
    $showBankAccounts = !$showMidtransPay && $remaining > 0;
@endphp

<article class="paper" data-label="{{ $order->order_number }}">

@include('erp._partials.print-header-accurate', [
    'profile'   => $profile,
    'docTitle'  => 'Pesanan Penjualan',
    'docNumber' => $order->order_number,
    'docDate'   => $order->order_date,
    'extraMeta' => [
        'No. P.O' => $order->customer_po_number,
    ],
])

{{-- Recipient --}}
<div class="recipient-block">
    <div class="lbl">Kepada</div>
    <div class="name">{{ $order->customer->name ?? '-' }}</div>
    @if(!empty($order->customer?->address))
        <div class="addr">{{ $order->customer->address }}</div>
    @endif
</div>

{{-- Items — pola quotation --}}
<table class="items-acc">
    <thead>
        <tr>
            <th>Nama Produk</th>
            <th style="width:42px;" class="num">Qty</th>
            <th style="width:48px;" class="center">Unit</th>
            <th style="width:85px;" class="num">Harga</th>
            <th style="width:80px;" class="num">Diskon</th>
            <th style="width:100px;" class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @forelse($order->items as $it)
            @php $sku = $it->product->sku ?? null; @endphp
            <tr>
                <td>
                    @if($sku)<span class="sku-prefix">{{ $sku }}</span> &mdash; @endif{{ $it->description ?: ($it->product->name ?? '-') }}
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$it->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td class="center">{{ $it->unit_name ?? 'pcs' }}</td>
                <td class="num">{{ number_format((float)$it->unit_price, 0, ',', '.') }}</td>
                <td class="num">{{ ($it->line_discount ?? 0) > 0 ? '- ' . number_format((float)$it->line_discount, 0, ',', '.') : '0' }}</td>
                <td class="num" style="font-weight:700;">{{ number_format((float)$it->line_total, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#94a3b8; padding:16px;">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Summary — pola quotation --}}
<div class="summary-wrap">
    <div class="notes-box">
        <div class="h">Keterangan</div>
        <div class="body">{{ $hasNotes ? $order->notes : '—' }}</div>
    </div>
    <div class="totals-box">
        <div class="row"><span>Subtotal</span><span>{{ number_format($subtotal, 0, ',', '.') }}</span></div>
        @if($globalDiscount > 0)
            <div class="row"><span>Diskon Global</span><span class="neg">- {{ number_format($globalDiscount, 0, ',', '.') }}</span></div>
        @endif
        @if($ppn > 0)
            <div class="row"><span>PPN ({{ rtrim(rtrim(number_format($ppnPercent, 2, ',', '.'), '0'), ',') }}%)</span><span>{{ number_format($ppn, 0, ',', '.') }}</span></div>
        @endif
        @if($shipping > 0)
            <div class="row"><span>Ongkos Kirim</span><span>{{ number_format($shipping, 0, ',', '.') }}</span></div>
        @endif
        @if($expense > 0)
            <div class="row"><span>Biaya Lain</span><span>{{ number_format($expense, 0, ',', '.') }}</span></div>
        @endif
        <hr>
        <div class="grand"><span>Grand Total</span><span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span></div>
        @if($paid > 0)
            <hr>
            <div class="row paid"><span>Uang Muka</span><span>{{ number_format($paid, 0, ',', '.') }}</span></div>
            <div class="row remaining"><span>Sisa Pembayaran</span><span>Rp {{ number_format($remaining, 0, ',', '.') }}</span></div>
        @endif
    </div>
</div>

{{-- Info pengiriman: metode kurir, atau kode booking bila ambil di toko (di bawah, sebelum tanda tangan) --}}
@include('erp.sales._partials.shipping-info-print', ['doc' => $order, 'showBooking' => true])

{{-- Cara pembayaran (ringkas): instruksi + QR yang membuka LINK pembayaran (bukan QRIS) --}}
@if($showPayment)
<div style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:8px; display:flex; align-items:flex-start; gap:12px;">
    <div style="flex:1; font-size:10.5px; color:#64748b; line-height:1.5;">
        <span style="font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.4px;">Cara Pembayaran</span>
        <div style="margin-top:2px; white-space:pre-line;">{{ $payInstr !== '' ? $payInstr : 'Silakan lakukan pembayaran melalui link berikut. Jika kesulitan, hubungi admin kami.' }}</div>
        @if($payUrl)
            <div style="margin-top:3px;">🔗 Link pembayaran: <a href="{{ $payUrl }}" style="color:#2563eb; font-weight:600; word-break:break-all;">{{ $payUrl }}</a></div>
        @endif
    </div>
    @if($qrSrc)
        <div style="text-align:center; flex-shrink:0; width:78px;">
            <img src="{{ $qrSrc }}" alt="QR Link Pembayaran" style="width:78px; height:78px; display:block;">
            <div style="font-size:8px; color:#94a3b8; margin-top:2px; line-height:1.25;">Arahkan kamera HP &mdash; membuka link bayar<br><b>(bukan kode QRIS)</b></div>
        </div>
    @endif
</div>
@elseif($showBankAccounts)
<div style="margin-top:10px; border-top:1px solid #e2e8f0; padding-top:8px;">
    @include('erp._partials.print-payment-accounts', ['profile' => $profile])
</div>
@endif

{{-- Signature --}}
<div class="sig-section">
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

</article>
