@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Selesai</h1>
        <p class="text-xs text-gray-500">
            Seluruh pesanan yang sudah tuntas — marketplace: ditandai selesai oleh marketplace ·
            ambil di toko: barangnya sudah diambil · kasir: transaksi langsung di toko ·
            kurir: semua paket sudah sampai di pembeli.
        </p>
    </div>
</div>

{{-- Filter sendiri, bukan _filters bersama: di daftar sepanjang ribuan baris yang
     dicari orang adalah RENTANG TANGGAL, bukan nama kurir. Dropdown kurir di tab ini
     juga tak lagi bisa disaring di SQL sejak daftarnya dipaginasi — menyaringnya
     sesudah halaman terambil hanya akan mengosongkan halaman yang isinya ada. --}}
<form method="GET" class="mb-3 flex items-end gap-2 flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="No. SO / No. Faktur / pelanggan / produk / SKU…"
               class="border rounded px-3 py-1.5 text-sm w-80">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Channel</label>
        <select name="channel" class="border rounded px-2 py-1.5 text-sm bg-white">
            <option value="">Semua</option>
            <option value="marketplace" @selected(request('channel') === 'marketplace')>🛒 Marketplace</option>
            <option value="non" @selected(request('channel') === 'non')>🏬 Non-Marketplace</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Selesai dari</label>
        <input type="date" name="from" value="{{ request('from') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="to" value="{{ request('to') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    @include('erp._partials.per-page-select')
    <button type="submit" class="px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-semibold">Cari</button>
    @if(request('q') || request('channel') || request('from') || request('to'))
        <a href="{{ route('pos.fulfillment.selesai') }}" class="text-xs text-gray-400 hover:text-gray-600 font-semibold pb-2">✕ Reset</a>
    @endif
</form>

<p class="mb-3 text-xs text-gray-500">{{ number_format($rows->total(), 0, ',', '.') }} pesanan selesai</p>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr class="text-left text-[10px] font-black text-gray-400 uppercase">
                    <th class="px-3 py-2">Tgl Selesai</th>
                    <th class="px-3 py-2">Nomor</th>
                    <th class="px-3 py-2">Pelanggan</th>
                    <th class="px-3 py-2">Channel</th>
                    <th class="px-3 py-2">Cara</th>
                    <th class="px-3 py-2 text-right">Total</th>
                    <th class="px-3 py-2">Kurir / Resi</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    @php
                        /* Tiga jenis baris hidup berdampingan di tab ini, dan ketiganya
                           menuju dokumen yang berbeda: SO ke Sales Order-nya, kasir ke
                           fakturnya, garansi ke nota garansinya. */
                        $utama = match ($row['kind']) {
                            'kasir'   => route('sales.invoices.show', $row['id']),
                            'garansi' => route('sales.warranty.show', $row['id']),
                            default   => route('sales.orders.show', $row['id']),
                        };
                        $faktur = $row['kind'] === 'so' ? ($row['invoice'] ?? null) : null;
                    @endphp
                    <tr class="hover:bg-gray-50/60">
                        <td class="px-3 py-2 text-gray-500 whitespace-nowrap">
                            {{ $row['selesai_at'] ? \Carbon\Carbon::parse($row['selesai_at'])->format('d/m/Y') : '—' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <a href="{{ $utama }}" class="font-bold text-gray-800 hover:text-indigo-600">{{ $row['number'] }}</a>
                            @if($row['kind'] === 'kasir')
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-black bg-emerald-100 text-emerald-700 align-middle">KASIR</span>
                            @elseif($row['kind'] === 'garansi')
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-black bg-rose-100 text-rose-700 align-middle">GARANSI</span>
                            @endif
                            {{-- Nomor faktur berdiri sebagai barisnya sendiri, bukan cuma
                                 dicari diam-diam: yang dipegang orang saat menelusuri
                                 riwayat sering nomor nota, bukan nomor SO. --}}
                            @if($faktur)
                                <div class="text-[11px] text-gray-400">
                                    <a href="{{ route('sales.invoices.show', $faktur->id) }}"
                                       class="hover:text-indigo-600">{{ $faktur->invoice_number }}</a>
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-gray-700">{{ $row['customer'] }}</td>
                        <td class="px-3 py-2 text-gray-500 text-xs">
                            {{ !empty($row['is_marketplace']) ? ($row['channel'] ?: 'Marketplace') : 'Toko / Web' }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $row['delivery_display'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-gray-800 whitespace-nowrap">
                            {{ ($row['grand_total'] ?? 0) > 0 ? rupiah($row['grand_total']) : '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">
                            @if(!empty($row['tracking_no']))
                                <span class="font-mono text-[11px]">{{ $row['tracking_no'] }}</span>
                                @if(!empty($row['shipper']))<div class="text-[11px] text-gray-400">{{ $row['shipper'] }}</div>@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <a href="{{ $utama }}"
                               class="text-xs px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">Buka →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-10 text-center text-gray-400 text-sm">Belum ada pesanan yang selesai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $rows->links() }}</div>
@endsection
