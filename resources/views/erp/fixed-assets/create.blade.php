@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">+ Tambah Aset Tetap</h1>
    <a href="{{ route('fixed-assets.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>


<form method="POST" action="{{ route('fixed-assets.store') }}">
    @csrf
    @include('erp.fixed-assets._form')
</form>
@endsection
