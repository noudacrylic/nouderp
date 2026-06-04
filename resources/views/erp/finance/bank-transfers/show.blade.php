@extends('layouts.erp')

@section('content')
@php
    $stCls = match($bt->status) {
        'draft'  => 'bg-yellow-100 text-yellow-700',
        'posted' => 'bg-green-100 text-green-700',
        'void'   => 'bg-red-100 text-red-700',
        default  => 'bg-gray-100 text-gray-700',
    };
    $borneBy   = $bt->fee_borne_by ?: 'source';
    $borneLabel = $borneBy === 'destination' ? 'Tujuan (Ke)' : 'Sumber (Dari)';
    $amount    = (float) $bt->amount;
    $fee       = (float) $bt->admin_fee;
    $fromOut   = $borneBy === 'destination' ? $amount : $amount + $fee;
    $toIn      = $borneBy === 'destination' ? $amount - $fee : $amount;
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Transfer Bank {{ $bt->number }}</h1>
        <div class="text-xs text-gray-500">{{ $bt->date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2 flex-row-reverse">
        @unless($bt->isVoid())
            <a href="{{ route('finance.cash-bank.transfers.print', $bt->id) }}" class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Print</a>
        @endunless
        @if($bt->isDraft())
            <form method="POST" action="{{ route('finance.cash-bank.transfers.post', $bt->id) }}" class="inline" onsubmit="return confirm('Post {{ $bt->number }}?')">
                @csrf
                <button class="bg-green-600 text-white px-3 py-1.5 rounded text-sm">Post</button>
            </form>
            <a href="{{ route('finance.cash-bank.transfers.edit', $bt->id) }}" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        @elseif($bt->isPosted() && $bt->canBeVoided())
            <form method="POST" action="{{ route('finance.cash-bank.transfers.void', $bt->id) }}" class="inline" onsubmit="return confirm('Void {{ $bt->number }}?')">
                @csrf
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route('finance.cash-bank.transfers.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← List</a>
    </div>
</div>

<div class="bg-white rounded shadow p-4">
    <div class="grid grid-cols-3 gap-3 text-sm">
        <div><div class="text-xs text-gray-500">Status</div><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $bt->status }}</span></div>
        <div><div class="text-xs text-gray-500">Tanggal</div>{{ $bt->date->format('d M Y') }}</div>
        <div><div class="text-xs text-gray-500">Journal</div>{{ $bt->journal_id ? '#'.$bt->journal_id : '-' }}</div>
        <div><div class="text-xs text-gray-500">Dari Akun</div>{{ $bt->fromAccount->code ?? '' }} — {{ $bt->fromAccount->name ?? '' }}</div>
        <div><div class="text-xs text-gray-500">Ke Akun</div>{{ $bt->toAccount->code ?? '' }} — {{ $bt->toAccount->name ?? '' }}</div>
        <div><div class="text-xs text-gray-500">Akun Beban Admin</div>{{ $bt->adminFeeAccount ? ($bt->adminFeeAccount->code.' — '.$bt->adminFeeAccount->name) : '-' }}</div>
        <div><div class="text-xs text-gray-500">Jumlah Transfer</div>{{ number_format($amount, 0, ',', '.') }}</div>
        <div><div class="text-xs text-gray-500">Biaya Admin</div>{{ number_format($fee, 0, ',', '.') }}</div>
        <div><div class="text-xs text-gray-500">Biaya Ditanggung</div>{{ $borneLabel }}</div>
        @if($bt->reference)<div class="col-span-3"><div class="text-xs text-gray-500">Referensi</div>{{ $bt->reference }}</div>@endif
    </div>
    @if($bt->notes)
        <div class="mt-3 text-xs text-gray-500">Catatan</div>
        <div class="text-sm">{{ $bt->notes }}</div>
    @endif
</div>

<div class="bg-gray-50 rounded shadow border p-4 mt-4">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Ringkasan Pergerakan</h3>
    <div class="grid grid-cols-3 gap-4 text-sm">
        <div>
            <div class="text-xs text-rose-700">{{ $bt->fromAccount->code ?? '' }} {{ $bt->fromAccount->name ?? '' }} berkurang</div>
            <div class="text-lg font-bold text-rose-700">Rp {{ number_format($fromOut, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-xs text-emerald-700">{{ $bt->toAccount->code ?? '' }} {{ $bt->toAccount->name ?? '' }} bertambah</div>
            <div class="text-lg font-bold text-emerald-700">Rp {{ number_format($toIn, 0, ',', '.') }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-600">Beban Admin Bank</div>
            <div class="text-lg font-bold text-gray-700">Rp {{ number_format($fee, 0, ',', '.') }}</div>
        </div>
    </div>
</div>
@endsection
