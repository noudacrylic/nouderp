{{-- Driver kurir instan. Tetap tampil setelah terkirim: Jubelio sudah mengosongkan datanya. --}}
@if($delivery->driver_name || $delivery->driver_phone)
    <div class="text-xs text-gray-600">
        🛵 Driver: <span class="font-semibold">{{ $delivery->driver_name ?: '—' }}</span>
        @if($delivery->driver_phone)
            · <span class="js-copy cursor-pointer hover:underline" data-copy="{{ $delivery->driver_phone }}" title="Klik untuk salin nomor">{{ $delivery->driver_phone }}</span>
        @endif
    </div>
@endif
