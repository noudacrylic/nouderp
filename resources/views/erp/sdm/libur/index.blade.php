@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Hari Libur Nasional</h1>
    <a href="{{ route('sdm.libur.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Hari Libur</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-4 flex gap-2 text-sm items-center">
    <label class="text-gray-500">Tahun:</label>
    <select name="year" onchange="this.form.submit()" class="border rounded px-3 py-1.5">
        @foreach($years as $y)
            <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
        @endforeach
    </select>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left w-36">Tanggal</th>
                <th class="px-3 py-2 text-left w-24">Hari</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-center w-32">Cuti Bersama</th>
                <th class="px-3 py-2 text-left">Catatan</th>
                <th class="px-3 py-2 text-right w-32">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $r)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 whitespace-nowrap">{{ $r->tanggal->translatedFormat('d M Y') }}</td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $r->tanggal->translatedFormat('l') }}</td>
                    <td class="px-3 py-2 font-medium">{{ $r->nama }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($r->is_cuti_bersama)
                            <span class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700">Cuti Bersama</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $r->catatan ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('sdm.libur.edit', $r->id) }}" class="text-blue-600 text-xs">Edit</a>
                        <form method="POST" action="{{ route('sdm.libur.destroy', $r->id) }}" class="inline" onsubmit="return confirm('Hapus hari libur ini?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-600 text-xs ml-2">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada hari libur untuk tahun {{ $year }}.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
