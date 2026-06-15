@extends('erp.print._shell', [
    'printTitle' => 'Cetak ' . count($labels) . ' Label',
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => '100mm 150mm',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@foreach($labels as $lbl)
    @php
        $delivery = $lbl['delivery'];
        [$origin, $dest] = $lbl['addr'];
        $totalWeight = 0;
        foreach ($delivery->items as $it) {
            $totalWeight += (int) ($it->product->weight_gram ?? 0) * (float) $it->qty;
        }
    @endphp
    <article class="paper resi-paper" data-label="Label">
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
@endforeach

@endsection
