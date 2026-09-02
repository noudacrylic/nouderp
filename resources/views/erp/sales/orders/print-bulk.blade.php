@extends('erp.print._shell', [
    'printTitle' => 'Cetak ' . count($orders) . ' Pesanan Penjualan',
    'indexUrl'   => $indexUrl ?? route('sales.orders.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

@foreach($orders as $order)
    @include('erp.sales.orders._paper', ['order' => $order, 'profile' => $profile])
@endforeach

@endsection

@section('toolbar-extra')
    @include('erp._partials.print-payment-mode-toggle')
@endsection
