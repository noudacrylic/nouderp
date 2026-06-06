@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Surat Jalan (Delivery)</h1>
    <div class="flex gap-2">
        <a href="{{ route('pos.fulfillment.belum-siap') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm" title="Kembali ke Pemrosesan Pesanan (POS)">← Pemrosesan Pesanan</a>
        <a href="{{ route('sales.deliveries.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Surat Jalan</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nomor SJ, pelanggan, kurir, atau no. resi...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Pengiriman</label>
        <select name="pengiriman" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="ambil_toko" @selected(request('pengiriman')=='ambil_toko')>Ambil di Toko</option>
            <option value="kurir" @selected(request('pengiriman')=='kurir')>Pakai Kurir</option>
            <option value="booked" @selected(request('pengiriman')=='booked')>Resi Aktif</option>
            <option value="not_booked" @selected(request('pengiriman')=='not_booked')>Kurir Belum Booking</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Surat Jalan</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Pelanggan</th>
                <th class="px-3 py-2 text-left">Kurir / Pengiriman</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($deliveries as $d)
                @php
                    $status = strtolower($d->status ?? '');
                    $isDraft = $status === 'draft';
                    $isPosted = $status === 'posted';
                    $rowHref = $isDraft
                        ? route('sales.deliveries.edit', $d->id)
                        : route('sales.deliveries.show', $d->id);
                    $customerName = $d->order->customer->name ?? $d->invoice->customer->name ?? '-';
                    [$stCls, $stLabel] = match($status) {
                        'draft'     => ['bg-yellow-100 text-yellow-700', 'Draf'],
                        'posted'    => ['bg-green-100 text-green-700', 'Diposting'],
                        'confirmed' => ['bg-blue-100 text-blue-700', 'Confirmed'],
                        'cancelled' => ['bg-gray-200 text-gray-600', 'Cancelled'],
                        'void'      => ['bg-red-100 text-red-700', 'Void'],
                        default     => ['bg-gray-100 text-gray-500', strtoupper($d->status ?? '-')],
                    };

                    // Info kurir/pengiriman — selaras dengan halaman detail Surat Jalan.
                    $shipSrc = $d->order ?? $d->invoice;
                    $isAmbil = $d->delivery_method === 'ambil_toko';
                    $isBooked = $d->isBooked();
                    $courierLabel = $d->courier_name ?: ($shipSrc?->shipping_courier_code);
                    $pickupCode = $shipSrc?->pickup_code;
                    $pickupDone = ($shipSrc?->pickup_status) === 'picked_up';
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $d->delivery_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $d->delivery_number])
                    </td>
                    <td class="px-3 py-2">{{ \Carbon\Carbon::parse($d->delivery_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $customerName }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        @if($isAmbil)
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-700"
                                  title="{{ $pickupCode ? 'Booking: '.$pickupCode : 'Diambil di toko' }}">
                                🏬 Ambil di Toko{{ $pickupDone ? ' ✓' : '' }}
                            </span>
                        @else
                            <span class="font-medium uppercase">{{ $courierLabel ?: '—' }}</span>
                            @if($isBooked)
                                <span class="ml-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700" title="Resi sudah dibooking">RESI AKTIF</span>
                            @endif
                            @if($d->tracking_number)
                                <span class="ml-1 text-[11px] text-gray-600 font-mono align-middle">{{ $d->tracking_number }}</span>
                                @include('erp.purchasing._partials.copy-btn', ['value' => $d->tracking_number])
                            @endif
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $stCls }}">{{ $stLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($isDraft)
                                <form method="POST" action="{{ route('sales.deliveries.post', $d->id) }}"
                                      onsubmit="return confirm('Post pengiriman ini? Stok akan dikurangi.')">
                                    @csrf
                                    <button class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('sales.deliveries.destroy', $d->id) }}"
                                      onsubmit="return confirm('Cancel Surat Jalan {{ $d->delivery_number }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs">Batal</button>
                                </form>
                            @endif

                            @if($isPosted)
                                <a href="{{ route('sales.deliveries.print', $d->id) }}"
                                   class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Cetak Surat Jalan">Cetak</a>
                                @if($isBooked)
                                    <a href="{{ route('sales.deliveries.resi', $d->id) }}"
                                       class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded text-xs" title="Cetak Resi">Resi</a>
                                    <a href="{{ route('sales.deliveries.track', $d->id) }}"
                                       class="bg-cyan-600 hover:bg-cyan-700 text-white px-2 py-1 rounded text-xs" title="Lacak Paket">Lacak</a>
                                @endif
                                @if($d->canBeVoided())
                                    <form method="POST" action="{{ route('sales.deliveries.void', $d->id) }}"
                                          onsubmit="return confirm('Void Surat Jalan {{ $d->delivery_number }}? Stok akan dikembalikan.')">
                                        @csrf
                                        <button class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Void</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada surat jalan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($deliveries instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $deliveries->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
