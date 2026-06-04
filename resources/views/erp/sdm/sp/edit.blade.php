@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<h1 class="text-lg font-semibold mb-4">Edit Surat Peringatan</h1>

<form method="POST" action="{{ route('sdm.sp.update', $sp->id) }}">
    @csrf @method('PUT')
    @include('erp.sdm.sp._form')
</form>
@endsection
