@extends('layouts.erp')

@section('content')

<div class="max-w-4xl mx-auto">

    <h1 class="text-lg font-semibold mb-4">Tambah Pelanggan</h1>

    <form method="POST" action="{{ route('customers.store') }}">
        @include('erp.master.customers._form')
    </form>

</div>

@endsection
