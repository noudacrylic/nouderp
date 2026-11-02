{{-- Tulisan kecil di atas kotak balasan: produk yang sedang dibahas di chat Shopee. Elemennya
     selalu ada (kosong pun) supaya bisa diganti saat pembeli mengirim kartu produk baru.
     Kartu variasi menunjuk satu varian, jadi hanya SKU varian itu yang ditulis. --}}
@php
    $layanan = app(\App\Modules\CRM\Services\ProdukDibahasService::class);
    $fokus = $layanan->untukChat($percakapan);
    $sku = $fokus ? $layanan->daftarSku($fokus['pilihan'] ? [$fokus['pilihan']] : $fokus['varian']) : [];
@endphp
<div id="produkDibahas" class="{{ $fokus ? 'mb-1 truncate text-[11px] text-gray-500' : 'hidden' }}"
     @if($fokus) title="{{ $fokus['nama'] }} — {{ implode(', ', $sku) }} (kartu produk {{ $fokus['sent_at']->format('d/m H:i') }})" @endif>
    @if($fokus)
        🛍️ Dibahas: <span class="font-mono font-semibold text-gray-700">{{ $layanan->ringkas($sku) }}</span> · {{ $fokus['nama'] }}@if($fokus['pilihan']?->nama_model) ({{ $fokus['pilihan']->nama_model }})@endif
    @endif
</div>
