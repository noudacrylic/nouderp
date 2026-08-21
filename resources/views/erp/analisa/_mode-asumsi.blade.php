{{--
    Penanda + saklar mode asumsi harga bahan. Dipakai HPP Ready, HPP Bundle, dan Harga Produk.

    Mode asumsi sengaja hidup di URL, bukan di pengaturan tersimpan, dan selalu memasang pita
    kuning selebar halaman: angka andaian yang menyamar jadi angka sebenarnya adalah cara
    tercepat menetapkan harga jual dari kebohongan.
--}}
@php
    $asumsiAktif  = request()->boolean('asumsi');
    $asumsiJumlah = \App\Modules\Analysis\Models\MaterialPriceAssumption::count();

    $qs = request()->query();
    unset($qs['asumsi'], $qs['page']);
    $urlNyala = request()->url() . '?' . http_build_query($qs + ['asumsi' => 1]);
    $urlMati  = request()->url() . ($qs ? '?' . http_build_query($qs) : '');
@endphp

@if($asumsiAktif)
    <div class="bg-amber-50 border border-amber-300 rounded-2xl px-5 py-3 mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-amber-900">
            <strong>Memakai harga bahan asumsi.</strong>
            {{ $asumsiJumlah }} bahan diandaikan &mdash; seluruh HPP, margin, dan markup di halaman ini
            <em>bukan</em> angka sebenarnya.
            <a href="{{ route('analisa.hpp.asumsi.index') }}" class="font-semibold underline">ubah asumsinya</a>
        </div>
        <a href="{{ $urlMati }}"
           class="bg-white border border-amber-300 text-amber-800 hover:bg-amber-100 px-3.5 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap">
            Kembali ke harga sebenarnya
        </a>
    </div>
@elseif($asumsiJumlah > 0)
    <div class="flex justify-end mb-3">
        <a href="{{ $urlNyala }}"
           class="border border-amber-300 text-amber-700 hover:bg-amber-50 px-3.5 py-1.5 rounded-xl text-xs font-bold">
            Lihat dengan {{ $asumsiJumlah }} asumsi bahan
        </a>
    </div>
@endif
