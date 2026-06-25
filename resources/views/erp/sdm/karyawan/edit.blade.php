@extends('layouts.erp')

@section('content')
<div class="max-w-5xl mx-auto flex items-center justify-between mb-6">
    <h1 class="text-lg font-semibold">Edit Karyawan — {{ $karyawan->name }}</h1>
    <form method="POST" action="{{ route('sdm.karyawan.destroy', $karyawan->id) }}"
          onsubmit="return confirm('Nonaktifkan karyawan ini?')">
        @csrf @method('DELETE')
        <button type="submit" class="border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded text-sm">Nonaktifkan</button>
    </form>
</div>

<form method="POST" action="{{ route('sdm.karyawan.update', $karyawan->id) }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    @include('erp.sdm.karyawan._form')
</form>
@endsection
