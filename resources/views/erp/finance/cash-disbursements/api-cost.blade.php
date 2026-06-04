@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Biaya API Biteship</h1>
    <a href="https://dashboard.biteship.com/billing?tab=api-transactions" target="_blank"
       class="text-sm text-blue-600 underline">Buka dashboard Biteship → Transaksi API ↗</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('info'))
    <div class="bg-blue-50 border border-blue-200 text-blue-700 px-3 py-2 rounded text-sm mb-3">{{ session('info') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

{{-- Upload Excel --}}
<div class="bg-white rounded shadow border p-5 mb-4">
    <h2 class="font-bold text-gray-800 mb-1">Upload Laporan Bulanan</h2>
    <p class="text-sm text-gray-500 mb-3">
        Di dashboard Biteship → <b>Transaksi API</b> → klik <b>Export</b>, lalu upload file-nya di sini.
        Tiap tanggal otomatis jadi <b>Pengeluaran Umum</b>: Dr <b>Beban API Biteship</b> / Cr <b>Saldo Biteship</b>.
        Aman di-upload ulang — tanggal yang sudah tercatat dilewati (tidak dobel).
    </p>
    <form method="POST" action="{{ route('finance.cash-bank.disbursements.api-cost-import') }}"
          enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">File Excel (.xlsx/.xls/.csv)</label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="border rounded px-3 py-2 text-sm w-80">
        </div>
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2 rounded font-semibold">Upload & Catat</button>
    </form>
    <p class="text-[11px] text-gray-400 mt-2">Kolom yang dibaca: <code>date</code> (tanggal) &amp; <code>total_amount</code> (nominal total hari itu). Kolom rates/tracking diabaikan.</p>
</div>

{{-- Daftar tercatat --}}
<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Dok</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-right">Biaya API</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $cd)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-3 py-2 font-medium">{{ $cd->number }}</td>
                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($cd->date)->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">Rp {{ number_format($cd->total, 0, ',', '.') }}</td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs {{ $cd->status === 'posted' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ ucfirst($cd->status) }}</span>
                    </td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('finance.cash-bank.disbursements.show', $cd->id) }}" class="text-blue-600 text-xs underline">Lihat</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-8 text-center text-gray-400">Belum ada biaya API tercatat. Upload laporan Biteship di atas.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $items->links() }}</div>
@endsection
