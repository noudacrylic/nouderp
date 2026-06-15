@extends('erp.print._shell', [
    'printTitle' => 'Label ' . $delivery->delivery_number,
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => 'A6',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@php
    $totalWeight = 0;
    foreach ($delivery->items as $it) {
        $totalWeight += (int) ($it->product->weight_gram ?? 0) * (float) $it->qty;
    }
@endphp

<article class="paper resi-paper">
    @include('erp.sales.deliveries._manual-label', [
        'courierName' => \App\Models\ManualCourier::nameFor($delivery->shipping_courier_code) ?: $delivery->courier_name,
        'origin'      => $origin,
        'dest'        => $dest,
        'items'       => $delivery->items,
        'docNumber'   => 'SJ ' . $delivery->delivery_number,
        'totalWeight' => $totalWeight,
        'docDate'     => \Carbon\Carbon::parse($delivery->delivery_date)->format('d/m/Y'),
    ])
</article>

@endsection
