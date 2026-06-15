@extends('erp.print._shell', [
    'printTitle' => 'Label ' . $order->order_number,
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => 'A6',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@php
    $totalWeight = 0;
    foreach ($order->items as $it) {
        $totalWeight += (int) ($it->product->weight_gram ?? 0) * (float) $it->qty;
    }
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
