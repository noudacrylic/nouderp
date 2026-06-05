@extends('layouts.erp')

@section('content')
@php
    $statusCls = match($delivery->status) {
        'draft'     => 'bg-yellow-100 text-yellow-700',
        'posted'    => 'bg-green-100 text-green-700',
        'cancelled' => 'bg-gray-200 text-gray-600',
        default     => 'bg-gray-100 text-gray-500',
    };
    $isPosted = $delivery->status === 'posted';
    $isDraft = $delivery->status === 'draft';
@endphp

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
        <h1 class="text-lg font-semibold">Delivery</h1>
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $delivery->status }}</span>
    </div>
    <a href="{{ route('sales.deliveries.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    {{-- LEFT: Info SO & Customer --}}
    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Informasi Pengiriman</h2>
        <dl class="space-y-2">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">No. Delivery</dt>
                <dd class="font-semibold text-right">
                    {{ $delivery->delivery_number }}
                    @include('erp.purchasing._partials.copy-btn', ['value' => $delivery->delivery_number])
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Sales Order</dt>
                <dd class="font-semibold text-right">
                    @if($delivery->order)
                        <a href="{{ route('sales.orders.show', $delivery->order->id) }}" class="text-blue-600 hover:underline">
                            {{ $delivery->order->order_number }}
                        </a>
                    @else
                        -
                    @endif
                </dd>
            </div>
            @php
                $cust = $delivery->order->customer ?? $delivery->invoice->customer ?? null;
                $custPhone = $cust->recipient_phone ?? $cust->phone ?? null;
                $custAddr = $cust
                    ? collect([$cust->shipping_address, $cust->district, $cust->city, $cust->province, $cust->postal_code])->filter()->implode(', ')
                    : null;
                $custAddr = $custAddr ?: ($cust->address ?? null);
            @endphp
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Pelanggan</dt>
                <dd class="text-right">{{ $cust->name ?? '-' }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Phone</dt>
                <dd class="text-right">{{ $custPhone ?: '-' }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-gray-500">Address</dt>
                <dd class="text-right max-w-xs whitespace-pre-line">{{ $custAddr ?: '-' }}</dd>
            </div>
        </dl>
    </div>

    {{-- RIGHT: Inline form courier + tracking --}}
    <div class="bg-white rounded shadow p-4 text-sm">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Resi &amp; Jasa Pengiriman</h2>

        @if($delivery->status === 'cancelled')
            <p class="text-gray-400 italic">Pengiriman dibatalkan. Tidak dapat diubah.</p>
        @else
            <form method="POST" action="{{ route('sales.deliveries.update', $delivery->id) }}" class="space-y-3">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-xs text-gray-500 mb-1">
                        Tanggal Kirim
                        @if($isPosted) <span class="text-[10px] text-gray-400">(locked)</span> @endif
                    </label>
                    <input type="date" name="delivery_date"
                        value="{{ \Carbon\Carbon::parse($delivery->delivery_date)->format('Y-m-d') }}"
                        class="border rounded px-3 py-2 w-full text-sm @if($isPosted) bg-gray-100 @endif"
                        @if($isPosted) readonly @endif>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jasa Pengiriman</label>
                    <input type="text" name="courier_name" value="{{ $delivery->courier_name }}"
                        class="border rounded px-3 py-2 w-full text-sm" placeholder="JNE / J&amp;T / SiCepat / dll">
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">No. Resi / Tracking</label>
                    <input type="text" name="tracking_number" value="{{ $delivery->tracking_number }}"
                        class="border rounded px-3 py-2 w-full text-sm" placeholder="Nomor Resi">
                </div>

                <div class="pt-1">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                        Simpan
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>

{{-- BOOKING RESI (Biteship) --}}
@php
    $isAmbil = $delivery->delivery_method === 'ambil_toko';
    $src = $delivery->order ?: $delivery->invoice;
    $courierCode = $src->shipping_courier_code ?? null;
    $serviceName = $src->shipping_service_name ?? null;
    $serviceCode = $src->shipping_service_code ?? null;
    $colDefault = 'pickup';
    if ($courierCode) {
        $cfg = \App\Models\ShippingSetting::for('biteship')->config['courier_collection'] ?? [];
        $cm = $cfg[$courierCode] ?? 'pickup';
        $colDefault = in_array($cm, ['pickup','dropoff'], true) ? $cm : 'pickup';
    }
@endphp
@unless($isAmbil)
<div class="bg-white rounded shadow p-4 text-sm mb-4">
    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">🚚 Booking Resi (Biteship)</h2>
    @if($delivery->isBooked())
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">RESI AKTIF</span>
            <div><span class="text-gray-500">Kurir:</span> <b>{{ $delivery->courier_name }}</b></div>
            <div><span class="text-gray-500">No. Resi:</span> <b class="font-mono">{{ $delivery->tracking_number ?: '—' }}</b>
                @if($delivery->tracking_number) @include('erp.purchasing._partials.copy-btn', ['value' => $delivery->tracking_number]) @endif
            </div>
            <div><span class="text-gray-500">Order ID:</span> <span class="font-mono text-[11px]">{{ $delivery->provider_order_id }}</span></div>
            <div><span class="text-gray-500">Ambil:</span> {{ $delivery->collection_method === 'dropoff' ? 'Antar ke gerai' : 'Dijemput kurir' }}</div>
            <a href="{{ route('sales.deliveries.resi', $delivery->id) }}"
               class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded text-xs font-bold">🏷️ Cetak Resi</a>
            <a href="{{ route('sales.deliveries.track', $delivery->id) }}"
               class="bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-1.5 rounded text-xs font-bold">📦 Lacak Paket</a>
        </div>
    @elseif(!$isPosted)
        <p class="text-gray-400 italic">Post Surat Jalan dulu sebelum booking resi.</p>
    @elseif(!$courierCode)
        <p class="text-amber-600">Kurir belum dipilih di Sales Order. Pilih kurir lewat <b>Cek Ongkir</b> di SO, lalu kembali ke sini untuk booking.</p>
    @else
        <form method="POST" action="{{ route('sales.deliveries.book', $delivery->id) }}"
              class="flex flex-wrap items-end gap-3"
              onsubmit="return confirm('Buat resi ke Biteship sekarang? Ini membuat order pengiriman nyata.')">
            @csrf
            <div>
                <div class="text-gray-500 text-xs mb-1">Kurir terpilih (dari SO)</div>
                <div class="font-bold">{{ strtoupper($courierCode) }} <span class="font-normal text-gray-500">{{ $serviceName }}</span></div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Cara Ambil Paket</label>
                <select name="collection_method" class="border rounded px-3 py-2 text-sm bg-white">
                    <option value="pickup"  {{ $colDefault === 'pickup'  ? 'selected' : '' }}>Dijemput kurir</option>
                    <option value="dropoff" {{ $colDefault === 'dropoff' ? 'selected' : '' }}>Antar ke gerai</option>
                </select>
            </div>
            <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-bold">📦 Booking Resi</button>
        </form>
        @if($delivery->shipping_status === 'failed')
            <p class="text-red-500 text-xs mt-2">⚠ Booking terakhir gagal. Periksa alamat pelanggan / kurir lalu coba lagi.</p>
        @endif
    @endif
</div>
@endunless

{{-- Tabel produk --}}
<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-right w-40">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse($delivery->items as $item)
                <tr class="border-b">
                    <td class="px-3 py-2">{{ $item->product->name ?? 'Product #' . $item->product_id }}</td>
                    <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($item->qty, 4, '.', ''), '0'), '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="px-3 py-6 text-center text-gray-400">Belum ada item.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Action bar --}}
<div class="flex flex-wrap gap-2">
    @if($isDraft)
        <form method="POST" action="{{ route('sales.deliveries.post', $delivery->id) }}" onsubmit="return confirm('Post pengiriman ini? Stok akan dikurangi.')">
            @csrf
            <button class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded text-sm">Post</button>
        </form>
        <form method="POST" action="{{ route('sales.deliveries.destroy', $delivery->id) }}" onsubmit="return confirm('Batalkan delivery ini?')">
            @csrf
            @method('DELETE')
            <button class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded text-sm">Batal</button>
        </form>
    @endif

    @if($isPosted)
        <a href="{{ route('sales.deliveries.print', $delivery->id) }}"
           class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
            🖨️ Cetak Surat Jalan
        </a>
        @if($delivery->isBooked())
            <a href="{{ route('sales.deliveries.resi', $delivery->id) }}"
               class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                🏷️ Cetak Resi
            </a>
            <a href="{{ route('sales.deliveries.track', $delivery->id) }}"
               class="bg-cyan-600 hover:bg-cyan-700 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                📦 Lacak Paket
            </a>
        @endif
        @if($delivery->canBeVoided())
            <form method="POST" action="{{ route('sales.deliveries.void', $delivery->id) }}" onsubmit="return confirm('Void Surat Jalan ini? Stok akan dikembalikan & jurnal dibatalkan.')">
                @csrf
                <button class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-2 rounded text-sm">Void</button>
            </form>
        @endif
    @endif
</div>

@include('erp.sales._partials.relations-delivery')

@include('erp.purchasing._partials.list-scripts')
@endsection
