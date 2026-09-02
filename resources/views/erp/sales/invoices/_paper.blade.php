@php
    // Subtotal = jumlah kolom Total per item (sudah net diskon item), sama seperti tampilan di view/form.
    $subtotal       = (float) $invoice->items->sum(fn($it) => (float) ($it->subtotal ?? $it->line_total ?? ($it->qty * $it->unit_price - (float) ($it->discount_amount ?? $it->line_discount ?? 0))));
    $globalDiscount = (float) ($invoice->global_discount_amount ?? 0);
    $ppnPercent     = (float) ($invoice->ppn_percent ?? 0);
    $ppn            = (float) ($invoice->ppn_amount ?? 0);
    $shipping       = (float) ($invoice->shipping_cost ?? 0);
    $shipGross      = (float) ($invoice->shipping_gross ?? 0);
    $shipDiscount   = max(0, $shipGross - $shipping);
    $expense        = (float) ($invoice->additional_fee ?? 0);
    $marketplaceFee = (float) ($invoice->marketplace_fee ?? 0);
    $grandTotal     = (float) ($invoice->grand_total ?? 0);
    // Uang muka/DP (advance_applied) + pembayaran langsung (paid_amount) = total terbayar.
    // Sisa = grand_total − keduanya (sama dengan accessor remaining_amount).
    $advanceApplied = (float) ($invoice->advance_applied ?? 0);
    $paid           = (float) ($invoice->paid_amount ?? 0);
    $totalSettled   = $advanceApplied + $paid;
    $remaining      = max(0, round($grandTotal - $totalSettled, 2));
    $hasNotes       = !empty(trim((string) ($invoice->notes ?? '')));

    // Pembayaran: link+QR dan/atau rekening transfer. Link tetap dihitung walau setting
    // Midtrans menyuruh tampil rekening — setting cuma menentukan mana yang tampil BAWAAN,
    // pemilihannya per cetakan (lihat print-payment-block).
    $payInstr    = trim((string) ($profile->payment_instructions ?? ''));
    $invRemain   = (float) $invoice->remaining_amount;
    $payTrx      = $invRemain > 0 ? $invoice->activePaymentLink() : null;
    $payUrl      = $payTrx ? url('/pay/' . $payTrx->link_token) : null;
    $qrSrc       = $payUrl ? 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=' . urlencode($payUrl) : null;
@endphp

<article class="paper">

@include('erp._partials.print-header-accurate', [
    'profile'   => $profile,
    'docTitle'  => 'Faktur Penjualan',
    'docNumber' => $invoice->invoice_number,
    'docDate'   => $invoice->invoice_date,
    'extraMeta' => [
        'Jatuh Tempo' => isset($invoice->due_date) && $invoice->due_date
            ? \Carbon\Carbon::parse($invoice->due_date)->format('d M Y')
            : null,
    ],
])

{{-- Recipient --}}
@php
    $cust = $invoice->customer;
    // Alamat lengkap gaya alamat pengiriman (jalan + wilayah + kode pos).
    $custAddress = $cust ? $cust->fullAddress() : '';
@endphp
<div class="recipient-block">
    <div class="lbl">Kepada</div>
    <div class="name">{{ $cust->name ?? '-' }}</div>
    @if($custAddress !== '')
        <div class="addr">{{ $custAddress }}</div>
    @endif
</div>

{{-- Items --}}
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
        @forelse($invoice->items as $it)
            @php
                $sku = $it->product->sku ?? null;
                $lineDisc = (float) ($it->discount_amount ?? $it->line_discount ?? 0);
                $lineTotal = (float) ($it->subtotal ?? $it->line_total ?? ($it->qty * $it->unit_price - $lineDisc));
            @endphp
            <tr>
                <td>
                    @if($sku)<span class="sku-prefix">{{ $sku }}</span> &mdash; @endif{{ $it->description ?: ($it->product->name ?? '-') }}
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$it->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td class="center">{{ $it->unit_name ?? 'pcs' }}</td>
                <td class="num">{{ number_format((float)$it->unit_price, 0, ',', '.') }}</td>
                <td class="num">{{ $lineDisc > 0 ? '- ' . number_format($lineDisc, 0, ',', '.') : '0' }}</td>
                <td class="num" style="font-weight:700;">{{ number_format($lineTotal, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#94a3b8; padding:16px;">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Summary --}}
<div class="summary-wrap">
    <div class="notes-box">
        <div class="h">Keterangan</div>
        <div class="body">{{ $hasNotes ? $invoice->notes : '—' }}</div>
    </div>
    <div class="totals-box">
        <div class="row"><span>Subtotal</span><span>{{ number_format($subtotal, 0, ',', '.') }}</span></div>
        @if($globalDiscount > 0)
            <div class="row"><span>Diskon Global</span><span class="neg">- {{ number_format($globalDiscount, 0, ',', '.') }}</span></div>
        @endif
        @if($ppn > 0)
            <div class="row"><span>PPN ({{ rtrim(rtrim(number_format($ppnPercent, 2, ',', '.'), '0'), ',') }}%)</span><span>{{ number_format($ppn, 0, ',', '.') }}</span></div>
        @endif
        @if($shipping > 0 || $shipDiscount > 0)
            <div class="row"><span>Ongkos Kirim</span><span>{{ number_format($shipDiscount > 0 ? $shipGross : $shipping, 0, ',', '.') }}</span></div>
        @endif
        @if($shipDiscount > 0)
            <div class="row"><span>Diskon Ongkir</span><span class="neg">- {{ number_format($shipDiscount, 0, ',', '.') }}</span></div>
        @endif
        @if($expense > 0)
            <div class="row"><span>Biaya Lain</span><span>{{ number_format($expense, 0, ',', '.') }}</span></div>
        @endif
        @if($marketplaceFee > 0)
            <div class="row"><span>Biaya Admin Marketplace</span><span class="neg">- {{ number_format($marketplaceFee, 0, ',', '.') }}</span></div>
        @endif
        @if((int) ($invoice->unique_code ?? 0) !== 0)
            @php $uc = (int) $invoice->unique_code; @endphp
            <div class="row">
                <span>{{ $uc > 0 ? 'Kode Unik' : 'Penyesuaian QRIS' }}</span>
                <span class="{{ $uc > 0 ? 'neg' : '' }}">{{ $uc > 0 ? '- ' : '+ ' }}{{ number_format(abs($uc), 0, ',', '.') }}</span>
            </div>
        @endif
        <hr>
        <div class="grand"><span>Grand Total</span><span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span></div>
        @if($totalSettled > 0)
            <hr>
            @if($advanceApplied > 0)
                <div class="row paid"><span>Uang Muka</span><span>{{ number_format($advanceApplied, 0, ',', '.') }}</span></div>
            @endif
            @if($paid > 0)
                <div class="row paid"><span>Pembayaran</span><span>{{ number_format($paid, 0, ',', '.') }}</span></div>
            @endif
            <div class="row remaining"><span>Sisa</span><span>Rp {{ number_format($remaining, 0, ',', '.') }}</span></div>
        @endif
    </div>
</div>

{{-- Info pengiriman: metode kurir (tanpa kode booking), di bawah sebelum tanda tangan --}}
@include('erp.sales._partials.shipping-info-print', ['doc' => $invoice, 'showBooking' => false])

{{-- Cara pembayaran: link+QR dan/atau rekening transfer (bisa ditukar dari toolbar cetak) --}}
@include('erp._partials.print-payment-block', [
    'profile'   => $profile,
    'payInstr'  => $payInstr,
    'payUrl'    => $payUrl,
    'qrSrc'     => $qrSrc,
    'remaining' => $invRemain,
])

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
