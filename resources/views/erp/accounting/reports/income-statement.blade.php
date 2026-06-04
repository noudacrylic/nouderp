@extends('layouts.erp')

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
            {{-- REVENUE --}}
            <tr class="bg-green-50">
                <td colspan="3" class="px-3 py-2 font-semibold text-sm uppercase tracking-wide text-green-900">Pendapatan</td>
            </tr>
            @forelse($revenues as $a)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
                    <td class="px-3 py-1.5 text-gray-500 w-16 pl-6">{{ $a->code }}</td>
                    <td class="px-3 py-1.5">{{ $a->name }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums {{ $a->balance < 0 ? 'text-red-600' : '' }}">
                        @if($a->balance < 0)
                            ({{ number_format(abs($a->balance), 0, ',', '.') }})
                        @else
                            {{ number_format($a->balance, 0, ',', '.') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400 text-xs italic">Belum ada pendapatan di periode ini</td></tr>
            @endforelse
            <tr class="border-b font-medium bg-green-50">
                <td colspan="2" class="px-3 py-2 text-right text-green-900">TOTAL PENDAPATAN</td>
                <td class="px-3 py-2 text-right tabular-nums text-green-900">{{ number_format($totalRevenue, 0, ',', '.') }}</td>
            </tr>

            {{-- EXPENSE --}}
            <tr class="bg-rose-50">
                <td colspan="3" class="px-3 py-2 font-semibold text-sm uppercase tracking-wide text-rose-900">Beban</td>
            </tr>
            @forelse($expenses as $a)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('accounts.show', $a->id) }}">
                    <td class="px-3 py-1.5 text-gray-500 w-16 pl-6">{{ $a->code }}</td>
                    <td class="px-3 py-1.5">{{ $a->name }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums {{ $a->balance < 0 ? 'text-red-600' : '' }}">
                        @if($a->balance < 0)
                            ({{ number_format(abs($a->balance), 0, ',', '.') }})
                        @else
                            {{ number_format($a->balance, 0, ',', '.') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400 text-xs italic">Belum ada beban di periode ini</td></tr>
            @endforelse
            <tr class="border-b font-medium bg-rose-50">
                <td colspan="2" class="px-3 py-2 text-right text-rose-900">TOTAL BEBAN</td>
                <td class="px-3 py-2 text-right tabular-nums text-rose-900">{{ number_format($totalExpense, 0, ',', '.') }}</td>
            </tr>

            {{-- NET INCOME --}}
            <tr class="border-t-2 border-gray-400 font-bold {{ $netIncome >= 0 ? 'bg-blue-50' : 'bg-red-50' }}">
                <td colspan="2" class="px-3 py-3 {{ $netIncome >= 0 ? 'text-blue-900' : 'text-red-900' }}">
                    LABA / (RUGI) BERSIH
                </td>
                <td class="px-3 py-3 text-right tabular-nums text-base {{ $netIncome >= 0 ? 'text-blue-900' : 'text-red-900' }}">
                    {{ number_format($netIncome, 0, ',', '.') }}
                </td>
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
