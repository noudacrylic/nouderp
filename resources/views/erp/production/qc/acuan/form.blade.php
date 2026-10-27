@extends('layouts.erp')

@section('content')
@php
    $awal = [
        'product'    => $profil->product ? ['id' => $profil->product->id, 'label' => $profil->product->sku . ' — ' . $profil->product->name] : null,
        'packaging'  => $profil->packaging_gram ?? 0,
        'samples'    => $profil->samples ?? [],
        'components' => $profil->exists
            ? $profil->components->map(fn ($k) => ['name' => $k->name, 'qty' => $k->qty, 'unit_gram' => $k->unit_gram, 'samples' => $k->samples ?? []])->values()
            : [['name' => '', 'qty' => 1, 'unit_gram' => null, 'samples' => []]],
        'tol_mode'   => $profil->tolerance_mode ?? '',
        'tol_value'  => $profil->tolerance_value,
        'bawaan'     => $bawaan,
        'salinan'    => $salinan,
        'urlProduk'  => route('production.qc-acuan.produk'),
    ];
@endphp
<div class="w-full px-6 py-4" x-data="formAcuan(@js($awal))" @timbangan-tangkap.window="tangkap($event.detail)">
    <div class="mb-3">
        <div class="text-xs text-gray-400"><a href="{{ route('production.qc-acuan.index') }}" class="hover:text-blue-600">Sampel Acuan</a> › {{ $profil->exists ? $profil->product?->sku : 'Profil baru' }}</div>
        <h1 class="text-xl font-bold text-gray-800">{{ $profil->exists ? 'Profil QC ' . $profil->product?->sku : 'Profil QC Baru' }}</h1>
    </div>
    @include('erp.production.qc._tabs')

    <form method="POST" action="{{ $profil->exists ? route('production.qc-acuan.update', $profil->id) : route('production.qc-acuan.store') }}"
          class="grid grid-cols-12 gap-5">
        @csrf
        @if($profil->exists) @method('PUT') @endif

        <div class="col-span-8 space-y-4">
            {{-- Produk --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
                <label class="block text-xs font-semibold text-gray-600">Produk (SKU knockdown)</label>
                @if($profil->exists)
                    <div class="font-bold">{{ $profil->product?->sku }} <span class="text-gray-500 font-normal">{{ $profil->product?->name }}</span></div>
                @else
                    <input type="hidden" name="product_id" :value="product ? product.id : ''">
                    <template x-if="product">
                        <div class="flex items-center justify-between border border-blue-200 bg-blue-50 rounded-xl px-3 py-2 text-sm">
                            <span class="font-semibold" x-text="product.label"></span>
                            <button type="button" class="text-xs text-blue-700 hover:underline" @click="product = null">ganti</button>
                        </div>
                    </template>
                    <div x-show="!product" class="relative">
                        <input type="text" x-model="cari" @input.debounce.300ms="cariProduk()" @focus="cariProduk()"
                               placeholder="Cari SKU atau nama produk…" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                        <div x-show="hasilProduk.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-100 rounded-xl shadow-lg max-h-64 overflow-y-auto">
                            <template x-for="p in hasilProduk" :key="p.id">
                                <button type="button" @click="product = { id: p.id, label: p.sku + ' — ' + p.name }; hasilProduk = []"
                                        class="block w-full text-left px-3 py-2 hover:bg-gray-50 text-sm"><b x-text="p.sku"></b> <span class="text-gray-500" x-text="p.name"></span></button>
                            </template>
                        </div>
                    </div>
                @endif

                <div x-show="salinan.length" class="flex items-center gap-2 text-xs">
                    <span class="text-gray-500">Salin komponen dari:</span>
                    <select @change="salin($event.target.value); $event.target.value = ''" class="border border-gray-200 rounded-lg px-2 py-1">
                        <option value="">— SKU lain —</option>
                        <template x-for="s in salinan" :key="s.id"><option :value="s.id" x-text="s.label"></option></template>
                    </select>
                    <span class="text-gray-400">(nama & jumlah saja — beratnya ditimbang ulang)</span>
                </div>
            </div>

            {{-- Sampel paket utuh --}}
            <div class="bg-white rounded-2xl border shadow-sm p-4 space-y-2" :class="sasaran === 'paket' ? 'border-blue-400 ring-2 ring-blue-100' : 'border-gray-100'">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-bold text-gray-700 text-sm">Sampel paket utuh</div>
                        <div class="text-[11px] text-gray-500">Timbang beberapa bungkus yang SUDAH DICEK lengkap. Rata-ratanya jadi target.</div>
                    </div>
                    <button type="button" @click="sasaran = 'paket'" class="text-xs px-3 py-1.5 rounded-lg border font-semibold"
                            :class="sasaran === 'paket' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-gray-600'">⚖️ Timbang ke sini</button>
                </div>
                <div class="flex flex-wrap gap-1.5 items-center">
                    <template x-for="(s, i) in samples" :key="i">
                        <span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs"
                              :class="sampelLuar(s) ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-gray-100'"
                              :title="sampelLuar(s) ? 'Di luar target ± toleransi — sampel ini sendiri akan CEK ULANG' : ''">
                            <span x-text="g(s)"></span>
                            <button type="button" class="text-gray-400 hover:text-red-600" @click="samples.splice(i, 1)">×</button>
                            <input type="hidden" name="samples[]" :value="s">
                        </span>
                    </template>
                    <input type="number" step="0.1" min="0" x-model="ketikPaket" @keydown.enter.prevent="tambahPaket()" placeholder="+ ketik"
                           class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-xs">
                </div>
                <div class="text-sm">Target: <b x-text="targetPaket() ? g(targetPaket()) : '—'"></b>
                    <span x-show="samples.length > 1" class="text-xs text-gray-500" x-text="'· sebaran ' + g(Math.max(...samples) - Math.min(...samples))"></span></div>
                {{-- Saran toleransi: sampel acuan yang "sudah benar" semestinya semua lulus. --}}
                <template x-if="samples.length > 1">
                    <div class="text-xs rounded-lg px-3 py-2 flex flex-wrap items-center gap-2"
                         :class="jumlahLuar() ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700'">
                        <span x-show="!jumlahLuar()" x-text="'✓ Semua sampel masuk toleransi ± ' + g(toleransi())"></span>
                        <span x-show="jumlahLuar()">
                            ⚠️ <b x-text="jumlahLuar()"></b> sampel di luar toleransi ± <span x-text="g(toleransi())"></span>.
                            Toleransi minimal agar semua sampel lulus: <b x-text="'± ' + g(saranToleransi())"></b>
                            (<span x-text="persen(saranToleransi())"></span>).
                            Atau cek dulu sampel merah — mungkin kurang komponen.
                        </span>
                        <button type="button" x-show="jumlahLuar()" @click="tolMode = 'gram'; tolValue = saranToleransi()"
                                class="ml-auto border border-amber-400 text-amber-800 px-2 py-0.5 rounded font-semibold">Pakai ± <span x-text="g(saranToleransi())"></span></button>
                    </div>
                </template>
            </div>

            {{-- Komponen --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-bold text-gray-700 text-sm">Komponen per bungkus</div>
                        <div class="text-[11px] text-gray-500">Isi berat <b>SATU keping</b> — dikalikan jumlahnya otomatis. Timbang boleh beberapa kali, diambil rata-ratanya. Dipakai untuk menebak komponen yang kurang.</div>
                    </div>
                    <button type="button" @click="components.push({ name: '', qty: 1, unit_gram: null, samples: [] })"
                            class="text-xs border border-gray-200 px-3 py-1.5 rounded-lg">+ Komponen</button>
                </div>
                <div class="grid grid-cols-12 gap-2 px-2 text-[10px] font-bold text-gray-500 uppercase tracking-wide">
                    <div class="col-span-3">Nama komponen</div>
                    <div class="col-span-2">Jumlah / bungkus</div>
                    <div class="col-span-2">Berat per keping</div>
                    <div class="col-span-4">Sampel timbang · subtotal</div>
                </div>
                <template x-for="(k, i) in components" :key="i">
                    <div class="grid grid-cols-12 gap-2 items-center rounded-xl p-2" :class="sasaran === i ? 'bg-blue-50 ring-2 ring-blue-200' : ''">
                        <input type="text" x-model="k.name" :name="'components[' + i + '][name]'" placeholder="mis. Samping"
                               class="col-span-3 border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                        <div class="col-span-2 flex items-center gap-1">
                            <input type="number" min="1" x-model.number="k.qty" :name="'components[' + i + '][qty]'"
                                   class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right"><span class="text-xs text-gray-400">pcs</span>
                        </div>
                        <div class="col-span-2 flex items-center gap-1">
                            <input type="number" step="0.1" min="0" x-model.number="k.unit_gram" :name="'components[' + i + '][unit_gram]'"
                                   :readonly="k.samples.length > 0" placeholder="g/pcs"
                                   class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right read-only:bg-gray-50"><span class="text-xs text-gray-400 whitespace-nowrap">g/pcs</span>
                        </div>
                        <div class="col-span-4 flex flex-wrap gap-1">
                            <template x-for="(s, j) in k.samples" :key="j">
                                <span class="inline-flex items-center gap-1 bg-gray-100 rounded px-1.5 py-0.5 text-[11px]">
                                    <span x-text="g(s)"></span>
                                    <button type="button" class="text-gray-400 hover:text-red-600" @click="k.samples.splice(j, 1); hitungUnit(k)">×</button>
                                    <input type="hidden" :name="'components[' + i + '][samples][]'" :value="s">
                                </span>
                            </template>
                            <button type="button" @click="sasaran = i" class="text-[11px] px-2 py-0.5 rounded border"
                                    :class="sasaran === i ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-gray-600'">⚖️ timbang 1 keping</button>
                            <span x-show="k.unit_gram && k.qty" class="text-[11px] text-gray-500 self-center"
                                  x-text="'= ' + k.qty + ' × ' + g(k.unit_gram) + ' = ' + g(k.qty * k.unit_gram)"></span>
                        </div>
                        <button type="button" @click="components.splice(i, 1); if (sasaran === i) sasaran = 'paket'"
                                class="col-span-1 text-gray-400 hover:text-red-600 text-sm">✕</button>
                    </div>
                </template>

                <div class="flex items-center gap-3 pt-2 border-t border-gray-100 text-sm">
                    <label class="text-xs text-gray-600">Plastik/bungkus</label>
                    <input type="number" step="0.1" min="0" name="packaging_gram" x-model.number="packaging"
                           class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right"><span class="text-xs text-gray-400">g</span>
                    <span class="ml-auto text-xs text-gray-600">Σ komponen + bungkus: <b x-text="g(sumKomponen())"></b></span>
                </div>
                {{-- Cek silang: Σ komponen + bungkus harus dekat dengan sampel paket utuh. --}}
                <template x-if="targetPaket() && sumKomponen()">
                    <div class="text-xs rounded-lg px-3 py-2"
                         :class="Math.abs(targetPaket() - sumKomponen()) <= toleransi() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'"
                         x-text="Math.abs(targetPaket() - sumKomponen()) <= toleransi()
                            ? '✓ Cocok dengan sampel paket (selisih ' + g(targetPaket() - sumKomponen()) + ')'
                            : '⚠️ Beda ' + g(targetPaket() - sumKomponen()) + ' dari sampel paket — cek jumlah komponen atau berat bungkus.'"></div>
                </template>
                <template x-for="k in takTerdeteksi()" :key="k.name">
                    <div class="text-xs text-amber-700">⚠️ <b x-text="k.name"></b> (<span x-text="g(k.unit_gram)"></span>) lebih ringan dari 2× toleransi — kehilangannya bisa tidak terdeteksi timbangan, cek visual.</div>
                </template>
            </div>

            {{-- Toleransi & catatan --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Toleransi</label>
                    <div class="flex items-center gap-2">
                        <select name="tolerance_mode" x-model="tolMode" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">Ikut bawaan ({{ $bawaan['value'] }} {{ $bawaan['mode'] === 'gram' ? 'g' : '%' }})</option>
                            <option value="gram">± gram</option>
                            <option value="persen">± persen</option>
                        </select>
                        <input type="number" step="0.1" min="0.1" name="tolerance_value" x-model.number="tolValue" x-show="tolMode"
                               class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right">
                    </div>
                    <div class="text-xs text-gray-500 mt-1">= ± <b x-text="g(toleransi())"></b></div>
                    <button type="button" x-show="jumlahLuar()" x-cloak @click="tolMode = 'gram'; tolValue = saranToleransi()"
                            class="mt-2 text-xs border border-amber-400 bg-amber-50 text-amber-800 px-3 py-1.5 rounded-lg font-semibold">
                        Ikuti saran toleransi (± <span x-text="g(saranToleransi())"></span>)</button>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Catatan batch lembaran</label>
                    <input type="text" name="batch_note" value="{{ $profil->batch_note }}" maxlength="190" placeholder="mis. Akrilik 3 mm batch Okt"
                           class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm">
                    <label class="flex items-center gap-2 mt-2 text-xs text-gray-600">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($profil->is_active ?? true)> Aktif (wajib QC)
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('production.qc-acuan.index') }}" class="border border-gray-200 text-gray-600 px-4 py-2 rounded-xl text-sm font-semibold">Batal</a>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl text-sm font-semibold">Simpan</button>
            </div>
            @if($profil->exists)
                <p class="text-[11px] text-gray-400 text-right">Mengubah berat sampel = ambil ulang acuan; acuan lama otomatis diarsipkan.</p>
            @endif
        </div>

        <div class="col-span-4 space-y-4">
            <div class="text-xs text-gray-600">Tangkapan berikutnya masuk ke:
                <b x-text="sasaran === 'paket' ? 'sampel paket utuh' : ('komponen ' + (components[sasaran]?.name || '#' + (sasaran + 1)))"></b></div>
            @include('erp.production.qc._timbangan')

            @if($profil->exists && $profil->history->isNotEmpty())
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-xs space-y-2">
                    <div class="font-bold text-gray-700 text-sm">Riwayat acuan</div>
                    @foreach($profil->history as $h)
                        <div class="border-b border-gray-50 pb-1">
                            <div><b>{{ isset($h->snapshot['target_gram']) ? number_format($h->snapshot['target_gram'], 1, ',', '.') . ' g' : '—' }}</b>
                                · sampel {{ $h->snapshot['sampled_at'] ?? '—' }}</div>
                            <div class="text-gray-500">diganti {{ $h->created_at->format('d/m/Y H:i') }}{{ $h->user ? ' · ' . $h->user->name : '' }}{{ $h->reason ? ' · ' . $h->reason : '' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </form>
</div>

<script>
    function formAcuan(a) {
        return {
            product: a.product, cari: '', hasilProduk: [],
            packaging: Number(a.packaging) || 0, samples: a.samples.map(Number), components: a.components,
            tolMode: a.tol_mode || '', tolValue: a.tol_value, bawaan: a.bawaan, salinan: a.salinan,
            sasaran: 'paket', ketikPaket: '',

            g(v) { return (Number(v) || 0).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' g'; },
            rata(xs) { return xs.length ? Math.round(xs.reduce((s, x) => s + Number(x), 0) / xs.length * 10) / 10 : null; },
            targetPaket() { return this.rata(this.samples); },
            // Baris tanpa nama = belum diisi, tidak ikut dihitung (server pun membuangnya).
            terisi() { return this.components.filter((k) => (k.name || '').trim() !== ''); },
            sumKomponen() {
                const ks = this.terisi();
                if (!ks.length || ks.some((k) => !k.unit_gram)) return 0;
                return Math.round((ks.reduce((s, k) => s + (Number(k.qty) || 0) * Number(k.unit_gram), 0) + Number(this.packaging || 0)) * 10) / 10;
            },
            toleransi() {
                const t = this.tolMode ? { mode: this.tolMode, value: Number(this.tolValue) || 0 } : this.bawaan;
                const target = this.targetPaket() || this.sumKomponen() || 0;
                return Math.max(0.1, Math.round((t.mode === 'gram' ? t.value : target * t.value / 100) * 10) / 10);
            },
            // Selisih dihitung seperti QcService::analisa: target dibulatkan 0,1 g, selisih juga.
            selisih(s) { return Math.abs(Math.round((Number(s) - this.targetPaket()) * 10) / 10); },
            sampelLuar(s) { return this.samples.length > 1 && this.selisih(s) > this.toleransi() + 1e-9; },
            jumlahLuar() { return this.samples.filter((s) => this.sampelLuar(s)).length; },
            saranToleransi() { return Math.max(...this.samples.map((s) => this.selisih(s))); },
            persen(gram) { const t = this.targetPaket(); return t ? (gram / t * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%' : '—'; },
            takTerdeteksi() { const tol = this.toleransi(); return this.components.filter((k) => k.name && k.unit_gram && k.unit_gram < 2 * tol); },
            hitungUnit(k) { k.unit_gram = this.rata(k.samples); },

            tangkap(d) {
                if (this.sasaran === 'paket') { this.samples.push(d.gram); return; }
                const k = this.components[this.sasaran];
                if (k) { k.samples.push(d.gram); this.hitungUnit(k); }
            },
            tambahPaket() { const v = Number(this.ketikPaket); if (v > 0) this.samples.push(Math.round(v * 10) / 10); this.ketikPaket = ''; },

            salin(id) {
                const s = this.salinan.find((x) => String(x.id) === String(id));
                if (!s) return;
                this.components = s.components.map((k) => ({ name: k.name, qty: k.qty, unit_gram: null, samples: [] }));
                this.packaging = Number(s.packaging) || 0;
            },

            async cariProduk() {
                const r = await fetch(a.urlProduk + '?q=' + encodeURIComponent(this.cari.trim()), { headers: { 'Accept': 'application/json' } });
                this.hasilProduk = await r.json().catch(() => []);
            },
        };
    }
</script>
@endsection
