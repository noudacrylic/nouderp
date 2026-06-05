@extends('layouts.erp')

@section('content')
@php
    $stCls = match($cd->status) {
        'draft'  => 'bg-yellow-100 text-yellow-700',
        'posted' => 'bg-green-100 text-green-700',
        'void'   => 'bg-red-100 text-red-700',
        default  => 'bg-gray-100 text-gray-700',
    };
    $typeLabel = ['general'=>'Umum','freight'=>'Bayar Ongkir','customer_refund'=>'Refund Pelanggan'][$cd->type] ?? $cd->type;
@endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Pengeluaran {{ $cd->number }}</h1>
        <div class="text-xs text-gray-500">{{ $typeLabel }} · {{ $cd->date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2 flex-row-reverse">
        @unless($cd->isVoid())
            <a href="{{ route('finance.cash-bank.disbursements.print', $cd->id) }}"
               class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Cetak</a>
        @endunless
        @if($cd->isDraft())
            <form method="POST" action="{{ route('finance.cash-bank.disbursements.post', $cd->id) }}" class="inline"
                  onsubmit="return confirm('Post pengeluaran {{ $cd->number }}?')">
                @csrf
                <button class="bg-green-600 text-white px-3 py-1.5 rounded text-sm">Post</button>
            </form>
            <a href="{{ route('finance.cash-bank.disbursements.edit', $cd->id) }}"
               class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Edit</a>
        @elseif($cd->isPosted() && $cd->canBeVoided())
            <form method="POST" action="{{ route('finance.cash-bank.disbursements.void', $cd->id) }}" class="inline"
                  onsubmit="return confirm('Void {{ $cd->number }}? Jurnal di-void.')">
                @csrf
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route($cd->listRouteName()) }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar</a>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <div class="grid grid-cols-3 gap-3 text-sm">
        <div><div class="text-xs text-gray-500">Status</div><span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $cd->status }}</span></div>
        <div><div class="text-xs text-gray-500">Tipe</div>{{ $typeLabel }}</div>
        <div><div class="text-xs text-gray-500">Tanggal</div>{{ $cd->date->format('d M Y') }}</div>
        <div><div class="text-xs text-gray-500">Sumber Kas/Bank</div>{{ $cd->cashAccount->code ?? '' }} — {{ $cd->cashAccount->name ?? '' }}</div>
        @if($cd->customer)
            <div><div class="text-xs text-gray-500">Pelanggan</div>{{ $cd->customer->name }}</div>
        @endif
        @if($cd->payee)
            <div><div class="text-xs text-gray-500">Penerima</div>{{ $cd->payee }}</div>
        @endif
        @if($cd->reference)
            <div><div class="text-xs text-gray-500">Referensi</div>{{ $cd->reference }}</div>
        @endif
        @if($cd->journal_id)
            <div><div class="text-xs text-gray-500">Jurnal</div>#{{ $cd->journal_id }}</div>
        @endif
    </div>
    @if($cd->notes)
        <div class="mt-3 text-xs text-gray-500">Catatan</div>
        <div class="text-sm">{{ $cd->notes }}</div>
    @endif
</div>

@php $isFreight = $cd->type === 'freight'; @endphp

@if($isFreight)
    @php
        $cd->loadMissing(['lines.salesInvoice.customer', 'lines.salesInvoice.delivery']);
        $totalTitipan = 0; $totalBayar = 0;
        foreach ($cd->lines as $l) {
            $totalTitipan += (float) ($l->salesInvoice->shipping_cost ?? 0);
            $totalBayar   += (float) $l->amount;
        }
        $selisih = $totalTitipan - $totalBayar;
    @endphp
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-xs uppercase text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">Pelanggan</th>
                    <th class="px-3 py-2 text-left">Faktur</th>
                    <th class="px-3 py-2 text-left">SJ / Resi</th>
                    <th class="px-3 py-2 text-right w-32">Titipan</th>
                    <th class="px-3 py-2 text-right w-32">Bayar Aktual</th>
                    <th class="px-3 py-2 text-right w-32">Selisih</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cd->lines as $l)
                    @php
                        $inv = $l->salesInvoice;
                        $titipan = (float) ($inv->shipping_cost ?? 0);
                        $bayar = (float) $l->amount;
                        $sel = $titipan - $bayar;
                    @endphp
                    <tr class="border-b">
                        <td class="px-3 py-2">{{ $inv->customer->name ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $inv->invoice_number ?? '-' }}</td>
                        <td class="px-3 py-2 text-xs">
                            {{ $inv->delivery->delivery_number ?? '—' }}<br>
                            <span class="text-gray-500">{{ trim(($inv->delivery->courier_name ?? '') . ' ' . ($inv->delivery->tracking_number ?? '')) ?: '—' }}</span>
                        </td>
                        <td class="px-3 py-2 text-right">{{ number_format($titipan, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($bayar, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right {{ $sel > 0 ? 'text-green-600' : ($sel < 0 ? 'text-red-600' : 'text-gray-500') }}">
                            {{ $sel > 0 ? '+' : '' }}{{ number_format($sel, 0, ',', '.') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t font-semibold bg-gray-50">
                <tr>
                    <td colspan="3" class="px-3 py-2 text-right">Total</td>
                    <td class="px-3 py-2 text-right">{{ number_format($totalTitipan, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($totalBayar, 0, ',', '.') }}<br><span class="text-xs text-gray-500 font-normal">Cr {{ $cd->cashAccount->code ?? '' }}</span></td>
                    <td class="px-3 py-2 text-right {{ $selisih > 0 ? 'text-green-700' : ($selisih < 0 ? 'text-red-700' : '') }}">
                        {{ $selisih > 0 ? '+' : '' }}{{ number_format($selisih, 0, ',', '.') }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
@else
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-3 py-2 text-left">Akun (Debit)</th>
                    <th class="px-3 py-2 text-left">Keterangan</th>
                    <th class="px-3 py-2 text-right w-32">Nominal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($cd->lines as $l)
                    <tr class="border-b">
                        <td class="px-3 py-2">{{ $l->account->code ?? '' }} — {{ $l->account->name ?? '' }}</td>
                        <td class="px-3 py-2">{{ $l->description }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($l->amount, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t font-semibold bg-gray-50">
                <tr>
                    <td colspan="2" class="px-3 py-2 text-right">Total (Cr {{ $cd->cashAccount->code ?? '' }})</td>
                    <td class="px-3 py-2 text-right">{{ number_format($cd->total, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
@endsection
