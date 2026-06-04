@extends('erp.print._shell', [
    'printTitle' => 'Cetak ' . count($deliveries) . ' Surat Jalan',
    'indexUrl'   => $indexUrl ?? route('sales.deliveries.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')
@include('erp.sales.deliveries._sig-style')

@foreach($deliveries as $delivery)
    @include('erp.sales.deliveries._paper', ['delivery' => $delivery, 'profile' => $profile])
@endforeach

@endsection
