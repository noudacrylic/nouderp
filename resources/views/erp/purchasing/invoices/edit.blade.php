@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit Invoice — {{ $invoice->invoice_number }}</h1>

@if($errors->any())
    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('purchasing.invoices.update', $invoice->id) }}">
    @method('PUT')
    @include('erp.purchasing.invoices._form', ['po' => null])
</form>
@endsection
