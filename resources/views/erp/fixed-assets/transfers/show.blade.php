@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Transfer {{ $transfer->transfer_number }}</h1>
        <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-600">{{ $transfer->status }}</span>
    </div>
    <div class="flex gap-1 flex-row-reverse">
        @if($transfer->canBeVoided())
            <form method="POST" action="{{ route('fixed-assets.transfers.void', $transfer->id) }}" onsubmit="return confirm('Void transfer ini?')">@csrf
                <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Void</button>
            </form>
        @endif
        @if($transfer->status === 'draft')
            <form method="POST" action="{{ route('fixed-assets.transfers.post', $transfer->id) }}" onsubmit="return confirm('Post transfer?')">@csrf
                <button class="bg-green-600 text-white px-3 py-2 rounded text-sm">Post</button>
            </form>
            <a href="{{ route('fixed-assets.transfers.edit', $transfer->id) }}" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Edit</a>
        @endif
        <a href="{{ route('fixed-assets.transfers.index') }}" class="text-gray-500 text-sm underline self-center">← Kembali</a>
    </div>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<div class="bg-white rounded shadow p-4">
    <table class="w-full text-sm">
        <tbody>
            <tr><td class="py-1 text-gray-500 w-48">Aset</td><td class="py-1"><a href="{{ route('fixed-assets.show', $transfer->fixed_asset_id) }}" class="text-blue-600 underline">{{ $transfer->asset->asset_code ?? '-' }} — {{ $transfer->asset->name ?? '-' }}</a></td></tr>
            <tr><td class="py-1 text-gray-500">Tanggal</td><td class="py-1">{{ optional($transfer->transfer_date)->format('d M Y') }}</td></tr>
            <tr><td class="py-1 text-gray-500">Dari Gudang</td><td class="py-1">{{ $transfer->fromWarehouse->name ?? '-' }}</td></tr>
            <tr><td class="py-1 text-gray-500">Ke Gudang</td><td class="py-1">{{ $transfer->toWarehouse->name ?? '-' }}</td></tr>
            @if($transfer->notes) <tr><td class="py-1 text-gray-500 align-top">Catatan</td><td class="py-1 whitespace-pre-line">{{ $transfer->notes }}</td></tr> @endif
            @if($transfer->posted_at) <tr><td class="py-1 text-gray-500">Diposting</td><td class="py-1">{{ $transfer->posted_at->format('d M Y H:i') }}</td></tr> @endif
            @if($transfer->voided_at) <tr><td class="py-1 text-gray-500">Voided</td><td class="py-1">{{ $transfer->voided_at->format('d M Y H:i') }}</td></tr> @endif
        </tbody>
    </table>
</div>
@endsection
