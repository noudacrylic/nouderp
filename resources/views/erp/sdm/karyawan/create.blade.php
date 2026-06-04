@extends('layouts.erp')

@section('content')
<div class="max-w-5xl mx-auto mb-6">
    <h1 class="text-lg font-semibold">Tambah Karyawan</h1>
    <p class="text-xs text-gray-500 mt-1">Divisi diatur dari menu <a href="{{ route('production.departments.index') }}" class="text-blue-600 hover:underline">Divisi</a> setelah karyawan dibuat.</p>
</div>

<form method="POST" action="{{ route('sdm.karyawan.store') }}">
    @csrf
    @include('erp.sdm.karyawan._form')
</form>
@endsection
