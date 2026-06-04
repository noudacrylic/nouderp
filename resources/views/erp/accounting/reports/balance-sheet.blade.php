@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4 gap-3">
    <div>
        <h1 class="text-lg font-semibold">Neraca</h1>
        <div class="text-xs text-gray-500 mt-0.5">Posisi keuangan per <b>{{ $asOf->format('d M Y') }}</b></div>
    </div>
    <div class="flex gap-2 items-center print:hidden">
        <button onclick="window.print()" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Cetak</button>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap print:hidden">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Per Tanggal</label>
        <input type="date" name="as_of" value="{{ $asOf->format('Y-m-d') }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    <div class="text-xs text-gray-400 self-end pb-2 italic">Klik baris akun untuk lihat ledger.</div>
</form>

@if(abs($diff) > 0.01)
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-3 text-sm">
        ⚠ Selisih neraca: Rp {{ number_format($diff, 0, ',', '.') }} — kemungkinan ada jurnal tidak balanced.
    </div>
@endif

@php
    /** Render saldo: negatif → kurung + merah */
    $renderBal = function($n) {
        $cls = $n < 0 ? 'text-red-600' : '';
        $val = $n < 0 ? '(' . number_format(abs($n), 0, ',', '.') . ')' : number_format($n, 0, ',', '.');
        return ['cls' => $cls, 'val' => $val];
    };
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    {{-- ============ AKTIVA ============ --}}
    <div class="bg-white rounded shadow">
        <div class="px-3 py-2 border-b bg-blue-50">
            <h2 class="font-semibold text-sm uppercase tracking-wide text-blue-900">Aktiva</h2>
        </div>
        <table class="w-full text-sm">
            <tbody>
                @forelse($assetGroups as $g)
                    {{-- Section header (Aset Lancar / Aset Tetap) --}}
                    <tr class="bg-gray-100">
                        <td colspan="3" class="px-3 py-1.5 font-bold text-xs uppercase tracking-wide text-gray-700">{{ $g['label'] }}</td>
                    </tr>

                    @foreach($g['subgroups'] as $sg)
                        @if($sg['label'])
                            <tr class="bg-gray-50">
                                <td colspan="3" class="px-3 py-1 pl-6 font-semibold text-xs text-gray-600">{{ $sg['label'] }}</td>
                            </tr>
                        @endif
                        @foreach($sg['accounts'] as $a)
                            @php $b = $renderBal($a->balance); @endphp
                            <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
                                <td class="px-3 py-1.5 text-gray-500 w-16 pl-9">{{ $a->code }}</td>
                                <td class="px-3 py-1.5">{{ $a->name }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                            </tr>
                        @endforeach
                        @if($sg['label'])
                            @php $b = $renderBal($sg['subtotal']); @endphp
                            <tr class="border-b text-xs italic">
                                <td colspan="2" class="px-3 py-1 text-right text-gray-500 pl-6">Subtotal {{ $sg['label'] }}</td>
                                <td class="px-3 py-1 text-right tabular-nums text-gray-600">{{ $b['val'] }}</td>
                            </tr>
                        @endif
                    @endforeach

                    @php $b = $renderBal($g['subtotal']); @endphp
                    <tr class="border-b font-semibold bg-gray-50">
                        <td colspan="2" class="px-3 py-1.5 text-right text-gray-800">Jumlah {{ $g['label'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400 text-xs italic">Belum ada saldo</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                @php $b = $renderBal($totalAsset); @endphp
                <tr class="bg-blue-50 border-t-2 border-blue-300 font-bold">
                    <td colspan="2" class="px-3 py-2 text-blue-900">TOTAL AKTIVA</td>
                    <td class="px-3 py-2 text-right tabular-nums text-blue-900">{{ $b['val'] }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- ============ KEWAJIBAN & EKUITAS ============ --}}
    <div class="bg-white rounded shadow">
        <div class="px-3 py-2 border-b bg-amber-50">
            <h2 class="font-semibold text-sm uppercase tracking-wide text-amber-900">Kewajiban & Ekuitas</h2>
        </div>
        <table class="w-full text-sm">
            <tbody>
                {{-- KEWAJIBAN --}}
                @if(!empty($liabilityGroups))
                    @foreach($liabilityGroups as $g)
                        <tr class="bg-gray-100">
                            <td colspan="3" class="px-3 py-1.5 font-bold text-xs uppercase tracking-wide text-gray-700">{{ $g['label'] }}</td>
                        </tr>
                        @foreach($g['subgroups'] as $sg)
                            @foreach($sg['accounts'] as $a)
                                @php $b = $renderBal($a->balance); @endphp
                                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
                                    <td class="px-3 py-1.5 text-gray-500 w-16 pl-6">{{ $a->code }}</td>
                                    <td class="px-3 py-1.5">{{ $a->name }}</td>
                                    <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        @php $b = $renderBal($g['subtotal']); @endphp
                        <tr class="border-b font-semibold bg-gray-50">
                            <td colspan="2" class="px-3 py-1.5 text-right text-gray-800">Jumlah {{ $g['label'] }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                        </tr>
                    @endforeach
                    @php $b = $renderBal($totalLiability); @endphp
                    <tr class="border-b font-bold bg-amber-50/50">
                        <td colspan="2" class="px-3 py-1.5 text-right text-amber-900">TOTAL KEWAJIBAN</td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-amber-900">{{ $b['val'] }}</td>
                    </tr>
                @endif

                {{-- EKUITAS --}}
                @foreach($equityGroups as $g)
                    <tr class="bg-gray-100">
                        <td colspan="3" class="px-3 py-1.5 font-bold text-xs uppercase tracking-wide text-gray-700">{{ $g['label'] }}</td>
                    </tr>
                    @foreach($g['subgroups'] as $sg)
                        @foreach($sg['accounts'] as $a)
                            @php $b = $renderBal($a->balance); @endphp
                            <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
                                <td class="px-3 py-1.5 text-gray-500 w-16 pl-6">{{ $a->code }}</td>
                                <td class="px-3 py-1.5">{{ $a->name }}</td>
                                <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach

                @php $b = $renderBal($netIncomeYtd); @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounting.reports.income-statement') }}">
                    <td class="px-3 py-1.5 text-gray-500 w-16 pl-6">—</td>
                    <td class="px-3 py-1.5 italic" title="Pendapatan − Beban yang belum di-close ke Laba Ditahan. Klik untuk lihat Laba Rugi.">Laba/Rugi Berjalan (Belum Closed)</td>
                    <td class="px-3 py-1.5 text-right tabular-nums {{ $b['cls'] }}">{{ $b['val'] }}</td>
                </tr>

                @php $b = $renderBal($totalEquity + $netIncomeYtd); @endphp
                <tr class="border-b font-bold bg-amber-50/50">
                    <td colspan="2" class="px-3 py-1.5 text-right text-amber-900">TOTAL EKUITAS</td>
                    <td class="px-3 py-1.5 text-right tabular-nums text-amber-900">{{ $b['val'] }}</td>
                </tr>
            </tbody>
            <tfoot>
                @php $b = $renderBal($totalLiabEqIncome); @endphp
                <tr class="bg-amber-50 border-t-2 border-amber-300 font-bold">
                    <td colspan="2" class="px-3 py-2 text-amber-900">TOTAL KEWAJIBAN & EKUITAS</td>
                    <td class="px-3 py-2 text-right tabular-nums text-amber-900">{{ $b['val'] }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
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
