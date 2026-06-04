@extends('erp.print._shell', [
    'printTitle' => 'Surat Jalan ' . $delivery->delivery_number,
    'pdfUrl'     => route('sales.deliveries.pdf', $delivery->id),
    'indexUrl'   => route('sales.deliveries.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')
@include('erp.sales.deliveries._sig-style')

@include('erp.sales.deliveries._paper', ['delivery' => $delivery, 'profile' => $profile])

@endsection
