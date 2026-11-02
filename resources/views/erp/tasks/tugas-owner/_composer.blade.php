{{-- Kotak kirim satu aksi tugas: teks + rekam layar/suara + lampiran.
     Param: $t, $aksiKirim, $tombol, $placeholder, $warna (kelas tombol), $tersambung, $potongan, $maksMb,
            $wajibTeks (bool, tanpa perekam), $tanpaPerekam (bool). --}}
@php
    $wajibTeks = $wajibTeks ?? false;
    $tanpaPerekam = $tanpaPerekam ?? false;
    $warna = $warna ?? 'bg-blue-600 hover:bg-blue-700';
@endphp
<form method="POST" action="{{ route('tasks.meja.tugas.aksi', [$t->id, $aksiKirim]) }}" class="space-y-2"
      x-data="perekamTugas({ urlUnggah: @js(route('tasks.meja.tugas.unggah', $t->id)), potongan: {{ $potongan }}, maksMb: {{ $maksMb }} })">
    @csrf

    @unless ($tanpaPerekam)
        @if ($tersambung)
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-show="bisaLayar && !merekam" x-cloak @click="rekamLayar()"
                        class="px-3 py-2 rounded-lg text-sm font-semibold bg-red-600 hover:bg-red-700 text-white">🔴 Rekam layar + suara</button>
                <button type="button" x-show="bisaSuara && !merekam" x-cloak @click="rekamSuara()"
                        class="px-3 py-2 rounded-lg text-sm font-semibold border border-red-300 text-red-700 hover:bg-red-50">🎙️ Rekam suara</button>
                <label x-show="!merekam" class="px-3 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-700 hover:bg-gray-50 cursor-pointer">
                    📎 Lampirkan
                    <input type="file" multiple accept="image/*,video/*,audio/*" class="hidden" @change="pilihBerkas($event)">
                </label>
                <template x-if="merekam">
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-sm text-red-800">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-600 animate-pulse"></span>
                        <span x-text="(merekam === 'layar' ? 'Merekam layar ' : 'Merekam suara ') + formatDurasi(detik)"></span>
                        <button type="button" @click="berhenti()" class="px-3 py-1 rounded bg-red-600 text-white font-semibold">■ Selesai</button>
                    </div>
                </template>
            </div>
            <p x-show="bisaLayar" x-cloak class="text-[11px] text-gray-500">Saat merekam layar, pilih <b>Seluruh layar</b> kalau akan membuka aplikasi lain seperti File Manager; semua yang tampil di layar, termasuk notifikasi, ikut terekam. Pilih tab atau jendela kalau cukup memperlihatkan satu itu saja.</p>
            <p x-show="peringatan" x-cloak class="text-xs text-amber-700" x-text="peringatan"></p>
            <p x-show="galat" x-cloak class="text-xs text-red-600" x-text="galat"></p>

            <template x-for="it in antrian" :key="it.id">
                <div class="border border-gray-200 rounded-lg px-3 py-2 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-gray-700" x-text="it.nama + ' · ' + formatUkuran(it.berkas.size)"></span>
                        <span class="shrink-0 flex items-center gap-2 font-semibold">
                            <span :class="{ 'text-emerald-600': it.status === 'selesai', 'text-red-600': it.status === 'gagal', 'text-gray-500': it.status === 'antre' || it.status === 'jalan' }"
                                  x-text="it.status === 'selesai' ? '✓ siap dikirim' : (it.status === 'gagal' ? 'Gagal' : it.persen + '%')"></span>
                            <button type="button" x-show="it.status === 'gagal'" @click="ulangi(it)" class="underline text-blue-700">Ulangi</button>
                            <button type="button" x-show="it.status !== 'jalan'" @click="buang(it)" class="text-gray-400 hover:text-red-600" title="Buang">✕</button>
                        </span>
                    </div>
                    <div x-show="it.status === 'jalan'" class="h-1 bg-gray-100 rounded-full overflow-hidden mt-1">
                        <div class="h-full bg-blue-600 transition-all" :style="'width:' + it.persen + '%'"></div>
                    </div>
                    <p x-show="it.pesan" class="mt-0.5 text-[11px]" :class="it.status === 'gagal' ? 'text-red-600' : 'text-gray-500'" x-text="it.pesan"></p>
                </div>
            </template>
            <template x-for="id in selesaiIds()" :key="id">
                <input type="hidden" name="lampiran[]" :value="id">
            </template>
        @else
            <div class="rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm p-3">
                <b>NAS belum tersambung.</b> Rekaman dan lampiran belum bisa disimpan; pesan teks tetap bisa dikirim.
            </div>
        @endif
    @endunless

    <textarea name="isi" rows="{{ $wajibTeks ? 4 : 2 }}" maxlength="5000" @if ($wajibTeks) required @endif placeholder="{{ $placeholder }}"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('isi') }}</textarea>

    <button type="submit" :disabled="merekam || sibuk()"
            class="px-4 py-2 rounded-lg text-sm font-semibold text-white disabled:opacity-50 disabled:cursor-not-allowed {{ $warna }}">
        <span x-text="merekam ? 'Selesaikan rekaman dulu…' : (sibuk() ? 'Mengunggah…' : @js($tombol))">{{ $tombol }}</span>
    </button>
</form>
