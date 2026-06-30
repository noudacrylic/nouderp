{{-- Satu halaman cetak Surat Jalan. Param: $delivery, $profile.
     Dipakai oleh print.blade.php (tunggal) & print-bulk.blade.php (gabungan). --}}
<article class="paper" data-label="{{ $delivery->delivery_number }}">

@include('erp._partials.print-header-accurate', [
    'profile'   => $profile,
    'docTitle'  => 'Surat Jalan',
    'docNumber' => $delivery->delivery_number,
    'docDate'   => $delivery->delivery_date,
    'extraMeta' => [
        'No. SO'   => $delivery->order->order_number ?? null,
        'Kurir'    => $delivery->courier_name ?? null,
        'No. Resi' => $delivery->tracking_number ?? null,
    ],
])

{{-- Recipient --}}
@php
    $cust = $delivery->order->customer ?? null;
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

{{-- Items table tanpa harga --}}
<table class="items-acc">
    <thead>
        <tr>
            <th class="center" style="width: 32px;">No</th>
            <th>Nama Produk</th>
            <th style="width: 90px;">Kode</th>
            <th class="num" style="width: 80px;">Qty</th>
            <th class="center" style="width: 60px;">Satuan</th>
            <th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        @forelse($delivery->items as $i => $it)
            @php
                $soItem = $it->salesOrderItem ?? null;
                $sku = $it->product->sku ?? null;
                $name = ($soItem && !empty($soItem->description)) ? $soItem->description : ($it->product->name ?? '-');
                $unitName = $soItem->unit_name ?? ($it->product->base_unit ?? 'pcs');
            @endphp
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{{ $name }}</td>
                <td>@if($sku)<span class="sku-prefix">{{ $sku }}</span>@else - @endif</td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$it->qty, 2, ',', '.'), '0'), ',') }}</td>
                <td class="center">{{ $unitName }}</td>
                <td></td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#94a3b8; padding:16px;">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Catatan kalau ada --}}
@if(!empty(trim((string) ($delivery->notes ?? ''))))
<div style="margin-top: 14px;">
    <div class="h" style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">Keterangan</div>
    <div style="white-space: pre-wrap; color: #334155;">{{ $delivery->notes }}</div>
</div>
@endif

{{-- 3 signature blocks ala surat jalan --}}
<div class="delivery-sigs">
    <div class="sig">
        <div class="lbl">Disiapkan oleh,</div>
        <div class="line">( _________________ )</div>
    </div>
    <div class="sig">
        <div class="lbl">Pengirim,</div>
        <div class="line">( _________________ )</div>
    </div>
    <div class="sig">
        <div class="lbl">Diterima oleh,</div>
        <div class="line">( _________________ )</div>
    </div>
</div>

</article>
