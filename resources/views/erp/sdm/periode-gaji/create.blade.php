@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-6">Periode Penggajian Baru</h1>


<form method="POST" action="{{ route('sdm.periode-gaji.store') }}" class="bg-white rounded shadow p-5 max-w-lg">
    @csrf
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Bulan</label>
            <select name="bulan" required class="border rounded px-3 py-2 w-full">
                @foreach([1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'] as $n => $label)
                    <option value="{{ $n }}" @selected(old('bulan', now()->month) == $n)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tahun</label>
            <input type="number" name="tahun" required value="{{ old('tahun', now()->year) }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="notes" rows="2" class="border rounded px-3 py-2 w-full">{{ old('notes') }}</textarea>
        </div>
    </div>
    <div class="mt-5 flex gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan & Buka</button>
        <a href="{{ route('sdm.periode-gaji.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</form>
@endsection
