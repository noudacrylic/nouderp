{{-- Kesepakatan "keep stock": pembeli setuju memesan barang yang stoknya belum ada.
     Dicentang → pembayaran lewat tautan tetap diterima walau stok tidak mencukupi,
     sehingga stok boleh menjadi minus. Tanpa centang, tautan menolak pembayaran. --}}
@php $backorder = (bool) old('allow_backorder', $so?->allow_backorder); @endphp

<div class="border-t border-gray-200 pt-3 mt-3">
    {{-- Hidden dulu supaya kotak yang dilepas centangnya tetap terkirim sebagai 0. --}}
    <input type="hidden" name="allow_backorder" value="0">
    <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" name="allow_backorder" value="1" {{ $backorder ? 'checked' : '' }}
            class="mt-0.5 rounded border-gray-300 text-amber-500 focus:ring-amber-400">
        <span>
            <span class="block text-xs font-bold text-gray-600 uppercase tracking-wider">Keep stock (stok menyusul)</span>
            <span class="block text-[11px] text-gray-400 mt-0.5">
                Pembeli setuju memesan barang yang stoknya belum ada. Tanpa ini, tautan pembayaran
                menolak bayar bila stok tidak cukup.
            </span>
        </span>
    </label>
</div>
