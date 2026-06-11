@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pelunasan Kasbon Manual</h1>
    <a href="{{ route('sdm.kasbon-pembayaran.index') }}" class="text-gray-600 text-sm">← Kembali</a>
</div>


<form method="POST" action="{{ route('sdm.kasbon-pembayaran.store') }}" class="bg-white rounded shadow p-4 max-w-3xl">
    @include('erp.sdm.kasbon-pembayaran._form', ['kp' => null])
    <div class="mt-4 flex justify-end gap-2">
        <a href="{{ route('sdm.kasbon-pembayaran.index') }}" class="px-3 py-1.5 border rounded text-sm text-gray-700">Batal</a>
        <button class="px-4 py-1.5 bg-emerald-600 text-white rounded text-sm">Simpan Draft</button>
    </div>
</form>
@endsection
