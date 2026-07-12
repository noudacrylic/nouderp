@extends('layouts.erp')

@section('content')
@php
    $stCls = match($br->status) {
        'draft'     => 'bg-yellow-100 text-yellow-700',
        'completed' => 'bg-green-100 text-green-700',
        'void'      => 'bg-red-100 text-red-700',
    };

    $refMap = function($jl) {
        $type   = $jl->reference_type ?: ($jl->journal->reference_type ?? null);
        $id     = $jl->reference_id   ?: ($jl->journal->reference_id ?? null);
        $refNum = $jl->reference_number ?: ($jl->journal->reference_number ?? null);
        $url = null; $label = 'Jurnal Umum'; $color = 'gray';
        switch ($type) {
            case 'sales_invoice':
                $label = 'Faktur Penjualan'; $color = 'blue';
                $url = $id ? route('sales.invoices.show', $id) : null; break;
            case 'purchase_invoice':
                $label = 'Faktur Pembelian'; $color = 'amber';
                $url = $id ? route('purchasing.invoices.show', $id) : null; break;
            case 'supplier_payment':
                $label = 'Bayar Pemasok'; $color = 'rose';
                $url = $id ? route('purchasing.payments.show', $id) : null; break;
            case 'cash_receipt':
                $label = 'Pemasukan Kas'; $color = 'green';
                $url = $id ? route('finance.cash-bank.receipts.show', $id) : null; break;
            case 'cash_disbursement':
                $label = 'Pengeluaran Kas'; $color = 'red';
                $url = $id ? route('finance.cash-bank.disbursements.show', $id) : null; break;
            case 'bank_transfer':
                $label = 'Transfer Bank'; $color = 'purple';
                $url = $id ? route('finance.cash-bank.transfers.show', $id) : null; break;
            case 'marketplace_settlement':
                $label = 'Rekonsiliasi Marketplace'; $color = 'indigo';
                $url = $id ? route('finance.cash-bank.settlements.show', $id) : null; break;
            case 'warranty_order':
                $label = 'Order Garansi'; $color = 'teal';
                $url = $id ? route('sales.warranty.show', $id) : null; break;
            case 'sales_advance':
                $label = 'DP Pelanggan'; $color = 'cyan'; break;
            case 'purchase_return':
                $label = 'Retur Pembelian'; $color = 'orange';
                $url = $id ? route('purchasing.returns.show', $id) : null; break;
            case 'sales_return':
                $label = 'Retur Penjualan'; $color = 'orange';
                $url = $id ? route('sales.returns.show', $id) : null; break;
            case 'fixed_asset_depreciation_period':
                $label = 'Penyusutan Aset'; $color = 'slate'; break;
            case null: case '':
                $label = 'Jurnal Umum'; $color = 'gray'; break;
            default:
                $label = ucwords(str_replace('_',' ', $type)); $color = 'gray'; break;
        }
        return ['label' => $label, 'color' => $color, 'url' => $url, 'ref_number' => $refNum];
    };
    $colorCls = fn($c) => match($c) {
        'blue'   => 'bg-blue-100 text-blue-700',
        'amber'  => 'bg-amber-100 text-amber-700',
        'rose'   => 'bg-rose-100 text-rose-700',
        'green'  => 'bg-green-100 text-green-700',
        'red'    => 'bg-red-100 text-red-700',
        'purple' => 'bg-purple-100 text-purple-700',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        'teal'   => 'bg-teal-100 text-teal-700',
        'cyan'   => 'bg-cyan-100 text-cyan-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'slate'  => 'bg-slate-100 text-slate-700',
        default  => 'bg-gray-100 text-gray-700',
    };
@endphp

<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Rekonsiliasi {{ $br->number }}</h1>
        <div class="text-xs text-gray-500">{{ $br->account->code }} — {{ $br->account->name }} · {{ $br->start_date->format('d M Y') }} – {{ $br->end_date->format('d M Y') }}</div>
    </div>
    <div class="flex gap-2 flex-row-reverse">
        @if($br->isCompleted() && $br->canBeVoided())
            <form method="POST" action="{{ route('finance.cash-bank.reconciliations.void', $br->id) }}" class="inline" onsubmit="return confirm('Void rekonsiliasi {{ $br->number }}?')">
                @csrf
                <button class="bg-red-600 text-white px-3 py-1.5 rounded text-sm">Void</button>
            </form>
        @endif
        <a href="{{ route('finance.cash-bank.reconciliations.index') }}" class="bg-gray-200 px-3 py-1.5 rounded text-sm">← Daftar</a>
    </div>
</div>

{{-- Header ringkas 1 baris --}}
<div class="bg-white rounded shadow px-3 py-2 mb-3 grid grid-cols-5 gap-3 text-sm items-end">
    <div>
        <div class="text-[11px] text-gray-500">Status</div>
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $br->status }}</span>
    </div>
    <div>
        <div class="text-[11px] text-gray-500">Saldo Awal</div>
        <div class="font-semibold">{{ number_format($br->opening_balance, 0, ',', '.') }}</div>
    </div>
    <div>
        <div class="text-[11px] text-gray-500">Saldo Buku</div>
        <div class="font-semibold">{{ number_format($br->book_balance, 0, ',', '.') }}</div>
    </div>
    <div>
        <div class="text-[11px] text-gray-500">Saldo Per Rek. Koran</div>
        <div class="font-semibold">{{ number_format($br->statement_balance, 0, ',', '.') }}</div>
    </div>
    <div>
        <div class="text-[11px] text-gray-500">Selisih</div>
        <span class="{{ abs($br->difference) > 0.01 ? 'text-red-600 font-semibold' : 'text-green-700 font-semibold' }}">{{ number_format($br->difference, 0, ',', '.') }}</span>
    </div>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Sumber / Transaksi</th>
                <th class="px-3 py-2 text-left">Keterangan</th>
                <th class="px-3 py-2 text-right">Debit (Masuk)</th>
                <th class="px-3 py-2 text-right">Kredit (Keluar)</th>
                <th class="px-3 py-2 text-right">Saldo</th>
                <th class="px-3 py-2 text-right">Cocok</th>
            </tr>
        </thead>
        <tbody>
            <tr class="border-b bg-gray-50/70 italic text-gray-500">
                <td class="px-3 py-1.5 whitespace-nowrap">{{ $br->start_date->format('d M Y') }}</td>
                <td class="px-3 py-1.5" colspan="2">Saldo awal periode</td>
                <td></td>
                <td></td>
                <td class="px-3 py-1.5 text-right font-semibold font-mono">{{ number_format($br->opening_balance, 0, ',', '.') }}</td>
                <td></td>
            </tr>
            @php $running = (float) $br->opening_balance; @endphp
            @foreach($br->lines as $line)
                @php
                    $jl = $line->journalLine;
                    $ref = $refMap($jl);
                    $running += (float)$jl->debit - (float)$jl->credit;
                @endphp
                <tr class="border-b {{ $line->is_matched ? 'bg-green-50' : '' }}">
                    <td class="px-3 py-1.5 whitespace-nowrap">{{ \Carbon\Carbon::parse($jl->journal->date)->format('d M Y') }}</td>
                    <td class="px-3 py-1.5">
                        <span class="inline-block px-1.5 py-0.5 rounded text-[10px] uppercase font-semibold {{ $colorCls($ref['color']) }}">{{ $ref['label'] }}</span>
                        @if($ref['url'])
                            <a href="{{ $ref['url'] }}" class="text-blue-600 hover:underline ml-1 font-mono text-xs" target="_blank" rel="noopener">
                                {{ $ref['ref_number'] ?: ($jl->journal->journal_number ?? '#'.$jl->journal_id) }}
                            </a>
                        @else
                            <span class="ml-1 font-mono text-xs text-gray-700">{{ $ref['ref_number'] ?: ($jl->journal->journal_number ?? '#'.$jl->journal_id) }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-1.5 text-gray-700">{{ $jl->description ?: ($jl->journal->description ?? '-') }}</td>
                    <td class="px-3 py-1.5 text-right">{{ (float)$jl->debit > 0 ? number_format($jl->debit, 0, ',', '.') : '' }}</td>
                    <td class="px-3 py-1.5 text-right">{{ (float)$jl->credit > 0 ? number_format($jl->credit, 0, ',', '.') : '' }}</td>
                    <td class="px-3 py-1.5 text-right font-mono">{{ number_format($running, 0, ',', '.') }}</td>
                    <td class="px-3 py-1.5 text-right">
                        @if($line->is_matched)
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-green-600 text-white">✓ Cocok</span>
                        @else
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-200 text-gray-600">Belum</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            <tr class="border-t bg-gray-50/70 italic text-gray-600">
                <td class="px-3 py-1.5 whitespace-nowrap">{{ $br->end_date->format('d M Y') }}</td>
                <td class="px-3 py-1.5" colspan="2">Saldo akhir buku</td>
                <td></td>
                <td></td>
                <td class="px-3 py-1.5 text-right font-semibold font-mono">{{ number_format($running, 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</div>

@if($br->notes)
<div class="bg-white rounded shadow p-3 mt-3">
    <div class="text-xs text-gray-500 mb-1">Catatan</div>
    <div class="text-sm whitespace-pre-line">{{ $br->notes }}</div>
</div>
@endif
@endsection
