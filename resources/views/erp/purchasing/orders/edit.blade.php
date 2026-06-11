@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit PO — {{ $po->po_number }}</h1>


<form method="POST" action="{{ route('purchasing.orders.update', $po->id) }}">
    @method('PUT')
    @include('erp.purchasing.orders._form')
</form>
@endsection
