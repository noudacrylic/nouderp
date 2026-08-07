@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Semua Pesanan</h1>
        <p class="text-xs text-gray-500">
            Seluruh riwayat Sales Order beserta status terkininya — untuk audit, bukan antrean kerja.
            Klik <b>Buka</b> untuk melompat ke tab tempat pesanan itu berada sekarang.
        </p>
    </div>
</div>

<form method="GET" class="mb-3 flex items-end gap-2 flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nomor / pelanggan / produk / SKU…"
               class="border rounded px-3 py-1.5 text-sm w-72">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Channel</label>
        <select name="channel" class="filter-auto border rounded px-2 py-1.5 text-sm bg-white">
            <option value="">Semua</option>
            <option value="marketplace" @selected(request('channel') === 'marketplace')>🛒 Marketplace</option>
            <option value="non" @selected(request('channel') === 'non')>🏬 Non-Marketplace</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dokumen</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5 text-sm bg-white">
            <option value="">Semua</option>
            <option value="aktif" @selected(request('status') === 'aktif')>Aktif</option>
            <option value="draft" @selected(request('status') === 'draft')>Draft</option>
            <option value="void"  @selected(request('status') === 'void')>Dibatalkan / Void</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="from" value="{{ request('from') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="to" value="{{ request('to') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    @include('erp._partials.per-page-select')
    <button type="submit" class="px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-semibold">Cari</button>
    @if(request('q') || request('channel') || request('status') || request('from') || request('to'))
        <a href="{{ route('pos.fulfillment.semua') }}" class="text-xs text-gray-400 hover:text-gray-600 font-semibold pb-2">✕ Reset</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr class="text-left text-[10px] font-black text-gray-400 uppercase">
                    <th class="px-3 py-2">Tanggal</th>
                    <th class="px-3 py-2">Nomor</th>
                    <th class="px-3 py-2">Pelanggan</th>
                    <th class="px-3 py-2">Channel</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2 text-right">Total</th>
                    <th class="px-3 py-2 text-right">Sisa</th>
                    <th class="px-3 py-2">Kurir / Resi</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    @php
                        $bucket = $row['bucket'];
                        $badge = match ($bucket) {
                            'belum_bayar'    => 'bg-gray-100 text-gray-600',
                            'belum_siap'     => 'bg-amber-100 text-amber-700',
                            'belum_lunas'    => 'bg-yellow-100 text-yellow-800',
                            'perlu_diproses' => 'bg-emerald-100 text-emerald-700',
                            'telah_diproses' => 'bg-green-100 text-green-700',
                            'dikirim'        => 'bg-sky-100 text-sky-700',
                            'retur'          => 'bg-rose-100 text-rose-700',
                            'selesai'        => 'bg-green-600 text-white',
                            'dibatalkan'     => 'bg-red-100 text-red-700',
                            default          => 'bg-gray-100 text-gray-600',
                        };
                        $jump = \App\Modules\POS\Services\FulfillmentReadinessService::bucketRoute($bucket, $row['number']);
                    @endphp
                    <tr class="hover:bg-gray-50/60">
                        <td class="px-3 py-2 text-gray-500 whitespace-nowrap">
                            {{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d/m/Y') : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <a href="{{ route('sales.orders.show', $row['id']) }}" class="font-bold text-gray-800 hover:text-indigo-600">
                                {{ $row['number'] }}
                            </a>
                            @if(!empty($row['is_draft']))
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-black bg-gray-200 text-gray-600 align-middle">DRAFT</span>
                            @endif
                            @if(!empty($row['is_instant']))
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-black bg-orange-100 text-orange-700 align-middle">⚡</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-gray-700">{{ $row['customer'] }}</td>
                        <td class="px-3 py-2 text-gray-500 text-xs">
                            {{ !empty($row['is_marketplace']) ? ($row['channel'] ?: 'Marketplace') : 'Toko / Web' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-[10px] font-black {{ $badge }}">
                                {{ \App\Modules\POS\Services\FulfillmentReadinessService::bucketLabel($bucket) }}
                            </span>
                            @if(($row['resi_state'] ?? null) === 'belum_generate')
                                <span class="ml-1 text-[10px] text-orange-600 font-bold">belum resi</span>
                            @elseif(($row['resi_state'] ?? null) === 'belum_cetak')
                                <span class="ml-1 text-[10px] text-yellow-700 font-bold">belum cetak</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-semibold text-gray-800 whitespace-nowrap">
                            {{ rupiah($row['grand_total'] ?? 0) }}
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap {{ ($row['remaining'] ?? 0) > 0.01 ? 'text-amber-700 font-bold' : 'text-gray-300' }}">
                            {{ ($row['remaining'] ?? 0) > 0.01 ? rupiah($row['remaining']) : '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">
                            {{ $row['delivery_display'] ?: '—' }}
                            @if(!empty($row['tracking_no']))
                                <div class="text-[11px] text-gray-400 font-mono">{{ $row['tracking_no'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            @if($jump)
                                <a href="{{ $jump }}"
                                   class="text-xs px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold"
                                   title="Buka tab tempat pesanan ini berada sekarang">Buka →</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-gray-400 text-sm">Tidak ada pesanan yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $rows->links() }}
</div>
@endsection
