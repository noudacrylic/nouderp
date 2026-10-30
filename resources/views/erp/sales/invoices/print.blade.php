@extends('erp.print._shell', [
    'printTitle' => 'Faktur Penjualan ' . $invoice->invoice_number,
    'pdfUrl'     => route('sales.invoices.pdf', $invoice->id),
    'indexUrl'   => route('sales.invoices.index'),
])

@section('papers')

@include('erp._partials.print-styles-accurate')

@include('erp.sales.invoices._paper', [
    'invoice'     => $invoice,
    'profile'     => $profile,
    'atasNama'    => $atasNama ?? request()->query('nama'),
    'tanpaKontak' => $tanpaKontak ?? request()->boolean('tanpa_kontak'),
])

@endsection

@section('toolbar-extra')
    @include('erp._partials.print-recipient-name')
    @include('erp._partials.print-contact-toggle', [
        'sembunyikanBawaan' => $tanpaKontak ?? (request()->has('tanpa_kontak')
            ? request()->boolean('tanpa_kontak')
            : (bool) $invoice->customer?->is_marketplace),
    ])
    @include('erp._partials.print-signature-toggle')
    @include('erp._partials.print-payment-mode-toggle')
@endsection
