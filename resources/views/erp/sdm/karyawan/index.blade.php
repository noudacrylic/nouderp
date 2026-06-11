@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Karyawan</h1>
    <a href="{{ route('sdm.karyawan.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Karyawan</a>
</div>


<form method="GET" id="filter-form" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Staf Code / Nama" autocomplete="off"
               class="border rounded px-3 py-1.5 w-64 search-live" id="filter-q">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Divisi</label>
        <select name="department_id" class="border rounded px-3 py-1.5 filter-auto">
            <option value="">Semua</option>
            @foreach($departments as $d)
                <option value="{{ $d->id }}" @selected(request('department_id') == $d->id)>{{ $d->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center gap-2 pb-1">
        <input type="hidden" name="show_inactive" value="0">
        <input type="checkbox" name="show_inactive" value="1" id="filter-show-inactive" @checked($showInactive)
               class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 filter-auto">
        <label for="filter-show-inactive" class="text-gray-700 select-none cursor-pointer">Tampilkan nonaktif</label>
    </div>
    @if(request()->filled('q') || request()->filled('department_id'))
        <a href="{{ route('sdm.karyawan.index') }}" class="text-gray-500 text-sm pb-1">Reset pencarian</a>
    @endif
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left whitespace-nowrap">Staf Code</th>
                <th class="px-3 py-2 text-left whitespace-nowrap">ID Fingerprint</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Divisi</th>
                <th class="px-3 py-2 text-left">Jabatan</th>
                <th class="px-3 py-2 text-right w-32">Gaji Pokok</th>
                <th class="px-3 py-2 text-center w-16">Sanksi</th>
                <th class="px-3 py-2 text-center w-20">Status</th>
                <th class="px-3 py-2 text-right w-28">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($karyawans as $k)
                <tr class="border-b hover:bg-blue-50 cursor-pointer js-row-link"
                    data-href="{{ route('sdm.karyawan.edit', $k->id) }}">
                    <td class="px-3 py-2 whitespace-nowrap font-mono">
                        {{ $k->staf_code }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $k->staf_code])
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap font-mono text-xs text-gray-600">
                        @if($k->user_id_fingerprint)
                            {{ $k->user_id_fingerprint }}
                            @include('erp.purchasing._partials.copy-btn', ['value' => $k->user_id_fingerprint])
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2 font-medium">
                        <a href="{{ route('sdm.karyawan.show', $k->id) }}" class="text-blue-700 hover:underline">{{ $k->name }}</a>
                    </td>
                    <td class="px-3 py-2">{{ $k->department->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $k->jabatan ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ rupiah($k->gaji_pokok) }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-xs font-bold px-2 py-0.5 rounded
                            @if($k->sanksi === 'SP0') bg-green-100 text-green-700
                            @elseif($k->sanksi === 'SP1') bg-yellow-100 text-yellow-700
                            @elseif($k->sanksi === 'SP2') bg-orange-100 text-orange-700
                            @else bg-red-100 text-red-700 @endif">{{ $k->sanksi }}</span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($k->is_active)
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs">Aktif</span>
                        @else
                            <span class="bg-gray-200 text-gray-600 px-2 py-0.5 rounded text-xs">Nonaktif</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @if($k->canBeDeleted())
                                <form method="POST" action="{{ route('sdm.karyawan.destroy', $k->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus permanen karyawan {{ $k->name }}? Tindakan ini tidak dapat dibatalkan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @elseif($k->is_active)
                                <form method="POST" action="{{ route('sdm.karyawan.archive', $k->id) }}" class="inline"
                                      onsubmit="return confirm('Arsipkan karyawan {{ $k->name }}? Karyawan akan dinonaktifkan.')">
                                    @csrf
                                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-2 py-1 rounded text-xs">Arsipkan</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada karyawan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $karyawans->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
