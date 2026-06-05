@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $karyawan->name }} <span class="text-gray-400 text-sm font-mono">({{ $karyawan->staf_code }})</span></h1>
    <div class="flex gap-2">
        <a href="{{ route('sdm.karyawan.edit', $karyawan->id) }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        <a href="{{ route('sdm.karyawan.index') }}" class="border px-3 py-1.5 rounded text-sm text-gray-600">Kembali</a>
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif

<div class="grid grid-cols-2 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs text-gray-500 mb-1">Divisi</div>
        <div class="font-semibold">{{ $karyawan->department->name ?? '-' }}</div>
        <div class="text-xs text-gray-500 mt-1">{{ $karyawan->jabatan ?? '-' }}</div>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs text-gray-500 mb-1">Gaji Pokok / Bulan</div>
        <div class="font-bold">{{ rupiah($karyawan->gaji_pokok) }}</div>
        <div class="text-xs text-gray-400 mt-1">Tunjangan &amp; bonus diatur di Kebijakan.</div>
    </div>
</div>

<div class="grid grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs uppercase tracking-widest text-gray-400 mb-2">Identitas & Pekerjaan</div>
        <table class="w-full text-sm">
            <tr><td class="py-1 text-gray-500 w-40">Staf Code</td><td>{{ $karyawan->staf_code }}</td></tr>
            <tr><td class="py-1 text-gray-500">User ID Fingerprint</td><td>{{ $karyawan->user_id_fingerprint ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Mulai Kerja</td><td>{{ optional($karyawan->mulai_kerja)->format('d M Y') ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Sanksi</td><td>{{ $karyawan->sanksi }}</td></tr>
            <tr><td class="py-1 text-gray-500">Hak Cuti</td><td>{{ $karyawan->hak_cuti }} hari</td></tr>
            <tr><td class="py-1 text-gray-500">Status</td><td>
                @if($karyawan->is_active) Aktif @else Nonaktif @endif
            </td></tr>
        </table>
    </div>
    <div class="bg-white rounded shadow p-4 text-sm">
        <div class="text-xs uppercase tracking-widest text-gray-400 mb-2">Kontak & Bank</div>
        <table class="w-full text-sm">
            <tr><td class="py-1 text-gray-500 w-40">NIK</td><td>{{ $karyawan->nik ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">HP</td><td>{{ $karyawan->hp ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Email</td><td>{{ $karyawan->email ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Alamat</td><td>{{ $karyawan->alamat ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">NPWP</td><td>{{ $karyawan->npwp ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">BPJS</td><td>{{ $karyawan->bpjs ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Bank</td><td>{{ $karyawan->bank_name ?? '-' }} {{ $karyawan->bank_account_number ? ' · ' . $karyawan->bank_account_number : '' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Atas Nama</td><td>{{ $karyawan->bank_account_holder ?? '-' }}</td></tr>
        </table>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-6">
    <div class="flex items-center justify-between mb-2">
        <div class="text-sm font-semibold">Riwayat Slip Gaji</div>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-2 py-2 text-left">Kode</th>
                <th class="px-2 py-2 text-left">Periode</th>
                <th class="px-2 py-2 text-right">Total Gaji</th>
                <th class="px-2 py-2 text-center w-20">Status</th>
                <th class="px-2 py-2 w-20"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($slipGajis as $s)
                <tr class="border-b">
                    <td class="px-2 py-1.5 font-mono">{{ $s->code }}</td>
                    <td class="px-2 py-1.5">{{ $s->periode->label ?? '-' }}</td>
                    <td class="px-2 py-1.5 text-right">{{ rupiah($s->total_gaji) }}</td>
                    <td class="px-2 py-1.5 text-center">
                        <span class="text-xs px-2 py-0.5 rounded {{ $s->status === 'finalized' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ $s->status }}</span>
                    </td>
                    <td class="px-2 py-1.5"><a href="{{ route('sdm.slip-gaji.show', $s->id) }}" class="text-blue-600 text-xs">Rincian</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-2 py-4 text-center text-gray-400 text-xs">Belum ada slip.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
