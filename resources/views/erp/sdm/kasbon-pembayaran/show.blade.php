@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">
        Pelunasan Kasbon <span class="font-mono text-sm text-gray-500">{{ $kp->code }}</span>
        @php
            $badge = [
                'draft'  => 'bg-gray-200 text-gray-700',
                'posted' => 'bg-blue-100 text-blue-700',
                'void'   => 'bg-red-100 text-red-700',
            ][$kp->status] ?? 'bg-gray-200 text-gray-700';
        @endphp
        <span class="ml-2 text-xs px-2 py-0.5 rounded {{ $badge }}">{{ strtoupper($kp->status) }}</span>
        <span class="ml-1 text-xs px-2 py-0.5 rounded {{ $kp->source === 'gaji' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700' }}">{{ strtoupper($kp->source) }}</span>
    </h1>
    <div class="flex gap-2 text-sm">
        @if($kp->canBeEdited())
            <a href="{{ route('sdm.kasbon-pembayaran.edit', $kp->id) }}" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded">Edit</a>
        @endif
        @if($kp->canBePosted())
            <form method="POST" action="{{ route('sdm.kasbon-pembayaran.post', $kp->id) }}" onsubmit="return confirm('Posting pelunasan ini?');">
                @csrf
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded">Post</button>
            </form>
        @endif
        @if($kp->canBeVoided())
            <form method="POST" action="{{ route('sdm.kasbon-pembayaran.void', $kp->id) }}" onsubmit="return confirm('Void pelunasan ini? Sisa kasbon akan dikembalikan.');">
                @csrf
                <button class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded">Void</button>
            </form>
        @endif
        <a href="{{ route('sdm.kasbon-pembayaran.index') }}" class="px-3 py-1.5 text-gray-700">← Kembali</a>
    </div>
</div>


<div class="bg-white rounded shadow p-4 max-w-3xl text-sm">
    <dl class="grid grid-cols-2 gap-y-2">
        <dt class="text-gray-500">Kasbon</dt><dd><a href="{{ route('sdm.kasbon.show', $kp->kasbon_id) }}" class="text-blue-600 font-mono">{{ $kp->kasbon->code }}</a></dd>
        <dt class="text-gray-500">Karyawan</dt><dd>{{ $kp->kasbon->karyawan->name }} ({{ $kp->kasbon->karyawan->staf_code }})</dd>
        <dt class="text-gray-500">Tanggal Bayar</dt><dd>{{ $kp->tanggal_bayar?->format('d M Y') }}</dd>
        <dt class="text-gray-500">Jumlah</dt><dd class="font-semibold">Rp {{ number_format($kp->jumlah, 0, ',', '.') }}</dd>
        @if($kp->source === 'manual')
            <dt class="text-gray-500">Akun Penerima</dt><dd>{{ $kp->cashAccount?->code }} - {{ $kp->cashAccount?->name }}</dd>
        @else
            <dt class="text-gray-500">Slip Gaji</dt><dd><a href="{{ route('sdm.slip-gaji.show', $kp->slip_gaji_id) }}" class="text-blue-600 font-mono">{{ $kp->slip?->code }}</a></dd>
        @endif
        @if($kp->journal_id)
            <dt class="text-gray-500">Jurnal</dt><dd class="font-mono text-xs">{{ $kp->journal->journal_number ?? '#' . $kp->journal_id }}</dd>
        @endif
        @if($kp->notes)
            <dt class="text-gray-500">Catatan</dt><dd>{{ $kp->notes }}</dd>
        @endif
    </dl>
</div>
@endsection
