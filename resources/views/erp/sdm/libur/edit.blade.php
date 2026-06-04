@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<h1 class="text-lg font-semibold mb-4">Edit Hari Libur</h1>

<form method="POST" action="{{ route('sdm.libur.update', $libur->id) }}">
    @csrf @method('PUT')
    @include('erp.sdm.libur._form')
</form>
@endsection
