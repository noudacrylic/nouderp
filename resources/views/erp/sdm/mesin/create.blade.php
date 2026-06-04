@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-lg font-semibold mb-6">Tambah Mesin Fingerprint</h1>
</div>
<form method="POST" action="{{ route('sdm.mesin.store') }}">
    @csrf
    @include('erp.sdm.mesin._form')
</form>
@endsection
