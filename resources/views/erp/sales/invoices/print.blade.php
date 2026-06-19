@extends('erp.print._shell', [
    'printTitle' => 'Faktur Penjualan ' . $invoice->invoice_number,
    'pdfUrl'     => route('sales.invoices.pdf', $invoice->id),
    'indexUrl'   => route('sales.invoices.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

@include('erp.sales.invoices._paper', ['invoice' => $invoice, 'profile' => $profile])

@endsection

@section('toolbar-extra')
    @include('erp._partials.print-signature-toggle')
@endsection
