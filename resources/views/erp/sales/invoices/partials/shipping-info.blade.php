@php
    // Info pengiriman READ-ONLY di Faktur. Faktur sudah diposting → kurir & ongkir
    // tidak bisa diubah (sudah masuk jurnal). Cukup tampilkan info kurir + no. resi.
    $invMethodLabel = \App\Modules\Sales\Models\SalesOrder::DELIVERY_METHODS[$invoice->delivery_method ?? ''] ?? null;
    $invCourier = trim(
        strtoupper($invoice->shipping_courier_code ?? '')
        . ($invoice->shipping_service_name ? ' · ' . $invoice->shipping_service_name : ''),
        ' ·'
    );

    // No. Resi dari Surat Jalan terkait (lewat SO faktur).
    $invTracking = collect();
    if (!empty($invoice->sales_order_id)) {
        $invTracking = \App\Modules\Sales\Models\SalesDelivery::where('sales_order_id', $invoice->sales_order_id)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereNotNull('tracking_number')
            ->orderByDesc('id')
            ->pluck('tracking_number')
            ->filter()->unique()->values();
    }

    $showShip = $invMethodLabel || $invCourier || ($invoice->shipping_cost ?? 0) > 0 || $invTracking->count();
@endphp

@if($showShip)
<div class="bg-white border border-gray-100 rounded-lg shadow-sm p-4 mb-4">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
            <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">🚚 Pengiriman</div>
            <div class="mt-1 text-sm text-gray-700">
                @if($invMethodLabel)<span class="font-semibold">{{ $invMethodLabel }}</span>@endif
                @if($invCourier)
                    <span class="text-gray-300">·</span> <span class="font-bold text-gray-800">{{ $invCourier }}</span>
                @endif
            </div>
            @if(($invoice->shipping_cost ?? 0) > 0)
                <div class="mt-0.5 text-sm text-gray-600">
                    Ongkir ditagih: <span class="font-bold text-gray-800">Rp {{ number_format($invoice->shipping_cost, 0, ',', '.') }}</span>
                </div>
            @endif
            @if($invTracking->count())
                <div class="mt-1 text-sm text-gray-700">
                    No. Resi:
                    @foreach($invTracking as $t)
                        <span class="font-mono font-bold text-gray-800">{{ $t }}</span>@unless($loop->last), @endunless
                    @endforeach
                </div>
            @else
                <div class="mt-1 text-[11px] text-gray-400 italic">No. resi tampil di sini setelah Surat Jalan dibooking.</div>
            @endif
        </div>
        <span class="shrink-0 text-[11px] text-gray-400 italic max-w-[14rem] text-right">
            Faktur sudah diposting — kurir & ongkir tidak dapat diubah.
        </span>
    </div>
</div>
@endif
