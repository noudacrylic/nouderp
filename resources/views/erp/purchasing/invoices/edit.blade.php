@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit Faktur — {{ $invoice->invoice_number }}</h1>


<form method="POST" action="{{ route('purchasing.invoices.update', $invoice->id) }}">
    @method('PUT')
    @include('erp.purchasing.invoices._form', ['po' => null])
</form>
@endsection
