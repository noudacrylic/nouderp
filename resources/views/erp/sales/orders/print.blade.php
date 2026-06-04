@extends('erp.print._shell', [
    'printTitle' => 'Pesanan Penjualan ' . $order->order_number,
    'pdfUrl'     => route('sales.orders.pdf', $order->id),
    'indexUrl'   => route('sales.orders.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

@include('erp.sales.orders._paper', ['order' => $order, 'profile' => $profile])

@endsection
