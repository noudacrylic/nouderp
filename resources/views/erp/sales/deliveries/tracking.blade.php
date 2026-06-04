@extends('layouts.erp')

@section('content')
@php
    $statusMap = [
        'confirmed' => 'Dikonfirmasi', 'allocated' => 'Kurir dialokasikan', 'picking_up' => 'Menuju penjemputan',
        'picked' => 'Paket dijemput', 'dropping_off' => 'Dalam pengiriman', 'on_hold' => 'Tertahan',
        'delivered' => 'Terkirim', 'cancelled' => 'Dibatalkan', 'returned' => 'Dikembalikan',
    ];
    $cur = $result['status'] ?? null;
    $curLabel = $cur ? ($statusMap[$cur] ?? ucfirst(str_replace('_',' ',$cur))) : '—';
    $isDelivered = $cur === 'delivered';
@endphp

<div class="max-w-2xl mx-auto py-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Lacak Paket</h1>
        <a href="{{ route('sales.deliveries.show', $delivery->id) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
    </div>

    <div class="bg-white rounded shadow p-4 mb-4 text-sm">
        <div class="grid grid-cols-2 gap-y-2 gap-x-4">
            <div><span class="text-gray-500">No. Resi</span><div class="font-mono font-bold">{{ $delivery->tracking_number ?: '—' }}</div></div>
            <div><span class="text-gray-500">Kurir</span><div class="font-semibold">{{ $delivery->courier_name }}</div></div>
            <div><span class="text-gray-500">Tujuan</span><div>{{ $delivery->order->customer->name ?? $delivery->invoice->customer->name ?? '-' }}</div></div>
            <div><span class="text-gray-500">No. SJ</span><div>{{ $delivery->delivery_number }}</div></div>
        </div>
        <div class="mt-3 pt-3 border-t">
            <span class="text-gray-500 text-xs">Status saat ini</span>
            <div>
                <span class="px-3 py-1 rounded-full text-sm font-bold {{ $isDelivered ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' }}">
                    {{ $curLabel }}
                </span>
            </div>
        </div>
    </div>

    @if(!$result['success'])
        <div class="bg-amber-50 border border-amber-200 text-amber-700 px-3 py-2 rounded text-sm">
            Gagal mengambil tracking: {{ $result['error'] ?? 'tidak diketahui' }}.
            <div class="text-[11px] text-amber-600 mt-1">Status mungkin belum tersedia kalau resi baru dibuat / kurir belum memproses.</div>
        </div>
    @elseif(empty($result['history']))
        <div class="bg-gray-50 border border-gray-200 text-gray-500 px-3 py-3 rounded text-sm text-center">
            Belum ada riwayat perjalanan. Kurir belum mengupdate status — coba cek lagi nanti.
        </div>
    @else
        <div class="bg-white rounded shadow p-4">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Riwayat Perjalanan</h2>
            <ol class="relative border-l border-gray-200 ml-2">
                @foreach(array_reverse($result['history']) as $i => $h)
                    <li class="mb-5 ml-4">
                        <div class="absolute w-2.5 h-2.5 rounded-full -left-[5px] mt-1.5 {{ $i === 0 ? 'bg-blue-600' : 'bg-gray-300' }}"></div>
                        <time class="text-[11px] text-gray-400">
                            {{ !empty($h['updated_at']) ? \Carbon\Carbon::parse($h['updated_at'])->format('d/m/Y H:i') : '' }}
                        </time>
                        <p class="text-sm text-gray-800">{{ $h['note'] ?? ($h['status'] ?? '-') }}</p>
                        @if(!empty($h['status']))
                            <p class="text-[11px] text-gray-400">{{ $statusMap[$h['status']] ?? ucfirst(str_replace('_',' ',$h['status'])) }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <div class="mt-4">
        <a href="{{ route('sales.deliveries.track', $delivery->id) }}" class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold">
            🔄 Refresh Status
        </a>
    </div>
</div>
@endsection
