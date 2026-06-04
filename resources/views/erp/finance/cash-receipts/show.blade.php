@extends('layouts.erp')

@section('content')
@php
    $stCls = match($cr->status) {
        'draft'  => 'bg-yellow-100 text-yellow-700',
        'posted' => 'bg-green-100 text-green-700',
        'void'   => 'bg-red-100 text-red-700',
        default  => 'bg-gray-100 text-gray-700',
    };
    $typeLabel = ['general'=>'Umum','supplier_refund'=>'Refund Supplier'][$cr->type] ?? $cr->type;
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Pemasukan {{ $cr->number }}</h1>
        <div class="text-xs text-gray-500">{{ $typeLabel }} · {{ $cr->date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2 flex-row-reverse">
        @unless($cr->isVoid())
            <a href="{{ route('finance.cash-bank.receipts.print', $cr->id) }}"
               class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Print</a>
        @endunless
        @if($cr->isDraft())
            <form method="POST" action="{{ route('finance.cash-bank.receipts.post', $cr->id) }}" class="inline"
                  onsubmit="return confirm('Post {{ $cr->number }}?')">
                @csrf
                <button class="bg-green-600 text-white px-3 py-1.5 rounded text-sm">Post</button>
            </form>
            <a href="{{ route('finance.cash-bank.receipts.edit', $cr->id) }}"
               class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        @elseif($cr->isPosted() && $cr->canBeVoided())
            <form method="POST" action="{{ route('finance.cash-bank.receipts.void', $cr->id) }}" class="inline"
                  onsubmit="return confirm('Void {{ $cr->number }}?')">
                @csrf
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route('finance.cash-bank.receipts.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← List</a>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <div class="grid grid-cols-3 gap-3 text-sm">
        <div><div class="text-xs text-gray-500">Status</div><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $cr->status }}</span></div>
        <div><div class="text-xs text-gray-500">Tipe</div>{{ $typeLabel }}</div>
        <div><div class="text-xs text-gray-500">Tanggal</div>{{ $cr->date->format('d M Y') }}</div>
        <div><div class="text-xs text-gray-500">Tujuan Kas/Bank</div>{{ $cr->cashAccount->code ?? '' }} — {{ $cr->cashAccount->name ?? '' }}</div>
        @if($cr->supplier)<div><div class="text-xs text-gray-500">Supplier</div>{{ $cr->supplier->name }}</div>@endif
        @if($cr->payer)<div><div class="text-xs text-gray-500">Pembayar</div>{{ $cr->payer }}</div>@endif
        @if($cr->reference)<div><div class="text-xs text-gray-500">Referensi</div>{{ $cr->reference }}</div>@endif
        @if($cr->journal_id)<div><div class="text-xs text-gray-500">Journal</div>#{{ $cr->journal_id }}</div>@endif
    </div>
    @if($cr->notes)
        <div class="mt-3 text-xs text-gray-500">Catatan</div>
        <div class="text-sm">{{ $cr->notes }}</div>
    @endif
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="px-3 py-2 text-left">Akun (Kredit)</th>
                <th class="px-3 py-2 text-left">Keterangan</th>
                <th class="px-3 py-2 text-right w-32">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cr->lines as $l)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $l->account->code ?? '' }} — {{ $l->account->name ?? '' }}</td>
                    <td class="px-3 py-2">{{ $l->description }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($l->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t font-semibold bg-gray-50">
            <tr>
                <td colspan="2" class="px-3 py-2 text-right">Total (Dr {{ $cr->cashAccount->code ?? '' }})</td>
                <td class="px-3 py-2 text-right">{{ number_format($cr->total, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
