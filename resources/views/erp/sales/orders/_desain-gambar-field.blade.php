{{-- Desain gambar oleh Admin Desain: gambar cetak pesanan ini (stiker, akrilik sign) dibuat Admin
     Desain dari brief di bawah. Kartunya masuk meja Desain setelah pembeli membayar / SO tempo.
     Beda dengan desain PRODUK (bentuk/ukuran barang custom) yang dipegang owner. --}}
@php
    $desain = (bool) old('desain_gambar', $so?->desain_gambar);
    $brief  = (string) old('desain_brief', $so?->desain_brief);
@endphp

<div class="border-t border-gray-200 pt-3 mt-3" x-data="{ desain: @js($desain) }">
    <input type="hidden" name="desain_gambar" value="0">
    <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" name="desain_gambar" value="1" x-model="desain"
            class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-400">
        <span>
            <span class="block text-xs font-bold text-gray-600 uppercase tracking-wider">Desain gambar oleh Admin Desain</span>
            <span class="block text-[11px] text-gray-400 mt-0.5">
                Admin Desain membuat gambar sampai disetujui pelanggan, lalu membuat OP-nya. Kartu muncul setelah pembeli membayar.
            </span>
        </span>
    </label>
    <textarea name="desain_brief" rows="3" x-show="desain" x-cloak
        placeholder="Brief: bahan akrilik, ukuran, warna, isi tulisan/logo…"
        class="mt-2 w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm resize-y focus:ring-purple-400 focus:border-purple-400">{{ $brief }}</textarea>
</div>
