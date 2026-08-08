{{-- Tombol Generate Resi satuan. Param: $d (SalesDelivery).

     Dulu tombol ini membuka popup yang menanyakan berat & dimensi — padahal angka itu sudah
     dikunci operator di sub-tab "Perlu Ukur" dan tersimpan di SO. Menanyakannya lagi bukan
     cuma satu klik tambahan: kolomnya kosong sampai popup selesai memuat, jadi resi bisa
     terbit dengan ukuran yang BERBEDA dari yang ditimbang.

     Sekarang tombolnya langsung memesan resi memakai ukuran SO. Popupnya tetap ada di balik
     tombol ✎ untuk kasus kardusnya ternyata beda dari yang tercatat. --}}
<form action="{{ route('sales.deliveries.book', $d->id) }}" method="POST" class="flex items-center gap-1"
      onsubmit="return confirm('Generate resi untuk {{ $d->delivery_number }}? Ini membuat order pengiriman NYATA & memotong saldo kurir.')">
    @csrf
    <button type="submit"
            class="px-2.5 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">📮 Generate Resi</button>
</form>
<button type="button"
        class="js-genresi px-2 py-1 rounded border border-gray-200 text-gray-400 hover:text-indigo-700 hover:border-indigo-300 font-semibold"
        title="Generate dengan berat/dimensi lain dari yang tersimpan"
        data-id="{{ $d->id }}" data-number="{{ $d->delivery_number }}">✎</button>
