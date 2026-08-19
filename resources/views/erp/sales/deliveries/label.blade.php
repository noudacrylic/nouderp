@extends('erp.print._shell', [
    'printTitle' => 'Label ' . $delivery->delivery_number,
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => '100mm 150mm',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@php
    // Aturan yang sama dengan popup generate resi (SalesDeliveryController@shipInfo):
    // hasil timbang tersimpan dulu, baru taksiran master produk — dan bundle memakai
    // berat sendirinya, bukan jumlah komponennya. Menghitung sendiri di sini membuat
    // label mencetak berat yang berbeda dari yang dibookingkan ke kurir.
    $totalWeight = app(\App\Modules\Shipping\Services\PackageDefaults::class)
        ->weightFor($delivery->order ?: $delivery->invoice, $delivery->items);
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
