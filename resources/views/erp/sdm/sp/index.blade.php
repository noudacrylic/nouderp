@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Surat Peringatan</h1>
    <a href="{{ route('sdm.sp.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Terbitkan SP</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-4 flex gap-2 text-sm">
    <select name="karyawan_id" class="border rounded px-3 py-1.5">
        <option value="">— semua karyawan —</option>
        @foreach($karyawans as $k)
            <option value="{{ $k->id }}" @selected(request('karyawan_id') == $k->id)>{{ $k->name }} ({{ $k->staf_code }})</option>
        @endforeach
    </select>
    <select name="sanksi" class="border rounded px-3 py-1.5">
        <option value="">— semua level —</option>
        @foreach(['SP1','SP2','SP3'] as $s)
            <option value="{{ $s }}" @selected(request('sanksi') === $s)>{{ $s }}</option>
        @endforeach
    </select>
    <select name="status" class="border rounded px-3 py-1.5">
        <option value="">— semua status —</option>
        <option value="aktif" @selected(request('status') === 'aktif')>Aktif</option>
        <option value="dicabut" @selected(request('status') === 'dicabut')>Dicabut</option>
    </select>
    <button class="bg-gray-100 px-3 py-1.5 rounded">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-center w-20">Level</th>
                <th class="px-3 py-2 text-left">Alasan</th>
                <th class="px-3 py-2 text-left w-32">Berlaku Sampai</th>
                <th class="px-3 py-2 text-center w-20">Status</th>
                <th class="px-3 py-2 text-right w-32">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($records as $r)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 whitespace-nowrap">{{ $r->tanggal_terbit->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $r->karyawan->name ?? '-' }} <span class="text-gray-400 text-xs">({{ $r->karyawan->staf_code ?? '-' }})</span></td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-xs px-2 py-0.5 rounded bg-red-100 text-red-700 font-semibold">{{ $r->sanksi }}</span>
                    </td>
                    <td class="px-3 py-2 text-xs">{{ \Illuminate\Support\Str::limit($r->alasan, 80) }}</td>
                    <td class="px-3 py-2 text-xs">{{ optional($r->berlaku_sampai)->format('d M Y') ?? '-' }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($r->is_active)
                            <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">Aktif</span>
                        @else
                            <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-500">Dicabut</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('sdm.sp.edit', $r->id) }}" class="text-blue-600 text-xs">Edit</a>
                        @if($r->is_active)
                            <form method="POST" action="{{ route('sdm.sp.destroy', $r->id) }}" class="inline" onsubmit="return confirm('Cabut SP ini?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 text-xs ml-2">Cabut</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada SP terbit.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $records->links() }}</div>
@endsection
