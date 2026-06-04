@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<h1 class="text-lg font-semibold mb-4">Tambah Hari Libur Nasional</h1>

<form method="POST" action="{{ route('sdm.libur.store') }}">
    @csrf
    @include('erp.sdm.libur._form')
</form>
@endsection
