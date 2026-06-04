@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Preview Tutup Periode — {{ $period->year }}-{{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}</h1>
    <a href="{{ route('accounting.period.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>

@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 rounded mb-3 text-sm">
    Penyusutan aset tetap berikut akan otomatis di-post sebelum periode ditutup. Periksa dulu, lalu klik <strong>Tutup Periode</strong> untuk konfirmasi.
</div>

@if(!empty($unreconciledAccounts) && count($unreconciledAccounts) > 0)
    <div class="bg-amber-50 border border-amber-300 text-amber-800 px-3 py-2 rounded mb-3 text-sm">
        <div class="font-semibold mb-1">⚠ Akun bank/kas belum direkonsiliasi untuk periode ini:</div>
        <ul class="list-disc ml-5">
            @foreach($unreconciledAccounts as $acc)
                <li>{{ $acc->code }} — {{ $acc->name }}</li>
            @endforeach
        </ul>
        <div class="mt-1">Disarankan menyelesaikan rekonsiliasi di
            <a href="{{ route('finance.cash-bank.reconciliations.create') }}" class="underline font-semibold">Kas & Bank → Rekonsiliasi Bank</a>
            sebelum tutup periode. (Tutup tetap diperbolehkan; ini peringatan saja.)
        </div>
    </div>
@endif

<div class="bg-white rounded shadow p-4 mb-4">
    <h2 class="font-semibold mb-3">Penyusutan Aset Tetap</h2>
    @if($depreciation['count'] === 0)
        <div class="text-gray-500 text-sm">Tidak ada aset yang perlu disusutkan untuk periode ini.</div>
    @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">Kode</th>
                    <th class="px-3 py-2 text-left">Nama</th>
                    <th class="px-3 py-2 text-left">Kategori</th>
                    <th class="px-3 py-2 text-right">Cost</th>
                    <th class="px-3 py-2 text-right">Akumulasi</th>
                    <th class="px-3 py-2 text-right">Residu</th>
                    <th class="px-3 py-2 text-right">Penyusutan Bulan Ini</th>
                </tr>
            </thead>
            <tbody>
                @foreach($depreciation['rows'] as $row)
                    @php $a = $row['asset']; @endphp
                    <tr class="border-b">
                        <td class="px-3 py-2">{{ $a->asset_code }}</td>
                        <td class="px-3 py-2">{{ $a->name }}</td>
                        <td class="px-3 py-2">{{ $a->category->name ?? '-' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($a->acquisition_cost, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($a->accumulated_depreciation, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($a->salvage_value, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right font-medium">{{ number_format($row['amount'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="font-semibold bg-gray-50">
                    <td class="px-3 py-2" colspan="6">TOTAL ({{ $depreciation['count'] }} aset)</td>
                    <td class="px-3 py-2 text-right">{{ number_format($depreciation['total'], 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</div>

<div class="mt-4 flex gap-2">
    <form method="POST" action="{{ route('accounting.period.close', [$period->year, $period->month]) }}" onsubmit="return confirm('Tutup periode {{ $period->year }}-{{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}? @if($depreciation['count']>0)Penyusutan {{ $depreciation['count'] }} aset (Rp {{ number_format($depreciation['total'], 2, ',', '.') }}) akan otomatis di-post.@endif')">
        @csrf
        <button class="bg-red-600 text-white px-4 py-2 rounded text-sm">Tutup Periode @if($depreciation['count']>0)+ Post Penyusutan @endif</button>
    </form>
    <a href="{{ route('accounting.period.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>
@endsection
