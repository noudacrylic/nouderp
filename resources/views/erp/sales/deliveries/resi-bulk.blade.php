@extends('erp.print._shell', [
    'printTitle' => 'Cetak ' . count($labels) . ' Resi',
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => '100mm 150mm',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@foreach($labels as $lbl)
    @php
        $delivery = $lbl['delivery'];
        [$origin, $dest] = $lbl['addr'];
        // Aturan yang sama dengan popup generate resi — lihat catatan di resi.blade.php.
        $totalWeight = app(\App\Modules\Shipping\Services\PackageDefaults::class)
            ->weightFor($delivery->order ?: $delivery->invoice, $delivery->items);
    @endphp
    <article class="paper resi-paper" data-label="Resi">
        @include('erp.sales.deliveries._resi-label', [
            'delivery'    => $delivery,
            'origin'      => $origin,
            'dest'        => $dest,
            'totalWeight' => $totalWeight,
        ])
    </article>
@endforeach

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
    try {
        document.querySelectorAll('.barcode').forEach(function (el) {
            JsBarcode(el, el.getAttribute('data-resi'), { format: "CODE128", width: 2, height: 50, displayValue: false, margin: 0 });
        });
    } catch (e) { /* abaikan kalau lib gagal dimuat */ }
</script>

@endsection
