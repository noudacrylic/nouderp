{{--
    Paket retur Shopee yang dikirim balik pembeli (ShopeeReturn::kirimBalik): resi pengembalian,
    sudah sampai gudang atau belum, dan batas pembeli mengirim. Dipakai di daftar/detail/form
    retur & kartu retur Meja Kerja.
    Variabel: $kirimBalik = array<int, array{return_sn, resi, sampai, batas}>
--}}
@foreach ($kirimBalik ?? [] as $kb)
    <div class="text-[11px] leading-snug mt-0.5 whitespace-normal">
        @if ($kb['resi'])
            <span class="text-gray-500">📦 Resi retur</span>
            <button type="button" title="Salin resi"
                    onclick="navigator.clipboard?.writeText(@js($kb['resi'])); this.querySelector('span').textContent = '✓';"
                    class="font-mono font-semibold text-gray-800 hover:text-indigo-600">{{ $kb['resi'] }} <span class="text-gray-400">⧉</span></button>
            @if ($kb['sampai'])
                <span class="ml-1 px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold">✅ sampai gudang</span>
            @else
                <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-semibold">⏳ dalam perjalanan</span>
            @endif
        @else
            <span class="text-gray-500">📦 Pembeli belum kirim barang</span>
            @if ($kb['batas'])
                <span class="{{ $kb['batas']->isPast() ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                    · batas {{ $kb['batas']->format('d/m H:i') }}{{ $kb['batas']->isPast() ? ' (lewat)' : '' }}
                </span>
            @endif
        @endif
        @if (count($kirimBalik) > 1)
            <span class="text-gray-400">· {{ $kb['return_sn'] }}</span>
        @endif
    </div>
@endforeach
