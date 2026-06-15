@extends('erp.print._shell', [
    'printTitle' => 'Resi ' . $delivery->tracking_number,
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
    @include('erp.sales.deliveries._resi-label', [
        'delivery'    => $delivery,
        'origin'      => $origin,
        'dest'        => $dest,
        'totalWeight' => $totalWeight,
        'barcodeId'   => 'barcode',
    ])
</article>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
    try {
        JsBarcode("#barcode", @json($delivery->tracking_number), { format: "CODE128", width: 2, height: 50, displayValue: false, margin: 0 });
    } catch (e) { /* abaikan kalau lib gagal dimuat */ }
</script>

@endsection
