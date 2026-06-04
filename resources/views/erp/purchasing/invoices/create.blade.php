@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Purchase Invoice {{ $po ? '(dari PO ' . $po->po_number . ')' : '' }}</h1>

@if($errors->any())
    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('purchasing.invoices.store') }}">
    @include('erp.purchasing.invoices._form')
</form>
@endsection
