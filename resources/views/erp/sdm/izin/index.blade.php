@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Izin / Jadwal Lembur</h1>
    <div class="flex gap-2">
        <a href="{{ route('sdm.izin.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Izin</a>
    </div>
</div>

<div class="bg-blue-50 border border-blue-200 text-blue-700 px-3 py-2 rounded text-xs mb-3">
    💡 Sistem auto-inject scan tiap hari: <strong>08:30</strong> (check in), <strong>16:30</strong> (check out), <strong>20:30</strong> (lembur — kalau dijadwalkan).
    Karyawan yang sakit/cuti/izin/lembur di tanggal tertentu, daftarkan di sini supaya scheduler skip / handle khusus.
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Karyawan</label>
        <select name="karyawan_id" class="border rounded px-3 py-1.5">
            <option value="">Semua</option>
            @foreach($karyawans as $k)
                <option value="{{ $k->id }}" @selected(request('karyawan_id') == $k->id)>{{ $k->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="border rounded px-3 py-1.5">
            <option value="">Semua</option>
            @foreach(\App\Modules\SDM\Models\AttendanceOverride::TYPES as $val => $label)
                <option value="{{ $val }}" @selected(request('type') === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="from" value="{{ request('from') }}" class="border rounded px-3 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="to" value="{{ request('to') }}" class="border rounded px-3 py-1.5">
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
    @if(request()->hasAny(['karyawan_id','type','from','to']))
        <a href="{{ route('sdm.izin.index') }}" class="text-gray-500 text-sm">Reset</a>
    @endif
</form>

<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left w-28">Tanggal</th>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Catatan</th>
                <th class="px-3 py-2 text-left">Dibuat</th>
                <th class="px-3 py-2 w-20"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($izins as $i)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2">{{ $i->tanggal->translatedFormat('D, d M Y') }}</td>
                    <td class="px-3 py-2">
                        {{ $i->karyawan->name ?? '-' }}
                        <span class="text-gray-400 text-xs font-mono">({{ $i->karyawan->staf_code ?? '-' }})</span>
                    </td>
                    <td class="px-3 py-2">
                        @php
                            $colors = [
                                'cuti'                => 'bg-blue-100 text-blue-700',
                                'sakit'               => 'bg-purple-100 text-purple-700',
                                'izin_pagi'           => 'bg-orange-100 text-orange-700',
                                'izin_sore'           => 'bg-orange-100 text-orange-700',
                                'lembur'              => 'bg-green-100 text-green-700',
                                'tukar_hari'          => 'bg-teal-100 text-teal-700',
                                'tukar_setengah_hari' => 'bg-teal-100 text-teal-700',
                                'penyesuaian_jam'     => 'bg-amber-100 text-amber-700',
                            ];
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $colors[$i->type] ?? 'bg-gray-100' }}">{{ $i->typeLabel() }}</span>
                        @if($i->paired_date)
                            <div class="text-[11px] text-gray-500 mt-0.5">
                                ↔ {{ $i->paired_date->translatedFormat('d M y') }}
                                @if($i->sesi || $i->paired_sesi)
                                    ({{ $i->sesi ?? '-' }} ↔ {{ $i->paired_sesi ?? '-' }})
                                @endif
                            </div>
                        @endif
                        @if($i->jam_masuk_override || $i->jam_pulang_override)
                            <div class="text-[11px] text-gray-500 mt-0.5 font-mono">
                                {{ $i->jam_masuk_override ? substr($i->jam_masuk_override, 0, 5) : '--:--' }}
                                →
                                {{ $i->jam_pulang_override ? substr($i->jam_pulang_override, 0, 5) : '--:--' }}
                            </div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $i->notes ?? '-' }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $i->created_at->diffForHumans() }}</td>
                    <td class="px-3 py-2">
                        <form method="POST" action="{{ route('sdm.izin.destroy', $i->id) }}" onsubmit="return confirm('Hapus izin ini?')">
                            @csrf @method('DELETE')
                            <button class="text-red-500 text-xs">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada izin terdaftar.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mb-3">{{ $izins->links() }}</div>
@endsection
