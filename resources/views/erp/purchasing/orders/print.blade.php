@extends('erp.print._shell', [
    'printTitle' => 'Pesanan Pembelian ' . $order->po_number,
    'pdfUrl'     => route('purchasing.orders.pdf', $order->id),
    'indexUrl'   => route('purchasing.orders.index'),
])

@php
    $subtotal       = (float) ($order->subtotal ?? 0);
    $discountTotal  = (float) ($order->discount_total ?? 0);
    $globalDiscount = (float) ($order->global_discount_amount ?? 0);
    $ppnPercent     = (float) ($order->ppn_percent ?? 0);
    $ppn            = (float) ($order->ppn_amount ?? 0);
    $expCap         = (float) ($order->expense_capitalized_total ?? 0);
    $expDir         = (float) ($order->expense_direct_total ?? 0);
    $grandTotal     = (float) ($order->total ?? 0);
@endphp

@section('papers')

@include('erp._partials.print-styles-accurate')

<article class="paper" id="paper-1">

@include('erp._partials.print-header-accurate', [
    'profile'   => $profile,
    'docTitle'  => 'Pesanan Pembelian',
    'docNumber' => $order->po_number,
    'docDate'   => $order->po_date,
    'extraMeta' => [
        'Tgl. Diharapkan' => isset($order->expected_date) && $order->expected_date
            ? \Carbon\Carbon::parse($order->expected_date)->format('d M Y')
            : null,
        'Gudang' => $order->warehouse->name ?? null,
    ],
])

{{-- Supplier --}}
<div class="recipient-block">
    <div class="lbl">Kepada Pemasok</div>
    <div class="name">{{ $order->supplier->name ?? '-' }}</div>
    @if(!empty($order->supplier?->address))
        <div class="addr">{{ $order->supplier->address }}</div>
    @endif
</div>

{{-- Items --}}
<table class="items-acc">
    <thead>
        <tr>
            <th>Nama Produk</th>
            <th style="width:50px;" class="num">Qty</th>
            <th style="width:48px;" class="center">Unit</th>
            <th style="width:90px;" class="num">Harga</th>
            <th style="width:80px;" class="num">Diskon</th>
            <th style="width:100px;" class="num">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @forelse($order->items as $it)
            @php $sku = $it->product->sku ?? null; @endphp
            <tr>
                <td>
                    @if($sku)<span class="sku-prefix">{{ $sku }}</span> &mdash; @endif{{ $it->description ?: ($it->product->name ?? $it->asset_name ?? '-') }}
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float)$it->qty, 4, ',', '.'), '0'), ',') }}</td>
                <td class="center">{{ $it->unit ?? 'pcs' }}</td>
                <td class="num">{{ number_format((float)$it->price, 0, ',', '.') }}</td>
                <td class="num">{{ ($it->discount ?? 0) > 0 ? '- ' . number_format((float)$it->discount, 0, ',', '.') : '0' }}</td>
                <td class="num" style="font-weight:700;">{{ number_format((float)$it->subtotal, 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center" style="color:#94a3b8; padding:16px;">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Biaya tambahan --}}
@if(($order->expenses?->count() ?? 0) > 0)
<div style="font-size: 11px; font-weight: 700; color: #0f172a; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: 0.4px;">Biaya Tambahan</div>
<table class="items-acc">
    <thead>
        <tr>
            <th>Akun</th>
            <th>Keterangan</th>
            <th class="center" style="width: 100px;">Mode</th>
            <th class="num" style="width: 120px;">Nominal</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->expenses as $e)
        <tr>
            <td>{{ trim(($e->account->code ?? '') . ' — ' . ($e->account->name ?? ''), ' —') }}</td>
            <td>{{ $e->description }}</td>
            <td class="center">{{ $e->mode }}</td>
            <td class="num">{{ number_format((float)$e->amount, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Summary --}}
<div class="summary-wrap">
    <div class="notes-box">
        <div class="h">Keterangan</div>
        <div class="body">{{ !empty(trim((string) ($order->notes ?? ''))) ? $order->notes : '—' }}</div>
    </div>
    <div class="totals-box">
        <div class="row"><span>Subtotal</span><span>{{ number_format($subtotal, 0, ',', '.') }}</span></div>
        @if($discountTotal > 0)
            <div class="row"><span>Diskon Line</span><span class="neg">- {{ number_format($discountTotal, 0, ',', '.') }}</span></div>
        @endif
        @if($globalDiscount > 0)
            <div class="row"><span>Diskon Global</span><span class="neg">- {{ number_format($globalDiscount, 0, ',', '.') }}</span></div>
        @endif
        @if($expCap > 0)
            <div class="row"><span>Expense Capitalized</span><span>{{ number_format($expCap, 0, ',', '.') }}</span></div>
        @endif
        @if($expDir > 0)
            <div class="row"><span>Expense Direct</span><span>{{ number_format($expDir, 0, ',', '.') }}</span></div>
        @endif
        @if($ppn > 0)
            <div class="row"><span>PPN ({{ rtrim(rtrim(number_format($ppnPercent, 2, ',', '.'), '0'), ',') }}%)</span><span>{{ number_format($ppn, 0, ',', '.') }}</span></div>
        @endif
        <hr>
        <div class="grand"><span>Total</span><span>Rp {{ number_format($grandTotal, 0, ',', '.') }}</span></div>
    </div>
</div>

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

@endsection
