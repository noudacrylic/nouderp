@extends('erp.print._shell', [
    'printTitle' => 'Label ' . $order->order_number,
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => '100mm 150mm',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@php
    // Satu aturan berat untuk seluruh ERP — lihat PackageDefaults. Berat paket yang
    // sudah tersimpan di SO menang atas taksiran, dan bundle memakai berat sendirinya.
    $totalWeight = app(\App\Modules\Shipping\Services\PackageDefaults::class)
        ->weightFor($order, $order->items);
    // Kurir manual → nama bersih dari master; Biteship → kode + nama layanan.
    $courierName = \App\Models\ManualCourier::nameFor($order->shipping_courier_code)
        ?: (trim(strtoupper((string) ($order->shipping_courier_code ?? '')) . ' ' . (string) ($order->shipping_service_name ?? '')) ?: null);
@endphp

<article class="paper resi-paper">
    @include('erp.sales.deliveries._manual-label', [
        'courierName' => $courierName,
        'origin'      => $origin,
        'dest'        => $dest,
        'items'       => $order->items,
        'docNumber'   => 'SO ' . $order->order_number,
        'totalWeight' => $totalWeight,
        'docDate'     => \Carbon\Carbon::parse($order->order_date ?? now())->format('d/m/Y'),
    ])
</article>

@endsection
