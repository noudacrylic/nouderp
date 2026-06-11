@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Rincian Kehadiran — {{ $periode->label }}</h1>
        <div class="text-xs text-gray-500 mt-1">{{ $periode->start_date->format('d M') }} – {{ $periode->end_date->format('d M Y') }}</div>
    </div>
    <a href="{{ route('sdm.periode-gaji.show', $periode->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700">Kembali ke Periode</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Karyawan</label>
        <select name="karyawan_id" class="border rounded px-3 py-1.5">
            <option value="">Semua</option>
            @foreach($karyawans as $k)
                <option value="{{ $k->id }}" @selected(request('karyawan_id') == $k->id)>{{ $k->name }} ({{ $k->staf_code }})</option>
            @endforeach
        </select>
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
    @if(request()->filled('karyawan_id'))
        <a href="{{ route('sdm.attendance.index', $periode->id) }}" class="text-gray-500 text-sm">Reset</a>
    @endif
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-center">Datang</th>
                <th class="px-3 py-2 text-center">Pulang</th>
                <th class="px-3 py-2 text-center">Off Work2</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-center w-20">Lembur</th>
                <th class="px-3 py-2 text-center w-16">Edit?</th>
                <th class="px-3 py-2 w-16"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendances as $a)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-1.5 text-xs">{{ $a->karyawan->name }} <span class="text-gray-400 font-mono">({{ $a->karyawan->staf_code }})</span></td>
                    <td class="px-3 py-1.5 text-xs">{{ $a->tanggal->translatedFormat('D, d/m/y') }}</td>
                    <td class="px-3 py-1.5 text-center font-mono text-xs">{{ $a->on_work1 ? substr($a->on_work1, 0, 5) : '' }}</td>
                    <td class="px-3 py-1.5 text-center font-mono text-xs">{{ $a->off_work1 ? substr($a->off_work1, 0, 5) : '' }}</td>
                    <td class="px-3 py-1.5 text-center font-mono text-xs">{{ $a->off_work2 ? substr($a->off_work2, 0, 5) : '' }}</td>
                    <td class="px-3 py-1.5">
                        @php
                            $statusColors = [
                                'hadir' => 'bg-green-100 text-green-700',
                                'terlambat' => 'bg-yellow-100 text-yellow-700',
                                'pulang_awal' => 'bg-yellow-100 text-yellow-700',
                                'setengah_hari' => 'bg-orange-100 text-orange-700',
                                'tidak_hadir' => 'bg-red-100 text-red-700',
                                'libur' => 'bg-gray-100 text-gray-500',
                                'cuti' => 'bg-blue-100 text-blue-700',
                                'sakit' => 'bg-purple-100 text-purple-700',
                            ];
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $statusColors[$a->status] ?? 'bg-gray-100' }}">{{ ucwords(str_replace('_',' ', $a->status)) }}</span>
                    </td>
                    <td class="px-3 py-1.5 text-center text-xs">{{ $a->overtime_hours > 0 ? rtrim(rtrim(number_format($a->overtime_hours, 2, ',', '.'), '0'), ',') : '' }}</td>
                    <td class="px-3 py-1.5 text-center text-xs">{{ $a->edited_manually ? '✓' : '' }}</td>
                    <td class="px-3 py-1.5">
                        <a href="{{ route('sdm.attendance.edit', [$periode->id, $a->id]) }}" class="text-blue-600 text-xs">Edit</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada data kehadiran. Upload Excel di periode dulu.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $attendances->links() }}</div>
@endsection
