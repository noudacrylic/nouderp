@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="penilaianDash()" x-cloak>
    <div class="mb-3">
        <h1 class="text-xl font-bold text-gray-800">Dashboard Penilaian — {{ $divisi->firstWhere('id', $deptId)?->name }}</h1>
        <p class="text-xs text-gray-500 max-w-5xl">
            Tiap OP punya <b>jatah waktu</b> = Σ (pcs × waktu standar). Rasio = timer ÷ jatah (di bawah 100% = lebih cepat).
            Poin: lebih cepat dari pita = <b class="text-emerald-700">2</b>, di dalam pita = <b>1</b>, lebih lambat = <b class="text-red-600">0</b>.
            Nilai = rata-rata poin → tingkat bonus. Geser parameter di panel kiri — semua angka dihitung ulang seketika,
            <b>tidak tersimpan</b> sampai Anda tekan Simpan. Halaman ini baca-saja, belum memengaruhi gaji.
        </p>
    </div>

    {{-- Periode data --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 mb-4 flex flex-wrap items-end gap-4 text-sm">
        <input type="hidden" name="divisi" value="{{ $deptId }}">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Dari bulan</label>
            <input type="month" name="dari" value="{{ $dari->format('Y-m') }}" class="border border-gray-200 rounded-xl px-3 py-1.5">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Sampai bulan</label>
            <input type="month" name="sampai" value="{{ $sampai->format('Y-m') }}" class="border border-gray-200 rounded-xl px-3 py-1.5">
        </div>
        <div>
            <input type="hidden" name="simulasi" value="0">
            <label class="inline-flex items-center gap-2 pb-2">
                <input type="checkbox" name="simulasi" value="1" @checked($simulasi) class="rounded">
                Simulasi: SKU tanpa standar disetujui pakai usulan median
            </label>
        </div>
        <button class="px-4 py-1.5 rounded-xl border border-gray-300 text-gray-700 hover:bg-gray-50 font-semibold">Muat data</button>
        @if($simulasi && $standarSimulasi)
            <div class="w-full text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5">
                <b>{{ $standarSimulasi }} SKU</b> memakai usulan median (belum disetujui). Median dihitung dari 5 bulan terakhir — termasuk bulan yang dinilai,
                jadi hasil simulasi cenderung mendekati normal. Angka jadi resmi setelah standar disetujui di tab Waktu Standar.
            </div>
        @endif
    </form>

    <div class="flex flex-col xl:flex-row gap-4 items-start">
        {{-- ================= PANEL PARAMETER ================= --}}
        <form method="POST" action="{{ route('production.waktu-standar.penilaian.simpan') }}"
              class="w-full xl:w-80 shrink-0 xl:sticky xl:top-4 bg-white rounded-2xl border border-gray-200 shadow-sm p-4 text-sm space-y-4">
            @csrf
            <div class="flex items-center justify-between">
                <h2 class="font-bold text-gray-800">Parameter</h2>
                <span x-show="berubah()" class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-semibold">belum disimpan</span>
            </div>

            <div>
                <div class="flex justify-between text-xs font-semibold text-gray-600">
                    <span>Pita "sesuai jatah"</span><span>± <span x-text="p.pitaPct"></span>%</span>
                </div>
                <input type="range" min="0" max="30" step="1" x-model.number="p.pitaPct" class="w-full">
                <p class="text-[11px] text-gray-400">
                    Timer <span x-text="100 - p.pitaPct"></span>%–<span x-text="100 + p.pitaPct"></span>% dari jatah = 1 poin.
                    Makin lebar → makin banyak OP dapat 1, makin sedikit 2 dan 0.
                </p>
            </div>

            <div>
                <div class="flex justify-between text-xs font-semibold text-gray-600">
                    <span>Batas "perlu dicek"</span><span><span x-text="fmt(p.faktor, 1)"></span>×</span>
                </div>
                <input type="range" min="1.5" max="4" step="0.1" x-model.number="p.faktor" class="w-full">
                <p class="text-[11px] text-gray-400">
                    Timer di bawah <span x-text="fmt(100 / p.faktor, 0)"></span>% atau di atas <span x-text="fmt(100 * p.faktor, 0)"></span>% jatah →
                    poin ditahan, tidak ikut rata-rata.
                </p>
            </div>

            <div class="flex items-center justify-between gap-2">
                <label class="text-xs font-semibold text-gray-600">Minimal OP agar nilai sah</label>
                <input type="number" min="0" max="100" x-model.number="p.min_op" class="w-20 border border-gray-200 rounded-lg px-2 py-1 text-right">
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-600 mb-1">Cara rata-rata poin</div>
                <label class="flex items-start gap-2 text-xs"><input type="radio" value="op" x-model="p.bobot" class="mt-0.5"> <span>Tiap OP sama berat</span></label>
                <label class="flex items-start gap-2 text-xs"><input type="radio" value="jatah" x-model="p.bobot" class="mt-0.5"> <span>Ditimbang jatah — OP besar lebih berpengaruh dari OP kecil</span></label>
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-600 mb-1">Tingkat bonus <span class="font-normal text-gray-400">(rata-rata poin ≥ ambang)</span></div>
                <table class="w-full text-xs">
                    <thead class="text-gray-400"><tr><th class="text-left font-normal">Nama</th><th class="text-right font-normal">Ambang</th><th class="text-right font-normal">Bonus/bln</th><th></th></tr></thead>
                    <tbody>
                    <template x-for="(t, i) in p.tingkat" :key="i">
                        <tr>
                            <td class="py-0.5 pr-1"><input type="text" x-model="t.label" maxlength="30" class="w-full border border-gray-200 rounded px-1.5 py-0.5"></td>
                            <td class="py-0.5 px-1">
                                <input type="number" step="0.05" min="0" max="2" x-model.number="t.min" :disabled="i === p.tingkat.length - 1"
                                       class="w-14 border border-gray-200 rounded px-1 py-0.5 text-right disabled:bg-gray-50 disabled:text-gray-400">
                            </td>
                            <td class="py-0.5 pl-1"><input type="number" step="10000" min="0" x-model.number="t.bonus" class="w-24 border border-gray-200 rounded px-1 py-0.5 text-right"></td>
                            <td class="pl-1">
                                <button type="button" x-show="p.tingkat.length > 2" @click="p.tingkat.splice(i, 1)" class="text-gray-400 hover:text-red-600" title="Hapus tingkat">×</button>
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
                <button type="button" x-show="p.tingkat.length < 6" @click="tambahTingkat()" class="mt-1 text-[11px] text-blue-700 hover:underline">+ tambah tingkat</button>
                <p class="text-[11px] text-gray-400 mt-1">Tingkat terbawah selalu mulai dari 0 (lantai). Urutan disusun otomatis dari ambang tertinggi.</p>
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-600 mb-1">Toleransi merk akrilik <span class="font-normal text-gray-400">(faktor jatah)</span></div>
                <table class="w-full text-xs">
                    <tbody>
                    <template x-for="m in d.merkDaftar" :key="'fm' + m">
                        <tr>
                            <td class="py-0.5" x-text="m"></td>
                            <td class="py-0.5 text-right">
                                <input type="number" step="0.05" min="0.5" max="2" x-model.number="p.faktorMerk[m]"
                                       :class="Number(p.faktorMerk[m]) !== 1 ? 'border-sky-400 bg-sky-50 font-bold' : 'border-gray-200'"
                                       class="w-16 border rounded px-1 py-0.5 text-right">×
                            </td>
                        </tr>
                    </template>
                    </tbody>
                </table>
                <p class="text-[11px] text-gray-400 mt-1">
                    1,00 = normal. Mis. MAG 1,25 → OP berbahan MAG dapat jatah 25% lebih lama. OP campur ditimbang per lembar: MAG 2 + NN 1 → (2×1,25 + 1×1)÷3 = 1,17.
                    Isi dari usulan di kartu <b>Analisa per merk</b> setelah datanya cukup.
                </p>
            </div>

            {{-- Nilai yang dikirim saat Simpan --}}
            <template x-for="m in d.merkDaftar" :key="'hm' + m">
                <template x-if="Number(p.faktorMerk[m]) !== 1">
                    <input type="hidden" :name="`param[faktor_merk][${m}]`" :value="p.faktorMerk[m]">
                </template>
            </template>
            <input type="hidden" name="param[pita]" :value="p.pitaPct / 100">
            <input type="hidden" name="param[faktor_cek]" :value="p.faktor">
            <input type="hidden" name="param[min_op]" :value="p.min_op">
            <input type="hidden" name="param[bobot]" :value="p.bobot">
            <template x-for="(t, i) in p.tingkat" :key="'h' + i">
                <span>
                    <input type="hidden" :name="`param[tingkat][${i}][label]`" :value="t.label">
                    <input type="hidden" :name="`param[tingkat][${i}][min]`" :value="i === p.tingkat.length - 1 ? 0 : t.min">
                    <input type="hidden" :name="`param[tingkat][${i}][bonus]`" :value="t.bonus">
                </span>
            </template>

            <div class="flex flex-wrap gap-2 pt-1 border-t border-gray-100">
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-xl font-semibold disabled:opacity-40" :disabled="!berubah()">Simpan</button>
                <button type="button" @click="muat(d.param)" x-show="berubah()" class="px-3 py-1.5 rounded-xl border border-gray-300 text-gray-700 hover:bg-gray-50 text-xs">Batalkan perubahan</button>
                <button type="button" @click="muat(d.default)" class="px-3 py-1.5 rounded-xl border border-gray-200 text-gray-500 hover:bg-gray-50 text-xs">Isi default</button>
            </div>
        </form>

        {{-- ================= ISI DASHBOARD ================= --}}
        <div class="flex-1 min-w-0 space-y-4">

            {{-- Pilih bulan --}}
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-xs text-gray-500">Bulan:</span>
                <template x-for="b in d.bulan" :key="b.kunci">
                    <button type="button" @click="bulan = b.kunci"
                            :class="bulan === b.kunci ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                            class="px-3 py-1 rounded-lg border border-gray-200 font-semibold" x-text="b.label"></button>
                </template>
            </div>

            {{-- Kartu ringkas --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                    <div class="text-xs text-gray-500">Total bonus kinerja</div>
                    <div class="text-xl font-bold" x-text="rp(totalBonus(bulan, p))"></div>
                    <div class="text-[11px]" x-show="berubah()"
                         :class="totalBonus(bulan, p) - totalBonus(bulan, pTersimpan()) > 0 ? 'text-red-600' : 'text-emerald-700'">
                        <span x-text="selisihTeks(totalBonus(bulan, p) - totalBonus(bulan, pTersimpan()))"></span> dibanding parameter tersimpan
                    </div>
                    <div class="text-[11px] text-gray-400" x-text="`${ringkas(bulan, p).length} orang dinilai`"></div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                    <div class="text-xs text-gray-500">Rata-rata poin tim</div>
                    <div class="text-xl font-bold" x-text="fmt(rataTim(bulan, p), 2)"></div>
                    <div class="text-[11px] text-gray-400">1,00 = tepat sesuai standar</div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                    <div class="text-xs text-gray-500">OP bulan ini</div>
                    <div class="text-xl font-bold"><span x-text="hitungOp(bulan, p).dinilai"></span> <span class="text-sm font-normal text-gray-500">dinilai</span></div>
                    <div class="text-[11px]">
                        <span class="text-amber-700" x-text="`${hitungOp(bulan, p).cek} ditahan`"></span> ·
                        <span class="text-gray-500" x-text="`${hitungOp(bulan, p).tanpa} tanpa standar`"></span>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3">
                    <div class="text-xs text-gray-500 mb-1">Sebaran orang per tingkat</div>
                    <template x-for="t in urutTingkat(p)" :key="t.label + t.min">
                        <div class="flex items-center justify-between text-xs">
                            <span><span class="inline-block w-2 h-2 rounded-full mr-1" :class="warnaTingkat(t, p).dot"></span><span x-text="t.label"></span></span>
                            <b x-text="ringkas(bulan, p).filter(r => r.tingkat && r.tingkat.label === t.label).length"></b>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Tabel per pelaksana --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
                <div class="px-4 pt-3 pb-1 text-sm font-bold text-gray-700">Nilai per pelaksana · <span x-text="labelBulan(bulan)"></span></div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="text-left px-4 py-2">Pelaksana</th>
                            <th class="text-right px-3 py-2">OP</th>
                            <th class="text-right px-3 py-2">2 / 1 / 0</th>
                            <th class="text-right px-3 py-2">Rata poin</th>
                            <th class="text-right px-3 py-2" title="Σ jatah ÷ Σ timer — di atas 100% = lebih cepat dari standar">Efisiensi</th>
                            <th class="text-right px-3 py-2">Ditahan</th>
                            <th class="text-right px-3 py-2">Tanpa std</th>
                            <th class="text-left px-3 py-2">Tingkat</th>
                            <th class="text-right px-3 py-2">Bonus</th>
                        </tr>
                    </thead>
                    <tbody>
                    <template x-for="r in ringkas(bulan, p)" :key="r.id">
                        <tr class="border-t border-gray-50 cursor-pointer hover:bg-blue-50/40" :class="orang === r.id ? 'bg-blue-50' : ''" @click="orang = orang === r.id ? null : r.id">
                            <td class="px-4 py-2 font-semibold">
                                <span x-text="r.nama"></span>
                                <span x-show="r.percobaan" class="text-[10px] px-1 rounded bg-amber-50 text-amber-700 font-normal">percobaan</span>
                                <span x-show="!r.aktif" class="text-[10px] text-gray-400 font-normal">(nonaktif)</span>
                            </td>
                            <td class="px-3 py-2 text-right" x-text="r.n"></td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <span class="text-emerald-700" x-text="r.p2"></span> / <span x-text="r.p1"></span> / <span class="text-red-600" x-text="r.p0"></span>
                            </td>
                            <td class="px-3 py-2 text-right font-bold" x-text="fmt(r.rata, 2)"></td>
                            <td class="px-3 py-2 text-right" x-text="pct(r.efisiensi)"></td>
                            <td class="px-3 py-2 text-right" :class="r.cek ? 'text-amber-700 font-semibold' : 'text-gray-300'" x-text="r.cek"></td>
                            <td class="px-3 py-2 text-right text-gray-500" x-text="r.tanpa"></td>
                            <td class="px-3 py-2">
                                <template x-if="r.tingkat">
                                    <span class="px-2 py-0.5 rounded-lg text-xs font-semibold" :class="warnaTingkat(r.tingkat, p).chip" x-text="r.tingkat.label"></span>
                                </template>
                                <span x-show="!r.tingkat" class="text-gray-300">—</span>
                                <div x-show="r.tingkat && !r.cukup" class="text-[11px] text-amber-700" x-text="`OP < ${p.min_op} — belum bisa disimpulkan`"></div>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold" x-text="r.tingkat ? rp(r.tingkat.bonus) : '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="!ringkas(bulan, p).length"><td colspan="9" class="px-4 py-6 text-center text-gray-400">Belum ada OP yang bisa dinilai di bulan ini.</td></tr>
                    </tbody>
                </table>
                <p class="px-4 py-2 text-[11px] text-gray-400">Klik baris untuk melihat sebaran & rincian OP orang itu.</p>
            </div>

            {{-- Peta nilai: posisi tiap orang terhadap ambang --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <div class="text-sm font-bold text-gray-700">Posisi nilai terhadap ambang</div>
                <p class="text-[11px] text-gray-400 mb-3">Garis = ambang tingkat. Geser ambang di panel kiri untuk melihat siapa yang pindah tingkat.</p>
                <div class="space-y-1.5">
                    <template x-for="r in ringkas(bulan, p)" :key="'peta' + r.id">
                        <div class="flex items-center gap-3 text-xs">
                            <div class="w-32 truncate font-semibold" x-text="r.nama"></div>
                            <div class="relative flex-1 h-6 rounded-md overflow-hidden flex">
                                <template x-for="(seg, i) in segmenTingkat(p)" :key="i">
                                    <div class="h-full" :class="seg.bg" :style="`width:${seg.lebar}%`"></div>
                                </template>
                                <template x-for="t in urutTingkat(p).slice(0, -1)" :key="'g' + t.min">
                                    <div class="absolute top-0 bottom-0 w-px bg-gray-500/60" :style="`left:${posisi(t.min)}%`"></div>
                                </template>
                                <div x-show="r.rata !== null" class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-3.5 h-3.5 rounded-full border-2 border-white shadow"
                                     :class="r.cukup ? 'bg-gray-900' : 'bg-gray-400'" :style="`left:${posisi(r.rata)}%`" :title="fmt(r.rata, 2)"></div>
                            </div>
                            <div class="w-12 text-right font-bold" x-text="fmt(r.rata, 2)"></div>
                        </div>
                    </template>
                    <div class="flex items-center gap-3 text-[10px] text-gray-400">
                        <div class="w-32"></div>
                        <div class="relative flex-1 h-3">
                            <template x-for="v in [0, 0.5, 1, 1.5, 2]" :key="v">
                                <span class="absolute -translate-x-1/2" :style="`left:${posisi(v)}%`" x-text="fmt(v, 1)"></span>
                            </template>
                        </div>
                        <div class="w-12"></div>
                    </div>
                </div>
            </div>

            {{-- Sebaran rasio timer/jatah --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="text-sm font-bold text-gray-700">
                        Sebaran timer ÷ jatah · <span x-text="orang ? namaOrang(orang) : 'semua pelaksana'"></span> · <span x-text="labelBulan(bulan)"></span>
                    </div>
                    <button type="button" x-show="orang" @click="orang = null" class="text-xs text-blue-700 hover:underline">tampilkan semua</button>
                </div>
                <p class="text-[11px] text-gray-400 mb-3">
                    Tiap batang = jumlah OP. <span class="text-emerald-700 font-semibold">Hijau</span> = 2 poin,
                    <span class="text-gray-500 font-semibold">abu</span> = 1 poin, <span class="text-red-600 font-semibold">merah</span> = 0 poin,
                    <span class="text-amber-600 font-semibold">kuning</span> = ditahan. Pita yang lebih lebar memindahkan batang dari hijau/merah ke abu.
                </p>
                <div class="flex items-end gap-px h-40">
                    <template x-for="h in histogram(bulan, orang, p)" :key="h.lo">
                        <div class="flex-1 flex flex-col justify-end items-center h-full" :title="`${h.label}: ${h.n} OP`">
                            <span class="text-[9px] text-gray-500" x-show="h.n" x-text="h.n"></span>
                            <div class="w-full rounded-t" :class="h.warna" :style="`height:${h.tinggi}%`"></div>
                        </div>
                    </template>
                </div>
                <div class="flex gap-px text-[9px] text-gray-400 mt-1">
                    <template x-for="h in histogram(bulan, orang, p)" :key="'l' + h.lo">
                        <div class="flex-1 text-center" x-text="h.tick"></div>
                    </template>
                </div>
            </div>

            {{-- Analisa per merk akrilik (semua bulan yang dimuat, jatah dasar tanpa faktor) --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto">
                <div class="px-4 pt-3 text-sm font-bold text-gray-700">Analisa per merk akrilik
                    <span class="font-normal text-xs text-gray-400">· semua bulan dimuat (<span x-text="d.bulan.map(b => b.label).join(', ')"></span>)</span>
                </div>
                <p class="px-4 text-[11px] text-gray-500 mb-2 max-w-4xl">
                    Merk dipilih operator CNC saat Mulai. <b>Relatif orang</b> = timer÷jatah dibanding kebiasaan pelaksananya sendiri,
                    supaya merk tidak tampak sulit hanya karena kebetulan dikerjakan orang yang lebih lambat.
                    OP dihitung untuk merk yang memakai ≥ separuh lembarnya; pembanding = OP tanpa merk itu sama sekali. <b>Usulan faktor</b> = relatif merk ini ÷ relatif pembanding; muncul bila minimal <span x-text="MIN_MERK"></span> OP.
                    OP "perlu dicek" (di luar batas) tidak ikut.
                </p>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500">
                        <tr>
                            <th class="text-left px-4 py-2">Merk</th>
                            <th class="text-right px-3 py-2" title="OP yang separuh lembarnya atau lebih memakai merk ini">OP</th>
                            <th class="text-right px-3 py-2" title="Median timer ÷ jatah dasar">Timer/jatah</th>
                            <th class="text-right px-3 py-2" title="Median timer÷jatah dibagi kebiasaan pelaksananya; 100% = seperti biasanya">Relatif orang</th>
                            <th class="text-right px-3 py-2" title="Dibanding OP bermerk lain">Selisih</th>
                            <th class="text-right px-3 py-2">Usulan faktor</th>
                            <th class="text-right px-3 py-2">Faktor dipakai</th>
                        </tr>
                    </thead>
                    <tbody>
                    <template x-for="r in analisaMerk(p)" :key="'am' + r.merk">
                        <tr class="border-t border-gray-50">
                            <td class="px-4 py-1.5 font-semibold" x-text="r.merk"></td>
                            <td class="px-3 py-1.5 text-right" x-text="r.n"></td>
                            <td class="px-3 py-1.5 text-right" x-text="pct(r.rasio)"></td>
                            <td class="px-3 py-1.5 text-right" x-text="pct(r.relatif)"></td>
                            <td class="px-3 py-1.5 text-right font-semibold" :class="r.selisih > 0.05 ? 'text-red-600' : r.selisih < -0.05 ? 'text-emerald-700' : 'text-gray-500'"
                                x-text="r.selisih === null ? '—' : (r.selisih > 0 ? '+' : '') + Math.round(r.selisih * 100) + '%'"></td>
                            <td class="px-3 py-1.5 text-right">
                                <template x-if="r.usulan !== null">
                                    <button type="button" @click="p.faktorMerk[r.merk] = r.usulan" class="text-sky-700 hover:underline font-semibold"
                                            :title="'Pakai ' + fmt(r.usulan, 2) + '× untuk ' + r.merk" x-text="fmt(r.usulan, 2) + '×'"></button>
                                </template>
                                <span x-show="r.usulan === null" class="text-[11px] text-gray-400" x-text="r.n ? 'data belum cukup' : '—'"></span>
                            </td>
                            <td class="px-3 py-1.5 text-right font-bold" :class="Number(p.faktorMerk[r.merk] ?? 1) !== 1 ? 'text-sky-700' : 'text-gray-400'"
                                x-text="fmt(p.faktorMerk[r.merk] ?? 1, 2) + '×'"></td>
                        </tr>
                    </template>
                    <tr class="border-t border-gray-100 text-gray-500 text-xs">
                        <td class="px-4 py-1.5">Merk belum tercatat</td>
                        <td class="px-3 py-1.5 text-right" x-text="d.op.filter(o => !(o.merk || []).length).length"></td>
                        <td colspan="5" class="px-3 py-1.5">OP sebelum pemilihan merk berlaku — jatahnya tidak dikali faktor apa pun.</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            {{-- Tren antarbulan --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-x-auto" x-show="d.bulan.length > 1">
                <div class="px-4 pt-3 pb-1 text-sm font-bold text-gray-700">Tren per bulan <span class="font-normal text-xs text-gray-400">(rata poin · efisiensi · bonus)</span></div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500">
                        <tr>
                            <th class="text-left px-4 py-2">Pelaksana</th>
                            <template x-for="b in d.bulan" :key="'th' + b.kunci"><th class="text-center px-3 py-2" x-text="b.label"></th></template>
                        </tr>
                    </thead>
                    <tbody>
                    <template x-for="o in d.pelaksana" :key="'tr' + o.id">
                        <tr class="border-t border-gray-50">
                            <td class="px-4 py-2 font-semibold" x-text="o.nama"></td>
                            <template x-for="b in d.bulan" :key="'td' + o.id + b.kunci">
                                <td class="px-3 py-1.5 text-center">
                                    <template x-if="nilai(o.id, b.kunci, p).n">
                                        <div>
                                            <span class="px-1.5 py-0.5 rounded font-bold text-xs" :class="warnaTingkat(nilai(o.id, b.kunci, p).tingkat, p).chip"
                                                  x-text="fmt(nilai(o.id, b.kunci, p).rata, 2)"></span>
                                            <div class="text-[10px] text-gray-500">
                                                <span x-text="pct(nilai(o.id, b.kunci, p).efisiensi)"></span> ·
                                                <span x-text="rp(nilai(o.id, b.kunci, p).tingkat.bonus)"></span>
                                                <span x-show="!nilai(o.id, b.kunci, p).cukup" class="text-amber-700" x-text="`(${nilai(o.id, b.kunci, p).n} OP)`"></span>
                                            </div>
                                        </div>
                                    </template>
                                    <span x-show="!nilai(o.id, b.kunci, p).n" class="text-gray-300">—</span>
                                </td>
                            </template>
                        </tr>
                    </template>
                    <tr class="border-t border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2">Total bonus</td>
                        <template x-for="b in d.bulan" :key="'tot' + b.kunci"><td class="px-3 py-2 text-center" x-text="rp(totalBonus(b.kunci, p))"></td></template>
                    </tr>
                    </tbody>
                </table>
            </div>

            {{-- Rincian per orang --}}
            <template x-if="orang">
                <div class="bg-white rounded-2xl border border-blue-200 shadow-sm p-4">
                    <div class="flex items-baseline justify-between">
                        <div class="text-sm font-bold text-gray-700">Rincian OP · <span x-text="namaOrang(orang)"></span> · <span x-text="labelBulan(bulan)"></span></div>
                        <button type="button" @click="orang = null" class="text-xs text-gray-500 hover:text-gray-800">tutup</button>
                    </div>
                    <div class="flex flex-wrap gap-2 my-3 text-xs">
                        <template x-for="w in mingguan(orang, bulan, p)" :key="w.minggu">
                            <div class="bg-gray-50 border border-gray-200 rounded-lg px-2 py-1">
                                <div class="text-gray-400" x-text="`Minggu ${w.minggu}`"></div>
                                <b x-text="fmt(w.rata, 2)"></b> poin · <span x-text="pct(w.efisiensi)"></span> · <span x-text="w.n"></span> OP
                            </div>
                        </template>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-gray-400">
                            <tr><th class="text-left py-1">OP</th><th class="text-left">Selesai</th><th class="text-left">Isi (pcs × standar mnt)</th>
                                <th class="text-right">Jatah</th><th class="text-right">Timer</th><th class="text-right">Timer/jatah</th><th class="text-right">Poin</th><th class="text-left pl-3">Keterangan</th></tr>
                        </thead>
                        <tbody>
                        <template x-for="o in opOrang(orang, bulan, p)" :key="o.op + o.tgl">
                            <tr class="border-t border-gray-100" :class="status(o, p) === 'cek' ? 'bg-amber-50' : ''">
                                <td class="py-1"><a :href="o.url" class="hover:underline" x-text="o.op"></a></td>
                                <td x-text="o.tgl"></td>
                                <td><span x-text="o.isi"></span><sup x-show="o.sim" class="text-amber-600" title="memakai usulan median, belum disetujui"> s</sup>
                                    <span x-show="(o.merk || []).length" class="ml-1 px-1 rounded bg-sky-50 text-sky-700" x-text="'🧱 ' + (o.merk || []).map(m => m + ((o.mj || {})[m] ? ' ' + o.mj[m] : '')).join(' + ')"></span></td>
                                <td class="text-right" :title="o.f ? `jatah dasar × ${fmt(o.f, 2)} (toleransi merk)` : ''">
                                    <span x-text="fmt(o.jatah, 0) + ' mnt'"></span><span x-show="o.f" class="text-sky-700" x-text="' ×' + fmt(o.f, 2)"></span></td>
                                <td class="text-right" x-text="fmt(o.menit, 0) + ' mnt'"></td>
                                <td class="text-right" x-text="pct(o.rasio)"></td>
                                <td class="text-right font-bold" :class="status(o, p) === 'cek' ? 'text-gray-300 line-through' : warnaPoin(poin(o.rasio, p))"
                                    x-text="o.rasio === null ? '—' : poin(o.rasio, p)"></td>
                                <td class="pl-3 text-amber-700" x-text="status(o, p) === 'cek' ? (o.rasio === null ? 'Perlu dicek: timer kosong' : (o.rasio < 1 ? 'Perlu dicek: jauh lebih cepat dari jatah' : 'Perlu dicek: jauh lebih lambat dari jatah')) : ''"></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                    </div>
                </div>
            </template>

            {{-- Produk tanpa standar --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-sm" x-show="skuTanpaStandar(bulan).length">
                <div class="font-semibold text-gray-700 mb-1"><span x-text="hitungOp(bulan, p).tanpa"></span> OP tidak dinilai — ada produk tanpa waktu standar</div>
                <p class="text-xs text-gray-500 mb-2">Isi standarnya manual di tab Waktu Standar (produk jarang: tiru produk mirip) supaya OP ini ikut dinilai.</p>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <template x-for="s in skuTanpaStandar(bulan)" :key="s.sku">
                        <span class="px-2 py-0.5 rounded-lg bg-gray-100 text-gray-700"><span x-text="s.sku"></span> <span class="text-gray-400" x-text="'×' + s.n"></span></span>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const DATA_PENILAIAN = @json($data);

function penilaianDash() {
    const WARNA = [
        { chip: 'bg-emerald-100 text-emerald-800', dot: 'bg-emerald-500', bg: 'bg-emerald-100' },
        { chip: 'bg-blue-100 text-blue-800',       dot: 'bg-blue-500',    bg: 'bg-blue-100' },
        { chip: 'bg-amber-100 text-amber-800',     dot: 'bg-amber-500',   bg: 'bg-amber-100' },
        { chip: 'bg-red-100 text-red-800',         dot: 'bg-red-500',     bg: 'bg-red-100' },
        { chip: 'bg-purple-100 text-purple-800',   dot: 'bg-purple-500',  bg: 'bg-purple-100' },
        { chip: 'bg-gray-200 text-gray-800',       dot: 'bg-gray-500',    bg: 'bg-gray-200' },
    ];
    const d = DATA_PENILAIAN;
    const cacheOps = {}; // daftar OP berfaktor merk, per kombinasi faktor (p & tersimpan bergantian)
    const faktorDari = src => Object.fromEntries(d.merkDaftar.map(m => [m, Number((src.faktor_merk || {})[m] ?? 1)]));

    return {
        d,
        MIN_MERK: 10,
        p: {},
        bulan: d.bulan.length ? d.bulan[d.bulan.length - 1].kunci : null,
        orang: null,

        init() { this.muat(d.param); },

        muat(src) {
            this.p = {
                pitaPct: Math.round((src.pita ?? 0.1) * 100),
                faktor: Number(src.faktor_cek ?? 2),
                min_op: Number(src.min_op ?? 10),
                bobot: src.bobot === 'jatah' ? 'jatah' : 'op',
                tingkat: (src.tingkat || []).map(t => ({ label: t.label, min: Number(t.min), bonus: Number(t.bonus) })),
                faktorMerk: faktorDari(src),
            };
        },
        pTersimpan() {
            const s = d.param;
            return { pitaPct: Math.round(s.pita * 100), faktor: Number(s.faktor_cek), min_op: Number(s.min_op),
                     bobot: s.bobot === 'jatah' ? 'jatah' : 'op',
                     tingkat: s.tingkat.map(t => ({ label: t.label, min: Number(t.min), bonus: Number(t.bonus) })),
                     faktorMerk: faktorDari(s) };
        },
        berubah() { return JSON.stringify(this.p) !== JSON.stringify(this.pTersimpan()); },
        tambahTingkat() {
            const t = this.urutTingkat(this.p);
            this.p.tingkat.splice(Math.max(0, this.p.tingkat.length - 1), 0, { label: 'Baru', min: t.length > 1 ? +(t[t.length - 2].min / 2).toFixed(2) : 0.5, bonus: 0 });
        },

        // ---------- format ----------
        fmt(v, n = 2) { return v === null || v === undefined || isNaN(v) ? '—' : Number(v).toLocaleString('id-ID', { minimumFractionDigits: n, maximumFractionDigits: n }); },
        pct(v) { return v === null || v === undefined ? '—' : Math.round(v * 100).toLocaleString('id-ID') + '%'; },
        rp(v) { return 'Rp' + Math.round(v || 0).toLocaleString('id-ID'); },
        selisihTeks(v) { return (v > 0 ? '+' : v < 0 ? '−' : '±') + this.rp(Math.abs(v)); },
        labelBulan(k) { return (d.bulan.find(b => b.kunci === k) || {}).label || ''; },
        namaOrang(id) { return (d.pelaksana.find(o => o.id === id) || {}).nama || ''; },
        warnaPoin(n) { return n === 2 ? 'text-emerald-700' : n === 0 ? 'text-red-600' : 'text-gray-700'; },

        // ---------- aturan ----------
        urutTingkat(p) {
            const t = [...p.tingkat].map(x => ({ ...x, min: Number(x.min) || 0 }));
            if (t.length) t[t.length - 1] = { ...p.tingkat[p.tingkat.length - 1], min: 0 }; // baris terakhir = lantai
            return t.sort((a, b) => b.min - a.min);
        },
        tingkatDari(rata, p) {
            if (rata === null) return null;
            const t = this.urutTingkat(p);
            return t.find(x => rata >= x.min - 1e-9) || t[t.length - 1];
        },
        warnaTingkat(t, p) {
            if (!t) return WARNA[5];
            const i = this.urutTingkat(p).findIndex(x => x.label === t.label && x.min === t.min);
            return WARNA[Math.max(0, i)] || WARNA[5];
        },
        status(o, p) {
            if (o.rasio === null) return 'cek';
            return (o.rasio < 1 / p.faktor || o.rasio > p.faktor) ? 'cek' : 'ok';
        },
        poin(r, p) {
            const pita = p.pitaPct / 100;
            if (r <= 1 - pita + 1e-9) return 2;
            if (r <= 1 + pita + 1e-9) return 1;
            return 0;
        },

        // ---------- hitungan ----------
        // OP dengan jatah × faktor merk tersulit (d.op = jatah dasar dari server).
        ops(p) {
            const fm = p.faktorMerk || {};
            const k = JSON.stringify(fm);
            return cacheOps[k] ||= d.op.map(o => {
                // Ada jumlah lembar → rata-rata tertimbang; data lama tanpa jumlah → merk tersulit.
                const mj = Object.entries(o.mj || {}).filter(([, n]) => n > 0);
                const tot = mj.reduce((s, [, n]) => s + n, 0);
                const fs = (o.merk || []).map(m => Number(fm[m]) || 1);
                const f = tot ? mj.reduce((s, [m, n]) => s + n * (Number(fm[m]) || 1), 0) / tot : (fs.length ? Math.max(...fs) : 1);
                return f === 1 ? o : { ...o, f, jatah: o.jatah * f, rasio: o.rasio === null ? null : o.rasio / f };
            });
        },
        opOrang(id, bulan, p) { return this.ops(p).filter(o => o.b === bulan && o.px.includes(id)); },

        // Per merk, semua bulan dimuat, jatah dasar. Relatif = rasio ÷ median rasio pelaksananya.
        analisaMerk(p) {
            const med = a => { if (!a.length) return null; const s = [...a].sort((x, y) => x - y), h = s.length >> 1; return s.length % 2 ? s[h] : (s[h - 1] + s[h]) / 2; };
            const sah = d.op.filter(o => o.rasio !== null && o.rasio >= 1 / p.faktor && o.rasio <= p.faktor);
            const perOrang = {};
            sah.forEach(o => o.px.forEach(id => (perOrang[id] ||= []).push(o.rasio)));
            const medOrang = Object.fromEntries(Object.entries(perOrang).map(([id, a]) => [id, med(a)]));
            const rel = o => { const m = o.px.map(id => medOrang[id]).filter(Boolean); return m.length ? o.rasio / (m.reduce((a, b) => a + b, 0) / m.length) : null; };
            const bermerk = sah.filter(o => (o.merk || []).length);
            const merkAda = [...new Set([...d.merkDaftar, ...bermerk.flatMap(o => o.merk)])];
            // Porsi lembar merk m di OP (data lama tanpa jumlah: dibagi rata antar merk).
            const porsi = (o, m) => {
                const mj = o.mj || {}, tot = Object.values(mj).reduce((s, n) => s + n, 0);
                return tot ? (mj[m] || 0) / tot : (o.merk.includes(m) ? 1 / o.merk.length : 0);
            };
            return merkAda.map(m => {
                const isi = bermerk.filter(o => porsi(o, m) >= 0.5);   // merk ini dominan
                const lain = bermerk.filter(o => porsi(o, m) === 0);   // sama sekali tanpa merk ini
                const rI = med(isi.map(rel).filter(x => x !== null)), rL = med(lain.map(rel).filter(x => x !== null));
                const selisih = rI !== null && rL ? rI / rL - 1 : null;
                const usulan = selisih !== null && isi.length >= this.MIN_MERK && lain.length >= this.MIN_MERK
                    ? Math.max(1, Math.min(2, Math.round((1 + selisih) * 20) / 20)) : null; // kelipatan 0,05; tak pernah < 1
                return { merk: m, n: isi.length, rasio: med(isi.map(o => o.rasio)), relatif: rI, selisih, usulan };
            });
        },

        nilai(id, bulan, p) {
            const semua = this.opOrang(id, bulan, p);
            const ok = semua.filter(o => this.status(o, p) === 'ok');
            const poin = ok.map(o => this.poin(o.rasio, p));
            let rata = null;
            if (ok.length) {
                if (p.bobot === 'jatah') {
                    const w = ok.reduce((s, o) => s + o.jatah, 0);
                    rata = w > 0 ? ok.reduce((s, o, i) => s + poin[i] * o.jatah, 0) / w : null;
                } else {
                    rata = poin.reduce((a, b) => a + b, 0) / poin.length;
                }
            }
            const jatah = ok.reduce((s, o) => s + o.jatah, 0);
            const timer = ok.reduce((s, o) => s + o.menit, 0);
            return {
                n: ok.length,
                p2: poin.filter(x => x === 2).length,
                p1: poin.filter(x => x === 1).length,
                p0: poin.filter(x => x === 0).length,
                cek: semua.length - ok.length,
                tanpa: d.tanpaStandar.filter(o => o.b === bulan && o.px.includes(id)).length,
                rata,
                efisiensi: timer > 0 ? jatah / timer : null,
                tingkat: this.tingkatDari(rata, p),
                cukup: ok.length >= p.min_op,
            };
        },
        ringkas(bulan, p) {
            return d.pelaksana
                .map(o => ({ ...o, ...this.nilai(o.id, bulan, p) }))
                .filter(r => r.n || r.cek || r.tanpa);
        },
        totalBonus(bulan, p) {
            return this.ringkas(bulan, p).reduce((s, r) => s + (r.tingkat ? Number(r.tingkat.bonus) || 0 : 0), 0);
        },
        rataTim(bulan, p) {
            const r = this.ringkas(bulan, p).filter(x => x.rata !== null);
            return r.length ? r.reduce((s, x) => s + x.rata, 0) / r.length : null;
        },
        hitungOp(bulan, p) {
            const ops = this.ops(p).filter(o => o.b === bulan);
            const cek = ops.filter(o => this.status(o, p) === 'cek').length;
            return { dinilai: ops.length - cek, cek, tanpa: d.tanpaStandar.filter(o => o.b === bulan).length };
        },
        mingguan(id, bulan, p) {
            const grup = {};
            this.opOrang(id, bulan, p).filter(o => this.status(o, p) === 'ok').forEach(o => { (grup[o.minggu] ||= []).push(o); });
            return Object.entries(grup).map(([minggu, g]) => ({
                minggu,
                n: g.length,
                rata: g.reduce((s, o) => s + this.poin(o.rasio, p), 0) / g.length,
                efisiensi: g.reduce((s, o) => s + o.jatah, 0) / Math.max(1e-9, g.reduce((s, o) => s + o.menit, 0)),
            }));
        },
        skuTanpaStandar(bulan) {
            const n = {};
            d.tanpaStandar.filter(o => o.b === bulan).forEach(o => o.kurang.forEach(s => { n[s] = (n[s] || 0) + 1; }));
            return Object.entries(n).map(([sku, c]) => ({ sku, n: c })).sort((a, b) => b.n - a.n);
        },

        // ---------- grafik ----------
        posisi(v) { return Math.max(0, Math.min(100, (Number(v) || 0) / 2 * 100)); },
        segmenTingkat(p) {
            // dari kiri (0) ke kanan (2): tingkat terendah dulu
            const t = this.urutTingkat(p).slice().reverse();
            return t.map((x, i) => {
                const atas = i + 1 < t.length ? Math.min(2, t[i + 1].min) : 2;
                const lebar = Math.max(0, (atas - Math.min(2, x.min)) / 2 * 100);
                return { lebar, bg: this.warnaTingkat(x, p).bg };
            });
        },
        histogram(bulan, orang, p) {
            // Rasio 40%–220% per 5%; ujung kiri/kanan menampung sisanya.
            const lebar = 0.05, lo0 = 0.4, hi0 = 2.2;
            const n = Math.round((hi0 - lo0) / lebar);
            const ops = this.ops(p).filter(o => o.b === bulan && o.rasio !== null && (!orang || o.px.includes(orang)));
            const kotak = [];
            for (let i = 0; i < n; i++) {
                const lo = +(lo0 + i * lebar).toFixed(2), hi = +(lo + lebar).toFixed(2);
                const isi = ops.filter(o => (i === 0 ? o.rasio < hi : i === n - 1 ? o.rasio >= lo : o.rasio >= lo && o.rasio < hi));
                const tengah = (lo + hi) / 2;
                const cek = tengah < 1 / p.faktor || tengah > p.faktor;
                const pn = this.poin(tengah, p);
                kotak.push({
                    lo, n: isi.length,
                    label: (i === 0 ? '< ' + Math.round(hi * 100) : i === n - 1 ? '≥ ' + Math.round(lo * 100) : Math.round(lo * 100) + '–' + Math.round(hi * 100)) + '%',
                    tick: Math.round(lo * 100) % 20 === 0 ? Math.round(lo * 100) + '%' : '',
                    warna: cek ? 'bg-amber-300' : pn === 2 ? 'bg-emerald-400' : pn === 1 ? 'bg-gray-300' : 'bg-red-400',
                });
            }
            const maks = Math.max(1, ...kotak.map(k => k.n));
            kotak.forEach(k => { k.tinggi = k.n ? Math.max(3, k.n / maks * 92) : 0; });
            return kotak;
        },
    };
}
</script>
@endpush
