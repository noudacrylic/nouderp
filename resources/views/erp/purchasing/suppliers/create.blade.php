@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Pemasok</h1>

@if($errors->any())
    <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<form method="POST" action="{{ route('purchasing.suppliers.store') }}">
    @include('erp.purchasing.suppliers._form')
</form>
@endsection
