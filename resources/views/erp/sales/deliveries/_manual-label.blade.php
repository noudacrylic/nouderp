{{-- Label pengiriman manual TANPA nomor resi.
     Param: $courierName, $origin, $dest, $items (koleksi: ->product->name, ->qty, ->product_id),
            $docNumber, $totalWeight, $docDate (string). --}}
<div class="resi-label">
    <div class="rrow">
        <div class="rcourier" style="font-size:14px">LABEL PENGIRIMAN</div>
    </div>
    @if($courierName)
        <div class="rservice">Kurir: {{ $courierName }}</div>
    @endif

    <div class="rbox from" style="margin-top:6px">
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
            @foreach($items as $it)
                <tr>
                    <td>{{ $it->product->name ?? 'Produk #'.$it->product_id }}</td>
                    <td>{{ rtrim(rtrim(number_format($it->qty, 2, '.', ''), '0'), '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rmeta">
        <span>No.: {{ $docNumber }}</span>
        <span>Berat: {{ number_format($totalWeight, 0, ',', '.') }} g</span>
        <span>{{ $docDate }}</span>
    </div>
</div>
