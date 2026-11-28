@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $menit = fn (?float $sec) => $sec === null ? '—' : number_format($sec / 60, 1, ',', '.') . ' mnt';
    $r     = $result;
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Harga Custom &middot; {{ $recipe->label() }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                Harga produk yang belum pernah dibuat, dihitung dari ukurannya. Ukuran dipakai penuh tanpa koreksi
                tebal, jadi hasilnya sedikit lebih besar dari kenyataan &mdash; sengaja, supaya tidak rugi.
                Tarif waktu sama dengan HPP produk Ready.
            </p>
        </div>
        <a href="{{ route('analisa.harga-custom.pengaturan', ['kategori' => $category->code]) }}"
           class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Pengaturan</a>
    </div>

    @include('erp.analisa.harga-custom._kategori', ['routeName' => 'analisa.harga-custom.index'])

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">

        {{-- ── Form ── --}}
        <form method="GET" action="{{ route('analisa.harga-custom.index') }}" id="form-hitung"
              class="lg:col-span-2 bg-white border border-slate-100 rounded-2xl shadow-sm p-5 space-y-4">
            <input type="hidden" name="kategori" value="{{ $category->code }}">

            <div>
                <label class="block text-[11px] font-bold text-slate-500 mb-1">Nama produk (untuk teks ke customer)</label>
                <input type="text" name="nama" value="{{ $raw['nama'] ?? '' }}" placeholder="mis. Kotak Amal Akrilik"
                       class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full">
            </div>

            @include($recipe->formView())

            <div class="grid grid-cols-3 gap-3 pt-2 border-t border-slate-100">
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Jumlah pesanan (pcs)</label>
                    <input type="text" inputmode="numeric" name="qty" value="{{ $raw['qty'] ?? 1 }}"
                           class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full text-right">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Packing</label>
                    <select name="packing" class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full">
                        <option value="instant" @selected(($raw['packing'] ?? 'instant') !== 'kayu')>Instant</option>
                        <option value="kayu" @selected(($raw['packing'] ?? '') === 'kayu')>Packing kayu</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Packing tambahan / pcs</label>
                    <input type="text" name="packing_khusus" value="{{ $raw['packing_khusus'] ?? '' }}" placeholder="0"
                           class="rupiah-input border border-slate-200 rounded-xl px-3 py-2 text-sm w-full text-right">
                </div>
            </div>

            {{-- Setting & markup diubah langsung di baris rincian hasil (form="form-hitung").
                 Sebelum ada hasil, keduanya ikut angka Pengaturan. --}}
            @if(!$result)
                <p class="text-[11px] text-slate-400">
                    Biaya setting {{ $rp($default['setup']) }} &middot; markup {{ $angka($default['markup'], 0) }}% &mdash; bisa diubah di rincian hasil.
                </p>
            @endif

            <button class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-bold">Hitung harga</button>
        </form>

        {{-- ── Hasil ── --}}
        <div class="lg:col-span-3 space-y-4">
            @if(!$r)
                <div class="bg-white border border-dashed border-slate-200 rounded-2xl p-10 text-center text-sm text-slate-400">
                    Isi ukuran dan bahan di kiri, lalu tekan <strong>Hitung harga</strong>.
                </div>
            @elseif(!$r['ok'])
                <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm">
                    @foreach($r['errors'] as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @else
                @if($r['warnings'])
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 text-xs space-y-1">
                        @foreach($r['warnings'] as $w)<div>⚠ {{ $w }}</div>@endforeach
                    </div>
                @endif

                {{-- Harga utama --}}
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Harga Non-MP per pcs @ {{ $r['qty'] }} pcs</div>
                            <div class="text-3xl font-black text-slate-800">{{ $rp($r['current']['price']) }}</div>
                            <div class="text-sm text-slate-500">Total {{ $rp($r['current']['total']) }} &middot; HPP {{ $rp($r['current']['hpp']) }}/pcs</div>
                            @if($r['current']['channels'])
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($r['current']['channels'] as $ch)
                                        <div class="bg-orange-50 border border-orange-100 rounded-xl px-3 py-1.5">
                                            <div class="text-[10px] font-bold text-orange-700">{{ $ch['label'] }} <span class="font-normal">({{ $angka($ch['percent'], 2) }}% + {{ $rp($ch['fixed']) }})</span></div>
                                            <div class="text-sm font-black text-slate-800">{{ $rp($ch['price']) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="text-right">
                            @if($r['min_order'] !== null)
                                <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Minimal order</div>
                                <div class="text-xl font-black {{ $r['current']['below_min'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $r['min_order'] }} pcs</div>
                                <div class="text-[11px] text-slate-400">½ hari seluruh produksi</div>
                            @else
                                <div class="text-[11px] text-slate-400">Tanpa minimal order</div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4" x-data="{ copied: false }">
                        <div class="text-[11px] font-bold text-slate-500 mb-1">Teks siap kirim</div>
                        <div class="flex gap-2">
                            <textarea x-ref="teks" readonly rows="2"
                                      class="flex-1 border border-slate-200 rounded-xl px-3 py-2 text-sm bg-slate-50">{{ $r['text'] }}</textarea>
                            <button type="button"
                                    @click="navigator.clipboard.writeText($refs.teks.value); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-3 rounded-xl text-xs font-semibold"
                                    x-text="copied ? 'Tersalin' : 'Salin'">Salin</button>
                        </div>
                    </div>
                </div>

                {{-- Rincian HPP --}}
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 text-sm font-bold text-slate-700">Rincian HPP per pcs @ {{ $r['qty'] }} pcs</div>
                    <table class="w-full text-sm" x-data="{ bahanBuka: false }">
                        <tbody class="divide-y divide-slate-50">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="bahanBuka = !bahanBuka">
                                <td class="px-5 py-2">
                                    <span class="inline-block w-3 text-slate-400 transition-transform" :class="bahanBuka && 'rotate-90'">&#9656;</span>
                                    Bahan <span class="text-[11px] text-blue-600 font-semibold" x-text="bahanBuka ? 'tutup rincian' : 'lihat rincian'">lihat rincian</span>
                                    <div class="text-[11px] text-slate-400 ml-3">{{ $angka($r['area_total'], 0) }} cm² &middot; {{ $rp($r['material_raw']) }} + sisa {{ $angka($r['waste_percent'], 0) }}%</div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['material_cost']) }}</td>
                            </tr>
                            <tr x-show="bahanBuka" x-cloak>
                                <td colspan="2" class="px-5 pb-3 pt-0 bg-slate-50/60">
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs" style="min-width:560px">
                                            <thead class="text-[10px] uppercase tracking-widest text-slate-400">
                                                <tr>
                                                    <th class="py-1.5 text-left">Bagian</th>
                                                    <th class="py-1.5 text-right">Ukuran (cm)</th>
                                                    <th class="py-1.5 text-right">Qty</th>
                                                    <th class="py-1.5 text-right">Luas cm²</th>
                                                    <th class="py-1.5 pl-3 text-left">Bahan</th>
                                                    <th class="py-1.5 text-right">Rp/cm²</th>
                                                    <th class="py-1.5 text-right">Biaya</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach($r['panels'] as $p)
                                                    <tr>
                                                        <td class="py-1.5">{{ $p['bagian'] }}</td>
                                                        <td class="py-1.5 text-right">{{ $angka($p['p']) }} × {{ $angka($p['l']) }}</td>
                                                        <td class="py-1.5 text-right">{{ $angka($p['qty'], 0) }}</td>
                                                        <td class="py-1.5 text-right">{{ $angka($p['area'], 0) }}</td>
                                                        <td class="py-1.5 pl-3 text-slate-500">{{ $p['material_name'] }}</td>
                                                        <td class="py-1.5 text-right">{{ $p['price_per_cm2'] !== null ? number_format($p['price_per_cm2'], 2, ',', '.') : '—' }}</td>
                                                        <td class="py-1.5 text-right">{{ $rp($p['cost']) }}</td>
                                                    </tr>
                                                @endforeach
                                                <tr class="font-semibold">
                                                    <td class="py-1.5" colspan="3">Jumlah</td>
                                                    <td class="py-1.5 text-right">{{ $angka($r['area_total'], 0) }}</td>
                                                    <td colspan="2"></td>
                                                    <td class="py-1.5 text-right">{{ $rp($r['material_raw']) }}</td>
                                                </tr>
                                                <tr class="text-slate-500">
                                                    <td class="py-1.5" colspan="6">+ sisa bahan {{ $angka($r['waste_percent'], 0) }}%</td>
                                                    <td class="py-1.5 text-right">{{ $rp($r['material_cost'] - $r['material_raw']) }}</td>
                                                </tr>
                                                <tr class="font-bold">
                                                    <td class="py-1.5" colspan="6">Biaya bahan</td>
                                                    <td class="py-1.5 text-right">{{ $rp($r['material_cost']) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-[10px] text-slate-400 mt-1">Rp/cm² = harga beli lembar terakhir ÷ luas lembar.</div>
                                </td>
                            </tr>
                            @if($r['accessories'])
                                <tr>
                                    <td class="px-5 py-2">Aksesoris</td>
                                    <td class="px-5 py-2 text-right">{{ $rp($r['accessory_cost']) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="px-5 py-2">Waktu CNC
                                    <div class="text-[11px] text-slate-400">
                                        {{ $menit($r['cnc_sec']) }} &middot; potong {{ $angka($r['cut_mm'] / 10, 0) }} cm = {{ $menit($r['cnc_potong_sec']) }}
                                        &middot; persiapan & ambil komponen {{ $menit($r['cnc_siap_sec']) }}
                                        @if($r['factors']['cnc']['effective'] != 1)&middot; faktor {{ $angka($r['factors']['cnc']['effective'], 2) }} ({{ $r['factors']['cnc']['source'] }})@endif
                                    </div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['cnc_cost']) }}</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-2">Waktu rakit
                                    <div class="text-[11px] text-slate-400">
                                        {{ $menit($r['asm_sec']) }} &middot; {{ $angka($r['komponen'], 0) }} komponen &middot; lem {{ $angka($r['lem_cm'], 0) }} cm
                                        @if($r['accessory_count'] > 0)&middot; {{ $angka($r['accessory_count'], 0) }} aksesoris @endif
                                        @if($r['factors']['assembly']['effective'] != 1)&middot; faktor {{ $angka($r['factors']['assembly']['effective'], 2) }} ({{ $r['factors']['assembly']['source'] }})@endif
                                    </div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['asm_cost']) }}</td>
                            </tr>
                            <tr>
                                <td class="px-5 py-2">Packing {{ $r['packing_jenis'] === 'kayu' ? 'kayu' : 'instant' }}
                                    <div class="text-[11px] text-slate-400">
                                        overhead per surat jalan {{ $rp($r['packing']) }}
                                        @if($r['packing_jenis'] === 'kayu')
                                            &middot; peti {{ $angka($r['kayu']['pendek']) }}×{{ $angka($r['kayu']['panjang']) }}×{{ $angka($r['kayu']['tebal']) }} cm:
                                            atas-bawah {{ $rp($r['kayu']['biaya_ab']) }} + samping {{ $rp($r['kayu']['biaya_samping']) }} + proses {{ $rp($r['kayu']['proses']) }}
                                        @endif
                                        @if($r['packing_khusus'] > 0)&middot; tambahan {{ $rp($r['packing_khusus']) }}@endif
                                    </div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['packing'] + $r['packing_kayu'] + $r['packing_khusus']) }}</td>
                            </tr>
                            @php
                                $ubah = abs($r['setup_cost'] - $default['setup']) > 0.001 || abs($r['markup_percent'] - $default['markup']) > 0.001;
                                $kirim = 'this.form.requestSubmit()';
                            @endphp
                            <tr class="bg-amber-50/40">
                                <td class="px-5 py-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        Setting
                                        <input type="text" name="setup" form="form-hitung"
                                               value="{{ number_format($r['setup_cost'], 0, ',', '.') }}"
                                               onchange="{{ $kirim }}"
                                               class="rupiah-input border border-amber-300 bg-white rounded-lg px-2 py-1 text-sm w-28 text-right font-semibold">
                                        <span class="text-[11px] text-slate-500">per pesanan &divide; {{ $r['qty'] }} pcs</span>
                                    </div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['current']['setup_per_unit']) }}</td>
                            </tr>
                            <tr class="bg-slate-50 font-bold">
                                <td class="px-5 py-2">HPP</td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['current']['hpp']) }}</td>
                            </tr>
                            <tr class="bg-amber-50/40">
                                <td class="px-5 py-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        Markup
                                        <input type="text" inputmode="decimal" name="markup" form="form-hitung"
                                               value="{{ $angka($r['markup_percent'], 2) }}"
                                               onchange="{{ $kirim }}"
                                               class="border border-amber-300 bg-white rounded-lg px-2 py-1 text-sm w-20 text-right font-semibold">
                                        <span class="text-sm">%</span>
                                        <span class="text-[11px] text-slate-400">(dibulatkan ke atas berakhiran 900)</span>
                                    </div>
                                </td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['current']['price'] - $r['current']['hpp']) }}</td>
                            </tr>
                            <tr>
                                <td colspan="2" class="px-5 py-2 text-[11px] text-slate-500">
                                    Ubah angka di kolom kuning lalu tekan Enter / klik di luar kolom → harga dihitung ulang.
                                    @if($ubah)
                                        <span class="text-amber-700 font-semibold">Beda dari bawaan ({{ $rp($default['setup']) }} &middot; {{ $angka($default['markup'], 2) }}%).</span>
                                        <button type="submit" form="form-simpan-bawaan"
                                                class="ml-1 border border-amber-400 text-amber-800 hover:bg-amber-50 px-2 py-0.5 rounded-lg font-semibold">
                                            Simpan jadi bawaan {{ $category->name }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            <tr class="bg-blue-50 font-black text-blue-800">
                                <td class="px-5 py-2">Harga Non-MP</td>
                                <td class="px-5 py-2 text-right">{{ $rp($r['current']['price']) }}</td>
                            </tr>
                            @foreach($r['current']['channels'] as $ch)
                                <tr>
                                    <td class="px-5 py-2">Harga {{ $ch['label'] }}
                                        <div class="text-[11px] text-slate-400">
                                            potongan {{ $angka($ch['percent'], 2) }}% + {{ $rp($ch['fixed']) }} &middot; untung bersih {{ $rp($ch['profit']) }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-2 text-right font-semibold">{{ $rp($ch['price']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-5 py-2 text-[11px] text-slate-400 border-t border-slate-100">
                        Tarif {{ $rp($r['rate']) }}/slot-jam dari Kuota Produksi.
                    </div>
                </div>

                {{-- Harga grosir terkunci per kelompok jumlah + untung pesanan ini --}}
                @php $g = $r['grosir']; @endphp
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100">
                        <div class="text-sm font-bold text-slate-700">Harga Grosir</div>
                        <div class="text-[11px] text-slate-500">
                            @if($g['bawah_normal'])
                                Harga normal dihitung pada {{ $g['minimal_order'] }} pcs, pesanan di bawahnya tetap harga normal &middot; grosir mulai {{ $g['mulai'] }} pcs (dihitung pada {{ $g['mulai'] }} pcs).
                            @else
                                Minimal order {{ $g['minimal_order'] }} pcs &middot; grosir mulai {{ $g['mulai'] }} pcs.
                                Harga tiap kelompok dihitung pada jumlah terkecil kelompoknya.
                            @endif
                            Harga terkunci, tidak berubah mengikuti jumlah pesanan.
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" style="min-width:560px">
                            <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400">
                                <tr>
                                    <th class="px-5 py-2 text-left">Jumlah</th>
                                    <th class="px-3 py-2 text-right">Non-MP</th>
                                    @foreach($r['channels'] as $ch)
                                        <th class="px-3 py-2 text-right">{{ $ch['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($g['groups'] as $grp)
                                    <tr class="{{ $grp['applies'] ? 'bg-blue-50/60 font-semibold' : '' }}">
                                        <td class="px-5 py-2">
                                            {{ $grp['label'] }}
                                            @if($grp['key'] === 'satuan')<span class="text-[10px] text-amber-600 ml-1">di bawah minimal order</span>@endif
                                            @if($grp['key'] === 'grosir')<span class="text-[10px] text-emerald-600 ml-1">grosir</span>@endif
                                            @if($grp['applies'])<span class="text-[10px] text-blue-600 ml-1">&larr; pesanan ini</span>@endif
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ $rp($grp['price']) }}</td>
                                        @foreach($grp['channels'] as $c)
                                            <td class="px-3 py-2 text-right">{{ $rp($c['price']) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="px-5 py-3 border-t border-slate-100 text-sm font-bold text-slate-700">
                        Untung pesanan ini: {{ $r['qty'] }} pcs <span class="font-normal text-slate-500">(harga kelompok {{ $g['berlaku'] }}, HPP {{ $rp($g['hpp']) }}/pcs)</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" style="min-width:560px">
                            <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400">
                                <tr>
                                    <th class="px-5 py-2 text-left">Kanal</th>
                                    <th class="px-3 py-2 text-right">Harga/pcs</th>
                                    <th class="px-3 py-2 text-right">Total bayar</th>
                                    <th class="px-3 py-2 text-right">Diterima</th>
                                    <th class="px-3 py-2 text-right">HPP total</th>
                                    <th class="px-5 py-2 text-right">Untung</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                @foreach($g['pesanan'] as $p)
                                    <tr>
                                        <td class="px-5 py-2">{{ $p['label'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $rp($p['price']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $rp($p['total']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $rp($p['diterima']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $rp($p['hpp_total']) }}</td>
                                        <td class="px-5 py-2 text-right font-semibold {{ $p['untung'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">
                                            {{ $rp($p['untung']) }}
                                            <span class="text-[11px] font-normal text-slate-400">({{ $angka($p['untung_pct'], 0) }}%)</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-2 text-[11px] text-slate-400 border-t border-slate-100">
                        Diterima = harga setelah potongan kanal. Untung = diterima &minus; HPP (setting dibagi {{ $r['qty'] }} pcs). % = dari total bayar.
                        Batas minimal order & grosir diubah di Pengaturan.
                    </div>
                </div>

                {{-- Cek silang dengan produk ready berukuran sama --}}
                @if($r['ready'])
                    @php $rd = $r['ready']; @endphp
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 text-sm">
                        <div class="font-bold text-slate-700 mb-1">Cek silang: ukuran ini ada versi ready</div>
                        <div class="text-slate-600">
                            {{ $rd['name'] }} <span class="text-[11px] text-slate-400">({{ $rd['sku'] }})</span>
                            &middot; harga {{ $rp($rd['base_price']) }}
                        </div>
                        <div class="mt-1">
                            HPP ready {{ $rp($rd['hpp']) }} vs kalkulator {{ $rp($rd['calc_hpp']) }}
                            @if($rd['diff_pct'] !== null)
                                <span class="ml-1 font-bold {{ abs($rd['diff_pct']) <= 15 ? 'text-emerald-600' : 'text-amber-600' }}">
                                    ({{ $rd['diff_pct'] >= 0 ? '+' : '' }}{{ $angka($rd['diff_pct'], 0) }}%)
                                </span>
                            @endif
                        </div>
                        <div class="text-[11px] text-slate-400 mt-1">
                            Kalkulator dihitung dengan bahan yang dipilih, packing Instant, tanpa biaya setting. Wajar sedikit di atas HPP ready
                            (sisa bahan 15% dan rumus waktu dibuat aman). Selisih jauh = cek bahan atau parameter.
                        </div>
                    </div>
                @endif

                {{-- Kapasitas & berat --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 text-sm">
                        <div class="font-bold text-slate-700 mb-2">Kapasitas</div>
                        @if($r['capacity']['units_per_day'])
                            <div>{{ $angka($r['capacity']['units_per_day']) }} pcs/hari kalau seluruh produksi mengerjakan ini</div>
                            <div class="text-[11px] text-slate-400 mt-1">
                                Penentu: {{ $r['capacity']['bottleneck'] }}.
                                @foreach($r['capacity']['per_division'] as $div => $n){{ $div }} {{ $angka($n) }}/hari @if(!$loop->last)&middot; @endif @endforeach
                            </div>
                        @else
                            <div class="text-slate-400">Belum bisa dihitung.</div>
                        @endif
                    </div>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 text-sm">
                        <div class="font-bold text-slate-700 mb-2">Berat</div>
                        <div>Per pcs: akrilik {{ $angka($r['weight_kg'], 2) }} kg &middot; volumetrik {{ $angka($r['volumetric_kg'], 2) }} kg</div>
                        <div class="font-semibold mt-1">
                            {{ $r['qty'] }} pcs: akrilik {{ $angka($r['weight_kg'] * $r['qty'], 2) }} kg &middot; volumetrik {{ $angka($r['volumetric_kg'] * $r['qty'], 2) }} kg
                        </div>
                        <div class="text-[11px] text-slate-400 mt-1">Volumetrik = (P+6)(L+5)(T+5) &divide; 5000, yang biasanya dipakai kurir.</div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if($r && $r['ok'])
        {{-- Simpan setting & markup yang sedang dicoba jadi angka bawaan kategori. --}}
        <form method="POST" action="{{ route('analisa.harga-custom.pengaturan.harga') }}" id="form-simpan-bawaan" class="hidden">
            @csrf
            <input type="hidden" name="kategori" value="{{ $category->code }}">
            <input type="hidden" name="setup_cost" value="{{ $r['setup_cost'] }}">
            <input type="hidden" name="markup_percent" value="{{ $r['markup_percent'] }}">
        </form>
    @endif
</div>
@endsection
