@extends('erp.print._shell', [
    'printTitle' => 'Cetak ' . count($invoices) . ' Faktur',
    'indexUrl'   => $indexUrl ?? route('sales.invoices.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

@foreach($invoices as $invoice)
    @include('erp.sales.invoices._paper', ['invoice' => $invoice, 'profile' => $profile])
@endforeach

@endsection

@section('toolbar-extra')
    @include('erp._partials.print-payment-mode-toggle')
@endsection
