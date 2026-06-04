@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Log Scan — {{ $machine->name }}</h1>
        <div class="text-xs text-gray-500">{{ $machine->serial_number ?? '-' }}</div>
    </div>
    <a href="{{ route('sdm.mesin.show', $machine->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700">Kembali</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tanggal</label>
        <input type="date" name="date" value="{{ request('date') }}" class="border rounded px-3 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Diproses</label>
        <select name="processed" class="border rounded px-3 py-1.5">
            <option value="">Semua</option>
            <option value="yes" @selected(request('processed') === 'yes')>Sudah</option>
            <option value="no" @selected(request('processed') === 'no')>Belum</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Match Karyawan</label>
        <select name="matched" class="border rounded px-3 py-1.5">
            <option value="">Semua</option>
            <option value="yes" @selected(request('matched') === 'yes')>Match</option>
            <option value="no" @selected(request('matched') === 'no')>Belum Match</option>
        </select>
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
    @if(request()->hasAny(['date','processed','matched']))
        <a href="{{ route('sdm.mesin.logs', $machine->id) }}" class="text-gray-500 text-sm">Reset</a>
    @endif
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Scan At</th>
                <th class="px-3 py-2 text-left">PIN</th>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Method</th>
                <th class="px-3 py-2 text-left">Type</th>
                <th class="px-3 py-2 text-center w-20">Diproses</th>
                <th class="px-3 py-2 text-left">Attendance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="border-b">
                    <td class="px-3 py-1.5 font-mono text-xs">{{ $log->scan_at->format('d/m/Y H:i:s') }}</td>
                    <td class="px-3 py-1.5 font-mono">{{ $log->user_id_fingerprint }}</td>
                    <td class="px-3 py-1.5">
                        @if($log->karyawan)
                            {{ $log->karyawan->name }} <span class="text-gray-400 text-xs">({{ $log->karyawan->staf_code }})</span>
                        @else
                            <span class="text-red-500 text-xs">⚠ tidak match</span>
                        @endif
                    </td>
                    <td class="px-3 py-1.5 text-xs">{{ $log->verify_method ?? '-' }}</td>
                    <td class="px-3 py-1.5 text-xs">{{ $log->verify_type ?? '-' }}</td>
                    <td class="px-3 py-1.5 text-center">
                        @if($log->processed)<span class="text-green-600">✓</span>@else<span class="text-gray-400">—</span>@endif
                    </td>
                    <td class="px-3 py-1.5 text-xs">
                        @if($log->attendance_id)
                            #{{ $log->attendance_id }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Tidak ada log dengan filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $logs->links() }}</div>
@endsection
