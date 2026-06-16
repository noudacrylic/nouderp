@extends('layouts.erp')

@section('content')
<div class="max-w-5xl mx-auto">
    <a href="{{ route('settings.jubelio.edit') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Pengaturan Jubelio</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Riwayat Sinkron Jubelio</h2>
                <p class="text-sm text-gray-500">Jejak per-kejadian sinkronisasi Pesanan, Stok, dan Harga ke/ dari Jubelio.</p>
            </div>
        </div>

        {{-- Ringkasan status saat ini --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="border rounded-lg p-3 bg-gray-50">
                <div class="text-xs text-gray-500">Produk tersinkron</div>
                <div class="text-lg font-bold text-gray-800">{{ number_format($summary['synced_total']) }}</div>
            </div>
            <div class="border rounded-lg p-3 {{ $summary['stock_pending'] ? 'bg-sky-50 border-sky-200' : 'bg-gray-50' }}">
                <div class="text-xs text-gray-500">Stok menunggu kirim</div>
                <div class="text-lg font-bold {{ $summary['stock_pending'] ? 'text-sky-700' : 'text-gray-800' }}">{{ number_format($summary['stock_pending']) }}</div>
            </div>
            <div class="border rounded-lg p-3 {{ $summary['price_pending'] ? 'bg-amber-50 border-amber-200' : 'bg-gray-50' }}">
                <div class="text-xs text-gray-500">Harga menunggu kirim</div>
                <div class="text-lg font-bold {{ $summary['price_pending'] ? 'text-amber-700' : 'text-gray-800' }}">{{ number_format($summary['price_pending']) }}</div>
            </div>
            <div class="border rounded-lg p-3 {{ $summary['failed_24h'] ? 'bg-red-50 border-red-200' : 'bg-gray-50' }}">
                <div class="text-xs text-gray-500">Gagal (24 jam)</div>
                <div class="text-lg font-bold {{ $summary['failed_24h'] ? 'text-red-700' : 'text-gray-800' }}">{{ number_format($summary['failed_24h']) }}</div>
            </div>
        </div>

        {{-- Filter --}}
        <form method="GET" action="{{ route('settings.jubelio.history') }}" class="flex flex-wrap items-end gap-2 mb-4">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Jenis</label>
                <select name="type" class="border rounded px-2 py-1.5 text-sm">
                    <option value="">Semua</option>
                    <option value="order" @selected($type==='order')>Pesanan</option>
                    <option value="stock" @selected($type==='stock')>Stok</option>
                    <option value="price" @selected($type==='price')>Harga</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Status</label>
                <select name="status" class="border rounded px-2 py-1.5 text-sm">
                    <option value="">Semua</option>
                    <option value="success" @selected($status==='success')>Berhasil</option>
                    <option value="failed" @selected($status==='failed')>Gagal</option>
                    <option value="skipped" @selected($status==='skipped')>Dilewati</option>
                </select>
            </div>
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs font-semibold text-gray-500 mb-1">Cari (nama / SKU / no pesanan / pesan)</label>
                <input type="text" name="q" value="{{ $q }}" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="mis. SKU-001 atau JBL-123">
            </div>
            <button type="submit" class="px-4 py-1.5 bg-gray-800 hover:bg-gray-900 text-white rounded text-sm font-semibold">Filter</button>
            @if($type || $status || $q !== '')
                <a href="{{ route('settings.jubelio.history') }}" class="px-3 py-1.5 text-sm text-gray-500 hover:underline">Reset</a>
            @endif
        </form>

        {{-- Tabel --}}
        <div class="overflow-x-auto border rounded-lg">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="text-left px-3 py-2 font-semibold">Waktu</th>
                        <th class="text-left px-3 py-2 font-semibold">Jenis</th>
                        <th class="text-left px-3 py-2 font-semibold">Status</th>
                        <th class="text-left px-3 py-2 font-semibold">Objek</th>
                        <th class="text-left px-3 py-2 font-semibold">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($logs as $log)
                        <tr class="hover:bg-gray-50 align-top">
                            <td class="px-3 py-2 whitespace-nowrap text-gray-600">
                                <div>{{ $log->created_at->format('d/m/Y H:i') }}</div>
                                <div class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $log->typeBadgeClass() }}">{{ $log->typeLabel() }}</span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-xs font-semibold {{ $log->statusBadgeClass() }}">{{ $log->statusLabel() }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-800">{{ $log->title }}</div>
                                @if($log->reference)
                                    <div class="text-xs text-gray-400 font-mono">{{ $log->reference }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-600">{{ $log->message }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-10 text-center text-gray-400">
                                Belum ada riwayat sinkron. Catatan akan muncul saat cron menjalankan push stok/harga atau menarik pesanan dari Jubelio.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    </div>
</div>
@endsection
