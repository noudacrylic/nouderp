{{-- Label resi. Param: $delivery, $origin, $dest, $totalWeight, $barcodeId (id svg) atau pakai class .barcode (bulk). --}}
@php
    // Kode kurir dicoba dulu (paling bersih: 'jne', 'jt_cargo'), baru nama layanan.
    $courierLogo = \App\Modules\Shipping\CourierBrand::logo($delivery->shipping_courier_code, $delivery->courier_name);
    $courierText = $delivery->shipping_courier_code ? strtoupper($delivery->shipping_courier_code) : $delivery->courier_name;
@endphp
<div class="resi-label">
    <div class="rrow">
        @if($courierLogo)
            <img class="rlogo" src="{{ $courierLogo }}" alt="{{ $courierText }}">
        @else
            <div class="rcourier">{{ $courierText }}</div>
        @endif
        <span class="rpill">{{ $delivery->collection_method === 'dropoff' ? 'Drop Off' : 'Pickup' }}</span>
    </div>
    <div class="rservice">{{ $delivery->courier_name }}</div>

    <div class="rbarcode-wrap">
        @isset($barcodeId)
            <svg id="{{ $barcodeId }}"></svg>
        @else
            <svg class="barcode" data-resi="{{ $delivery->tracking_number }}"></svg>
        @endisset
        <div class="rresi-num">{{ $delivery->tracking_number }}</div>
    </div>

    <div class="rbox from">
        <h4>Pengirim</h4>
        <div class="rname">{{ $origin['name'] }} @if($origin['phone'])<span style="font-weight:normal;font-size:12px"> · {{ $origin['phone'] }}</span>@endif</div>
        <div class="raddr">{{ $origin['address'] ?: '-' }}</div>
    </div>

    <div class="rbox to">
        <h4>Penerima</h4>
        <div class="rname">{{ $dest['name'] }} @if($dest['phone'] && $dest['phone'] !== '-')<span style="font-weight:normal;font-size:13px"> · {{ $dest['phone'] }}</span>@endif</div>
        <div class="raddr">{{ $dest['address'] }}</div>
    </div>

    <table>
        <thead><tr><th>Barang</th><th>Qty</th></tr></thead>
        <tbody>
            @foreach($delivery->items as $it)
                <tr>
                    <td>{{ $it->product->name ?? 'Produk #'.$it->product_id }}</td>
                    <td>{{ rtrim(rtrim(number_format($it->qty, 2, '.', ''), '0'), '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rmeta">
        <span>No. SJ: {{ $delivery->delivery_number }}</span>
        <span>Berat: {{ number_format($totalWeight, 0, ',', '.') }} g</span>
        <span>{{ \Carbon\Carbon::parse($delivery->delivery_date)->format('d/m/Y') }}</span>
    </div>
    <div class="rmeta"><span>Order ID: {{ $delivery->provider_order_id }}</span></div>
</div>
