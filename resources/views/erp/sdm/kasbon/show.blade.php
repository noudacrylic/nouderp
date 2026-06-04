@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">
        Kasbon <span class="font-mono text-sm text-gray-500">{{ $kasbon->code }}</span>
        @php
            $badge = [
                'draft'  => 'bg-gray-200 text-gray-700',
                'posted' => 'bg-blue-100 text-blue-700',
                'lunas'  => 'bg-green-100 text-green-700',
                'void'   => 'bg-red-100 text-red-700',
            ][$kasbon->status] ?? 'bg-gray-200 text-gray-700';
        @endphp
        <span class="ml-2 text-xs px-2 py-0.5 rounded {{ $badge }}">{{ strtoupper($kasbon->status) }}</span>
    </h1>
    <div class="flex gap-2 text-sm">
        @if($kasbon->canBeEdited())
            <a href="{{ route('sdm.kasbon.edit', $kasbon->id) }}" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded">Edit</a>
        @endif
        @if($kasbon->canBePosted())
            <form method="POST" action="{{ route('sdm.kasbon.post', $kasbon->id) }}" onsubmit="return confirm('Posting kasbon? Jurnal akan dibuat & sisa terhutang aktif.');">
                @csrf
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded">Post / Cairkan</button>
            </form>
        @endif
        @if($kasbon->canBeVoided())
            <form method="POST" action="{{ route('sdm.kasbon.void', $kasbon->id) }}" onsubmit="return confirm('Void kasbon ini? Jurnal akan direverse.');">
                @csrf
                <button class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded">Void</button>
            </form>
        @endif
        <a href="{{ route('sdm.kasbon.index') }}" class="px-3 py-1.5 text-gray-700">← Kembali</a>
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="md:col-span-2 bg-white rounded shadow p-4 text-sm">
        <h2 class="font-semibold mb-3 border-b pb-2">Detail Kasbon</h2>
        <dl class="grid grid-cols-2 gap-y-2">
            <dt class="text-gray-500">Karyawan</dt><dd>{{ $kasbon->karyawan->name }} <span class="text-xs text-gray-500">({{ $kasbon->karyawan->staf_code }})</span></dd>
            <dt class="text-gray-500">Tanggal Pengajuan</dt><dd>{{ $kasbon->tanggal_pengajuan?->format('d M Y') }}</dd>
            <dt class="text-gray-500">Jumlah Pinjaman</dt><dd class="font-semibold">Rp {{ number_format($kasbon->jumlah_pinjaman, 0, ',', '.') }}</dd>
            <dt class="text-gray-500">Cicilan per Bulan</dt><dd>Rp {{ number_format($kasbon->cicilan_per_bulan, 0, ',', '.') }}</dd>
            <dt class="text-gray-500">Mulai Potong</dt><dd>{{ $kasbon->tanggal_mulai_potong?->format('d M Y') ?: '—' }}</dd>
            <dt class="text-gray-500">Akun Pencairan</dt><dd>{{ $kasbon->cashAccount?->code }} - {{ $kasbon->cashAccount?->name ?: '—' }}</dd>
            <dt class="text-gray-500">Sisa Terhutang</dt><dd class="font-semibold text-red-700">Rp {{ number_format($kasbon->sisa_terhutang, 0, ',', '.') }}</dd>
            @if($kasbon->journal_id)
                <dt class="text-gray-500">Jurnal</dt><dd class="font-mono text-xs">{{ $kasbon->journal->journal_number ?? '#' . $kasbon->journal_id }}</dd>
            @endif
            @if($kasbon->void_reason)
                <dt class="text-gray-500">Alasan Void</dt><dd>{{ $kasbon->void_reason }}</dd>
            @endif
            @if($kasbon->notes)
                <dt class="text-gray-500">Catatan</dt><dd>{{ $kasbon->notes }}</dd>
            @endif
        </dl>
    </div>

    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="font-semibold mb-3 border-b pb-2 flex items-center justify-between">
            <span>Riwayat Pembayaran</span>
            @if($kasbon->isPosted() && $kasbon->sisa_terhutang > 0)
                <a href="{{ route('sdm.kasbon-pembayaran.create', ['kasbon_id' => $kasbon->id]) }}" class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-0.5 rounded">+ Bayar Manual</a>
            @endif
        </h2>
        @if($kasbon->pembayaran->isEmpty())
            <p class="text-gray-500 text-xs">Belum ada pembayaran.</p>
        @else
            <table class="w-full text-xs">
                <thead>
                    <tr><th class="text-left">Tanggal</th><th>Sumber</th><th class="text-right">Jumlah</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($kasbon->pembayaran as $p)
                        <tr>
                            <td><a href="{{ route('sdm.kasbon-pembayaran.show', $p->id) }}" class="text-blue-600">{{ $p->tanggal_bayar?->format('d/m/Y') }}</a></td>
                            <td><span class="text-xs px-1 py-0.5 rounded {{ $p->source === 'gaji' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700' }}">{{ $p->source }}</span></td>
                            <td class="text-right">{{ number_format($p->jumlah, 0, ',', '.') }}</td>
                            <td><span class="text-xs px-1 py-0.5 rounded {{ $p->status === 'posted' ? 'bg-blue-100 text-blue-700' : ($p->status === 'void' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700') }}">{{ $p->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
