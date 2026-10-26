@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4 max-w-5xl" x-data="pengaturanCnc({{ Js::from($produk) }})">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <span class="text-4xl">{{ $karyawan['ikon'] }}</span>
            <div>
                <h1 class="text-xl font-bold text-slate-800">Pengaturan · Isi antrean CNC</h1>
                <p class="text-xs text-slate-500">{{ $karyawan['nama'] }} · Divisi {{ $karyawan['divisi'] }}</p>
                <p class="text-sm text-slate-600 mt-1 max-w-3xl">
                    Mesin <b>double head</b> jauh lebih cepat dari single head. Produk kelompok <b>Mesin 1</b> dipantau
                    tersendiri: bila antrean CNC tak berisi satu pun produk Mesin 1, Manager Produksi membuatkan OP-nya.
                    Sampel Waktu Produksi yang dikerjakan di mesin lain kelompok selalu dicoret.
                </p>
            </div>
        </div>
        <a href="{{ route('karyawan-virtual.show', $kode) }}" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded text-sm">Kembali</a>
    </div>

    @unless ($bolehUbah)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded p-3 mb-4">
            Hanya dapat dilihat. Mengubah pengaturan butuh akses menu Pengaturan Produksi.
        </div>
    @endunless

    <form method="POST" action="{{ route('karyawan-virtual.cnc.pengaturan.simpan', $kode) }}">
        @csrf

        {{-- Mesin --}}
        <h2 class="font-semibold text-slate-800 mb-2">Mesin CNC</h2>
        <div class="bg-white rounded shadow p-4 mb-6 flex flex-wrap gap-4 text-sm">
            @forelse ($mesin as $m)
                <label class="flex items-center gap-2 border rounded px-3 py-2 {{ $bolehUbah ? 'cursor-pointer' : '' }}">
                    <input type="hidden" name="mesin[]" value="{{ $m->id }}">
                    <input type="checkbox" name="kepala_ganda[]" value="{{ $m->id }}" @checked($m->kepala_ganda) @disabled(!$bolehUbah)
                           class="rounded border-gray-300 text-blue-600">
                    <span class="font-semibold text-slate-700">{{ $m->name }}</span>
                    <span class="text-xs text-slate-400">double head</span>
                </label>
            @empty
                <span class="text-gray-400">Belum ada mesin di divisi CNC (eksekutor ber-induk).</span>
            @endforelse
        </div>

        {{-- Produk --}}
        <div class="flex flex-wrap items-end justify-between gap-3 mb-2">
            <h2 class="font-semibold text-slate-800">
                Produk ber-BOM CNC
                <span class="text-xs font-normal text-slate-500" x-text="'· ' + jumlahGanda() + ' Mesin 1 · ' + jumlahAndalan() + ' andalan'"></span>
            </h2>
            <div class="flex items-center gap-2 text-sm">
                <input type="text" x-model="cari" placeholder="Cari SKU / nama…" class="border rounded px-2 py-1.5 w-56">
                <select x-model="saring" class="border rounded px-2 py-1.5">
                    <option value="">Semua</option>
                    <option value="ganda">Mesin 1</option>
                    <option value="tunggal">Mesin 2 &amp; 3</option>
                    <option value="andalan">Produk andalan</option>
                </select>
            </div>
        </div>

        <div class="bg-white rounded shadow overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="text-left px-3 py-2">Produk</th>
                        <th class="text-right px-3 py-2 w-24">Laku/hari</th>
                        <th class="text-right px-3 py-2 w-24" title="Stok tersedia ÷ laku per hari">Cukup</th>
                        <th class="text-center px-3 py-2 w-56">Mesin</th>
                        <th class="text-center px-3 py-2 w-28" title="Tingkat 1: dibuatkan OP walau stok masih banyak (maks 2 OP berjalan)">Andalan</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="p in rows" :key="p.id">
                        <tr class="border-t border-gray-100" x-show="tampil(p)">
                            <td class="px-3 py-2">
                                <input type="hidden" name="produk[]" :value="p.id">
                                <div class="font-semibold text-slate-800" x-text="p.sku"></div>
                                <div class="text-xs text-slate-500">
                                    <span x-text="p.nama"></span>
                                    <span x-show="!p.ready" class="ml-1 text-[10px] bg-gray-100 text-gray-500 px-1 rounded">pre-order</span>
                                    <span x-show="!p.aktif" class="ml-1 text-[10px] bg-red-50 text-red-600 px-1 rounded">nonaktif</span>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-xs" x-text="p.laku ? p.laku.toLocaleString('id-ID') : '—'"></td>
                            <td class="px-3 py-2 text-right font-mono text-xs" x-text="p.cukup === null ? '∞' : p.cukup.toLocaleString('id-ID') + ' hr'"></td>
                            <td class="px-3 py-2 text-center">
                                <input type="checkbox" name="ganda[]" :value="p.id" x-model="p.ganda" class="hidden">
                                <div class="inline-flex rounded border overflow-hidden text-xs">
                                    <button type="button" @click="p.ganda = true" @disabled(!$bolehUbah)
                                            :class="p.ganda ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                                            class="px-2.5 py-1 font-semibold">Mesin 1</button>
                                    <button type="button" @click="p.ganda = false" @disabled(!$bolehUbah)
                                            :class="!p.ganda ? 'bg-slate-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                                            class="px-2.5 py-1 font-semibold border-l">Mesin 2 &amp; 3</button>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="checkbox" name="andalan[]" :value="p.id" x-model="p.andalan"
                                       :disabled="!p.ready || {{ $bolehUbah ? 'false' : 'true' }}"
                                       :title="p.ready ? '' : 'Hanya produk ready stok'"
                                       class="rounded border-gray-300 text-amber-500">
                            </td>
                        </tr>
                    </template>
                    <tr x-show="!rows.some(p => tampil(p))">
                        <td colspan="5" class="px-3 py-6 text-center text-gray-400">Tidak ada produk yang cocok.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($bolehUbah)
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded text-sm font-bold">Simpan Pengaturan</button>
        @endif
    </form>
</div>

<script>
    function pengaturanCnc(produk) {
        return {
            rows: produk,
            cari: '',
            saring: '',
            tampil(p) {
                const q = this.cari.trim().toLowerCase();
                if (q && !(p.sku + ' ' + p.nama).toLowerCase().includes(q)) return false;
                if (this.saring === 'ganda') return p.ganda;
                if (this.saring === 'tunggal') return !p.ganda;
                if (this.saring === 'andalan') return p.andalan;
                return true;
            },
            jumlahGanda() { return this.rows.filter(p => p.ganda).length; },
            jumlahAndalan() { return this.rows.filter(p => p.andalan).length; },
        };
    }
</script>
@endsection
