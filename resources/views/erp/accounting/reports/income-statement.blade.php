@extends('layouts.erp')

@php
    // Format angka: negatif → (kurung) merah, positif → biasa.
    $fmt = fn ($n) => $n < 0
        ? '(' . number_format(abs($n), 0, ',', '.') . ')'
        : number_format($n, 0, ',', '.');
@endphp

@section('content')
<div class="flex items-center justify-between mb-4 gap-3">
    <div>
        <h1 class="text-lg font-semibold">Laporan Laba Rugi</h1>
        <div class="text-xs text-gray-500 mt-0.5">
            Periode <b>{{ $dateFrom->format('d M Y') }}</b> s/d <b>{{ $dateTo->format('d M Y') }}</b>
        </div>
    </div>
    <div class="flex gap-2 items-center print:hidden">
        <button onclick="window.print()" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Cetak</button>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap print:hidden">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari Tanggal</label>
        <input type="date" name="date_from" value="{{ $dateFrom->format('Y-m-d') }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">s/d</label>
        <input type="date" name="date_to" value="{{ $dateTo->format('Y-m-d') }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
</form>

<div class="bg-white rounded shadow">
    <table class="w-full text-sm">
        <tbody>
            {{-- ───────── PENDAPATAN ───────── --}}
            <tr class="bg-green-50">
                <td colspan="3" class="px-3 py-2 font-semibold uppercase tracking-wide text-green-900">Pendapatan</td>
            </tr>
            @forelse($operatingRevenue as $a)
                @include('erp.accounting.reports._is_row', ['a' => $a])
            @empty
                <tr><td colspan="3" class="px-3 py-3 text-center text-gray-400 text-xs italic">Belum ada pendapatan di periode ini</td></tr>
            @endforelse
            <tr class="border-b-2 border-gray-300 font-semibold">
                <td colspan="2" class="px-3 py-2 text-right pl-8">Jumlah Pendapatan</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($totalRevenue) }}</td>
            </tr>

            {{-- ───────── BEBAN POKOK PENJUALAN ───────── --}}
            <tr class="bg-rose-50">
                <td colspan="3" class="px-3 py-2 font-semibold uppercase tracking-wide text-rose-900">Beban Pokok Penjualan</td>
            </tr>
            @forelse($cogs as $a)
                @include('erp.accounting.reports._is_row', ['a' => $a])
            @empty
                <tr><td colspan="3" class="px-3 py-3 text-center text-gray-400 text-xs italic">—</td></tr>
            @endforelse
            <tr class="border-b-2 border-gray-300 font-semibold">
                <td colspan="2" class="px-3 py-2 text-right pl-8">Jumlah Beban Pokok Penjualan</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($totalCogs) }}</td>
            </tr>

            {{-- ───────── LABA KOTOR ───────── --}}
            <tr class="border-y-2 border-gray-400 font-bold bg-gray-50">
                <td colspan="2" class="px-3 py-2.5">LABA KOTOR</td>
                <td class="px-3 py-2.5 text-right tabular-nums {{ $grossProfit < 0 ? 'text-red-600' : '' }}">{{ $fmt($grossProfit) }}</td>
            </tr>

            {{-- ───────── BEBAN OPERASIONAL ───────── --}}
            <tr class="bg-rose-50">
                <td colspan="3" class="px-3 py-2 font-semibold uppercase tracking-wide text-rose-900">Beban Operasional</td>
            </tr>
            @forelse($opex as $a)
                @include('erp.accounting.reports._is_row', ['a' => $a])
            @empty
                <tr><td colspan="3" class="px-3 py-3 text-center text-gray-400 text-xs italic">—</td></tr>
            @endforelse
            <tr class="border-b-2 border-gray-300 font-semibold">
                <td colspan="2" class="px-3 py-2 text-right pl-8">Jumlah Beban Operasional</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($totalOpex) }}</td>
            </tr>

            {{-- ───────── LABA OPERASIONAL ───────── --}}
            <tr class="border-y-2 border-gray-400 font-bold bg-gray-50">
                <td colspan="2" class="px-3 py-2.5">PENDAPATAN OPERASIONAL</td>
                <td class="px-3 py-2.5 text-right tabular-nums {{ $operatingIncome < 0 ? 'text-red-600' : '' }}">{{ $fmt($operatingIncome) }}</td>
            </tr>

            {{-- ───────── NON-OPERASIONAL ───────── --}}
            <tr class="bg-amber-50">
                <td colspan="3" class="px-3 py-2 font-semibold uppercase tracking-wide text-amber-900">Pendapatan dan Beban Non-Operasional</td>
            </tr>

            <tr><td colspan="3" class="px-3 pt-2 pb-1 font-medium text-gray-600 pl-6">Pendapatan Non-Operasional</td></tr>
            @forelse($nonopIncome as $a)
                @include('erp.accounting.reports._is_row', ['a' => $a])
            @empty
                <tr><td colspan="3" class="px-3 py-2 text-center text-gray-400 text-xs italic">—</td></tr>
            @endforelse
            <tr class="border-b font-medium">
                <td colspan="2" class="px-3 py-1.5 text-right pl-8 text-gray-600">Jumlah Pendapatan Non-Operasional</td>
                <td class="px-3 py-1.5 text-right tabular-nums">{{ $fmt($totalNonopInc) }}</td>
            </tr>

            <tr><td colspan="3" class="px-3 pt-2 pb-1 font-medium text-gray-600 pl-6">Beban Non-Operasional</td></tr>
            @forelse($nonopExpense as $a)
                @include('erp.accounting.reports._is_row', ['a' => $a])
            @empty
                <tr><td colspan="3" class="px-3 py-2 text-center text-gray-400 text-xs italic">—</td></tr>
            @endforelse
            <tr class="border-b font-medium">
                <td colspan="2" class="px-3 py-1.5 text-right pl-8 text-gray-600">Jumlah Beban Non-Operasional</td>
                <td class="px-3 py-1.5 text-right tabular-nums">{{ $fmt($totalNonopExp) }}</td>
            </tr>

            <tr class="border-b-2 border-gray-300 font-semibold">
                <td colspan="2" class="px-3 py-2 text-right pl-8">Jumlah Pendapatan dan Beban Non-Operasional</td>
                <td class="px-3 py-2 text-right tabular-nums {{ $nonopNet < 0 ? 'text-red-600' : '' }}">{{ $fmt($nonopNet) }}</td>
            </tr>

            {{-- ───────── LABA BERSIH ───────── --}}
            <tr class="border-t-4 border-double border-gray-500 font-bold {{ $netIncome >= 0 ? 'bg-blue-50' : 'bg-red-50' }}">
                <td colspan="2" class="px-3 py-3 text-base {{ $netIncome >= 0 ? 'text-blue-900' : 'text-red-900' }}">LABA / (RUGI) BERSIH</td>
                <td class="px-3 py-3 text-right tabular-nums text-base {{ $netIncome >= 0 ? 'text-blue-900' : 'text-red-900' }}">{{ $fmt($netIncome) }}</td>
            </tr>
        </tbody>
    </table>
</div>

<style>
@media print {
    body { background: white !important; }
    .sidebar, header, .print\\:hidden { display: none !important; }
    .wrapper { display: block !important; }
    .main, main, .content { margin: 0 !important; padding: 1rem !important; }
}
</style>

@include('erp.purchasing._partials.list-scripts')
@endsection
