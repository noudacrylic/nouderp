{{--
    Pilihan merk akrilik + jumlah lembar. Ketuk merk = +1 lembar; bila sudah penuh (maks lembar OP),
    satu lembar dipindah dari merk terbanyak lain. Tombol − mengurangi. Fungsi di x-data index.
    $model = nama objek Alpine {merk: jumlah}; $maks = ekspresi JS batas lembar (null = tanpa batas).
--}}
<div class="flex flex-wrap gap-1.5">
    @foreach($merkAkrilik as $m)
        <div class="flex items-stretch rounded-xl border transition overflow-hidden select-none"
             :class="{{ $model }}[@js($m)] ? 'border-sky-400 bg-sky-50' : 'border-gray-200'">
            <button type="button" @click="tambahMerk({{ $model }}, @js($m), {{ $maks }})"
                    class="px-3 py-1.5 text-sm flex items-center gap-1.5"
                    :class="{{ $model }}[@js($m)] ? 'font-semibold text-sky-800' : 'text-gray-700 hover:bg-gray-50'">
                {{ $m }}
                <span x-show="{{ $model }}[@js($m)]" class="min-w-[1.25rem] px-1 rounded-full bg-sky-600 text-white text-xs font-bold text-center"
                      x-text="{{ $model }}[@js($m)]"></span>
            </button>
            <button type="button" x-show="{{ $model }}[@js($m)]" @click="kurangMerk({{ $model }}, @js($m))"
                    class="px-2 border-l border-sky-200 text-sky-700 hover:bg-sky-100 font-bold" title="Kurangi 1 lembar">−</button>
        </div>
    @endforeach
</div>
<p class="text-[11px] mt-1.5" :class="{{ $maks }} && totalMerk({{ $model }}) > {{ $maks }} ? 'text-red-600 font-semibold' : 'text-gray-500'">
    Ketuk merk = +1 lembar.
    Total <b x-text="totalMerk({{ $model }})"></b><span x-show="{{ $maks }}"> dari maks <b x-text="{{ $maks }}"></b></span> lembar.
</p>
<template x-for="[nm, n] in Object.entries({{ $model }})" :key="nm">
    <input type="hidden" :name="`merk[${nm}]`" :value="n">
</template>
