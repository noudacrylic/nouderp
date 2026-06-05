@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pelunasan Kasbon Manual</h1>
    <a href="{{ route('sdm.kasbon-pembayaran.create') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded text-sm">+ Pelunasan Baru</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-4 flex flex-wrap gap-2 items-end">
    <div>
        <label class="block text-xs text-gray-500">Cari</label>
        <input type="text" name="search" value="{{ request('search') }}" class="border rounded px-2 py-1 text-sm" placeholder="Kode / Nama">
    </div>
    <div>
        <label class="block text-xs text-gray-500">Sumber</label>
        <select name="source" class="border rounded px-2 py-1 text-sm">
            <option value="">Semua</option>
            <option value="manual" @selected(request('source') === 'manual')>Manual</option>
            <option value="gaji" @selected(request('source') === 'gaji')>Dari Gaji</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500">Status</label>
        <select name="status" class="border rounded px-2 py-1 text-sm">
            <option value="">Semua</option>
            @foreach(['draft','posted','void'] as $st)
                <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex gap-1">
        <button class="bg-gray-700 text-white px-3 py-1 text-sm rounded">Filter</button>
        <a href="{{ route('sdm.kasbon-pembayaran.index') }}" class="text-gray-600 text-sm px-2 py-1">Reset</a>
    </div>
</form>

<div class="bg-white rounded shadow overflow-hidden">
    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Tanggal</th>
                <th>Kasbon</th>
                <th>Karyawan</th>
                <th>Sumber</th>
                <th class="text-right">Jumlah</th>
                <th>Status</th>
                <th class="text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                @php
                    $badge = [
                        'draft'  => 'bg-gray-200 text-gray-700',
                        'posted' => 'bg-blue-100 text-blue-700',
                        'void'   => 'bg-red-100 text-red-700',
                    ][$r->status] ?? 'bg-gray-200 text-gray-700';
                @endphp
                <tr>
                    <td><a href="{{ route('sdm.kasbon-pembayaran.show', $r->id) }}" class="text-blue-600 font-mono">{{ $r->code }}</a></td>
                    <td>{{ $r->tanggal_bayar?->format('d/m/Y') }}</td>
                    <td class="font-mono text-xs">{{ $r->kasbon?->code }}</td>
                    <td>{{ $r->kasbon?->karyawan?->name }}</td>
                    <td><span class="text-xs px-2 py-0.5 rounded {{ $r->source === 'gaji' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700' }}">{{ $r->source }}</span></td>
                    <td class="text-right">{{ number_format($r->jumlah, 0, ',', '.') }}</td>
                    <td><span class="text-xs px-2 py-0.5 rounded {{ $badge }}">{{ strtoupper($r->status) }}</span></td>
                    <td class="text-right">
                        <a href="{{ route('sdm.kasbon-pembayaran.show', $r->id) }}" class="text-blue-600 text-xs">Rincian</a>
                        @if($r->canBeEdited())
                            · <a href="{{ route('sdm.kasbon-pembayaran.edit', $r->id) }}" class="text-amber-600 text-xs">Edit</a>
                        @endif
                        @if($r->canBeVoided())
                            <form method="POST" action="{{ route('sdm.kasbon-pembayaran.void', $r->id) }}" class="inline" onsubmit="return confirm('Void pembayaran ini?');">
                                @csrf
                                <button class="text-red-600 text-xs">· Void</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-gray-500 py-6">Belum ada pelunasan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $rows->links() }}</div>
@endsection
