@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Faktur Pembelian {{ $po ? '(dari PO ' . $po->po_number . ')' : '' }}</h1>


<form method="POST" action="{{ route('purchasing.invoices.store') }}">
    @include('erp.purchasing.invoices._form')
</form>
@endsection
