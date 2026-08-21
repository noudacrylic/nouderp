{{--
    Dua ukuran keuntungan, keduanya benar — sama seperti di halaman HPP. Param: $sc (skenario).

    Margin (÷ harga jual) ukuran laporan keuangan, tidak pernah lewat 100%. Markup (÷ HPP)
    ukuran untuk menetapkan harga dari modal, bisa lewat 100%. Ditampilkan berdampingan
    supaya tidak ada yang keliru membaca "markup 80%" sebagai "untung 80% dari omzet".

    Angka dalam <span> tersendiri karena _hitung.blade.php menimpanya saat harga diketik;
    keterangan "dari harga" / "dari HPP" di sebelahnya tidak ikut tertimpa.
--}}
@php
    $m = $sc['margin_percent'] ?? null;
    $u = $sc['markup_percent'] ?? null;
    $fmt = fn (?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.') . '%';

    $warnaMargin = $m === null ? 'text-slate-300' : ($m < 0 ? 'text-red-600' : ($m < 20 ? 'text-amber-600' : 'text-emerald-600'));
    $warnaMarkup = $u === null ? 'text-slate-300' : ($u < 0 ? 'text-red-600' : 'text-indigo-600');
@endphp

<span class="block text-xs font-bold js-margin-wrap {{ $warnaMargin }}"
      title="Margin = laba ÷ harga jual. Ukuran standar laporan keuangan; tidak pernah lewat 100%.">
    <span class="js-margin">{{ $fmt($m) }}</span>
    <span class="font-semibold opacity-70">dari harga</span>
</span>
<span class="block text-xs font-bold js-markup-wrap {{ $warnaMarkup }}"
      title="Markup = laba ÷ HPP. Berapa kali lipat modalnya; dipakai saat menetapkan harga.">
    <span class="js-markup">{{ $fmt($u) }}</span>
    <span class="font-semibold opacity-70">dari HPP</span>
</span>
