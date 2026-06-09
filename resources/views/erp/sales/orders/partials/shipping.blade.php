@php
    // Kartu Pengiriman di halaman SO. Hanya untuk metode kurir/instant —
    // "Ambil di Toko" ditangani kartu pickup terpisah (partials/pickup).
    $isPickup = ($so->delivery_method ?? 'kurir') === 'ambil_toko';

    $courierLabel = trim(
        strtoupper($so->shipping_courier_code ?? '')
        . ($so->shipping_service_name ? ' · ' . $so->shipping_service_name : ''),
        ' ·'
    );

    // No. Resi dari Surat Jalan terkait (terisi setelah booking resi di Surat Jalan).
    $tracking = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $so->id)
        ->whereNotIn('status', ['void', 'cancelled'])
        ->whereNotNull('tracking_number')
        ->orderByDesc('id')
        ->pluck('tracking_number')
        ->filter()->unique()->values();

    // Bisa diubah selama belum void & belum ada faktur DIPOSTING. Faktur draft masih boleh
    // (jurnal belum terbentuk) — perubahan ikut disinkronkan ke faktur draft.
    $shipEditable = ($so->status !== 'void') && !($hasPostedInvoice ?? false);
@endphp

@unless($isPickup)
<div class="mt-4">
    <div class="bg-white border border-gray-100 rounded-lg shadow-sm p-4">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">🚚 Pengiriman</div>
                <div class="mt-1 text-sm text-gray-700">
                    <span class="font-semibold">{{ $so->deliveryMethodLabel() }}</span>
                    @if($courierLabel)
                        <span class="text-gray-300">·</span> <span class="font-bold text-gray-800">{{ $courierLabel }}</span>
                    @else
                        <span class="text-gray-400 italic ml-1">kurir belum dipilih</span>
                    @endif
                </div>
                <div class="mt-0.5 text-sm text-gray-600">
                    Ongkir ditagih: <span class="font-bold text-gray-800">Rp {{ number_format($so->shipping_cost ?? 0, 0, ',', '.') }}</span>
                    @if(($so->shipping_gross ?? 0) > ($so->shipping_cost ?? 0))
                        <span class="text-[11px] text-gray-400">(gross Rp {{ number_format($so->shipping_gross, 0, ',', '.') }} − diskon ongkir)</span>
                    @endif
                </div>
                @if($tracking->count())
                    <div class="mt-1 text-sm text-gray-700">
                        No. Resi:
                        @foreach($tracking as $t)
                            <span class="font-mono font-bold text-gray-800">{{ $t }}</span>@unless($loop->last), @endunless
                        @endforeach
                    </div>
                @endif
            </div>

            @if($shipEditable)
                <button type="button" id="btn_edit_ship_so"
                    class="shrink-0 text-sm px-4 py-2 border border-blue-200 text-blue-600 rounded-lg font-bold hover:bg-blue-50">
                    ✎ Ubah Kurir / Ongkir
                </button>
            @endif
        </div>

        @if($shipEditable)
        <form id="ship_edit_form" action="{{ route('sales.orders.update-shipping', $so->id) }}" method="POST"
              class="hidden mt-4 border-t border-gray-100 pt-4">
            @csrf
            {{-- Elemen pendukung yang dibaca shipping-embed (customer & gudang asal). --}}
            <input type="hidden" id="customer_id" value="{{ $so->customer_id }}">
            <input type="hidden" name="warehouse_id" value="{{ $so->warehouse_id }}">

            <div class="max-w-2xl mx-auto">
                @include('erp._partials.shipping-embed', ['model' => $so])
            </div>

            <div class="flex items-center justify-center gap-3 mt-4 flex-wrap">
                <button type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-bold">
                    Simpan Pengiriman
                </button>
                <button type="button" id="btn_cancel_ship_so"
                    class="text-sm text-gray-500 hover:bg-gray-100 px-4 py-2 rounded-lg font-bold">
                    Batal
                </button>
                <span class="text-[11px] text-gray-400">Aman diubah selama faktur belum diposting — perubahan ikut ke faktur draft.</span>
            </div>
        </form>
        @endif
    </div>
</div>

@if($shipEditable)
<script>
(function () {
    var btn = document.getElementById('btn_edit_ship_so');
    var form = document.getElementById('ship_edit_form');
    var cancel = document.getElementById('btn_cancel_ship_so');
    if (btn && form) btn.addEventListener('click', function () {
        form.classList.toggle('hidden');
        if (!form.classList.contains('hidden')) form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
    if (cancel && form) cancel.addEventListener('click', function () { form.classList.add('hidden'); });
})();
</script>
@endif
@endunless
