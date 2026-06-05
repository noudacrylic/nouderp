@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Daftar Absensi — Periode Penggajian</h1>
    <a href="{{ route('sdm.periode-gaji.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Periode Baru</a>
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
                <th class="px-3 py-2 text-left">Kode</th>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-center w-24">Status</th>
                <th class="px-3 py-2 text-right w-40">Total Gaji</th>
                <th class="px-3 py-2 text-left w-32">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($periodes as $p)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 font-mono">{{ $p->code }}</td>
                    <td class="px-3 py-2 font-medium">
                        <a href="{{ route('sdm.periode-gaji.show', $p->id) }}" class="text-blue-700 hover:underline">{{ $p->label }}</a>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $p->start_date->format('d M') }} – {{ $p->end_date->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $colors = [
                                'draft'     => 'bg-gray-100 text-gray-600',
                                'imported'  => 'bg-blue-100 text-blue-700',
                                'finalized' => 'bg-green-100 text-green-700',
                                'void'      => 'bg-red-100 text-red-700',
                            ];
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $colors[$p->status] ?? 'bg-gray-100' }}">{{ $p->status }}</span>
                    </td>
                    <td class="px-3 py-2 text-right">{{ rupiah($totals[$p->id] ?? 0) }}</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('sdm.periode-gaji.show', $p->id) }}" class="text-blue-600 text-xs">Buka</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada periode penggajian.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
