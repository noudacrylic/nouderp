{{-- Label resi CADANGAN untuk pesanan marketplace saat report label Jubelio error.
     Param: $labels = [['delivery','origin','dest','weight'], ...] — lihat FulfillmentController::labelResiErp(). --}}
@extends('erp.print._shell', [
    'printTitle' => 'Resi Marketplace (label ERP) — ' . count($labels) . ' label',
    'indexUrl'   => route('pos.fulfillment.telah-diproses'),
    'pageSize'   => '100mm 150mm',
])

@section('papers')

@include('erp.sales.deliveries._resi-style')

@foreach($labels as $lbl)
    <article class="paper resi-paper" data-label="Resi">
        @include('erp.sales.deliveries._resi-label', [
            'delivery'    => $lbl['delivery'],
            'origin'      => $lbl['origin'],
            'dest'        => $lbl['dest'],
            'totalWeight' => $lbl['weight'],
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
