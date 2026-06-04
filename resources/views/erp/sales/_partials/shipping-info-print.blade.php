{{-- Info pengiriman untuk dokumen cetak (SO / Invoice).
     Param:
       $doc         — SalesOrder / SalesInvoice (punya delivery_method, shipping_courier_code, shipping_service_name, pickup_code)
       $showBooking — bool: tampilkan Kode Booking saat ambil di toko (true untuk SO, false untuk Invoice) --}}
@php
    $__method      = $doc->delivery_method ?? null;
    $__isPickup    = $__method === 'ambil_toko';
    $__methodLabel = \App\Modules\Sales\Models\SalesOrder::DELIVERY_METHODS[$__method] ?? 'Kurir';
    $__courier     = trim(
        ($doc->shipping_courier_code ? strtoupper($doc->shipping_courier_code) : '')
        . ' ' . ($doc->shipping_service_name ?? '')
    );
    $__pickupCode  = $doc->pickup_code ?? null;
    $__showBooking = $showBooking ?? false;
    $__show        = $__method || $__courier !== '' || $__pickupCode;
@endphp
@if($__show)
    <div style="margin: 0 0 14px 0; font-size: 12px; color: #475569;">
        <span style="font-size:11px; color:#64748b;">Pengiriman:</span>
        <b style="color:#0f172a;">{{ $__methodLabel }}</b>
        @if(!$__isPickup && $__courier !== '')
            <span style="color:#94a3b8;">&middot;</span>
            <span style="font-size:11px; color:#64748b;">Kurir:</span>
            <b style="color:#0f172a;">{{ $__courier }}</b>
        @endif
        @if($__showBooking && $__isPickup && $__pickupCode)
            <span style="color:#94a3b8;">&middot;</span>
            <span style="font-size:11px; color:#64748b;">Kode Booking:</span>
            <b style="color:#0f172a; font-family:monospace; letter-spacing:0.3px;">{{ $__pickupCode }}</b>
        @endif
    </div>
@endif
