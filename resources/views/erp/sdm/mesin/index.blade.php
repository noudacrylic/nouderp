@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Mesin Fingerprint</h1>
    <div class="flex items-center gap-2">
        @if($archivedCount > 0)
            @if($showArchived)
                <a href="{{ route('sdm.mesin.index') }}" class="text-xs px-3 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50">Sembunyikan Arsip</a>
            @else
                <a href="{{ route('sdm.mesin.index', ['archived' => 1]) }}" class="text-xs px-3 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50">Tampilkan Arsip ({{ $archivedCount }})</a>
            @endif
        @endif
        <a href="{{ route('sdm.mesin.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Mesin</a>
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Code</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Serial</th>
                <th class="px-3 py-2 text-left">IP : Port</th>
                <th class="px-3 py-2 text-left">Lokasi</th>
                <th class="px-3 py-2 text-center w-24">Online</th>
                <th class="px-3 py-2 text-left">Last Seen</th>
                <th class="px-3 py-2 text-right w-56">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($machines as $m)
                <tr class="border-b hover:bg-blue-50 {{ $m->isArchived() ? 'bg-gray-50 text-gray-500' : '' }}">
                    <td class="px-3 py-2 font-mono">
                        {{ $m->code }}
                        @if($m->isArchived())
                            <span class="ml-1 bg-gray-200 text-gray-600 text-[10px] px-1.5 py-0.5 rounded">ARSIP</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 font-medium">
                        <a href="{{ route('sdm.mesin.show', $m->id) }}" class="text-blue-700 hover:underline">{{ $m->name }}</a>
                    </td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $m->serial_number ?? '-' }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $m->ip_address ?? '-' }}:{{ $m->port }}</td>
                    <td class="px-3 py-2 text-xs">{{ $m->location ?? '-' }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($m->isArchived())
                            <span class="bg-gray-200 text-gray-500 text-xs px-2 py-0.5 rounded">Diarsipkan</span>
                        @elseif($m->isOnline())
                            <span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded">●  Online</span>
                        @elseif($m->last_seen_at)
                            <span class="bg-gray-100 text-gray-500 text-xs px-2 py-0.5 rounded">Offline</span>
                        @else
                            <span class="bg-yellow-100 text-yellow-700 text-xs px-2 py-0.5 rounded">Belum pernah</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ optional($m->last_seen_at)->diffForHumans() ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('sdm.mesin.show', $m->id) }}" class="text-blue-600 text-xs">Detail</a>
                            @if(! $m->isArchived())
                                <a href="{{ route('sdm.mesin.edit', $m->id) }}" class="text-gray-600 text-xs">Edit</a>

                                <form method="POST" action="{{ route('sdm.mesin.archive', $m->id) }}" onsubmit="return confirm('Arsipkan mesin {{ $m->name }}? Mesin tidak akan terima scan baru, tapi log lama tetap tersimpan.');" class="inline">
                                    @csrf
                                    <button type="submit" class="text-amber-600 text-xs hover:underline">Arsipkan</button>
                                </form>

                                @if($m->canBeDeleted())
                                    <form method="POST" action="{{ route('sdm.mesin.destroy', $m->id) }}" onsubmit="return confirm('Hapus permanen mesin {{ $m->name }}? Mesin ini belum punya log scan, jadi aman dihapus.');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 text-xs hover:underline">Hapus</button>
                                    </form>
                                @endif
                            @else
                                <form method="POST" action="{{ route('sdm.mesin.unarchive', $m->id) }}" onsubmit="return confirm('Aktifkan kembali mesin {{ $m->name }}?');" class="inline">
                                    @csrf
                                    <button type="submit" class="text-green-600 text-xs hover:underline">Aktifkan</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">
                    @if($showArchived)
                        Belum ada mesin terdaftar.
                    @else
                        Belum ada mesin aktif. {{ $archivedCount > 0 ? '(' . $archivedCount . ' mesin diarsipkan)' : '' }}
                    @endif
                </td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
