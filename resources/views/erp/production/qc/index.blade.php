@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">
    <div class="mb-3">
        <h1 class="text-xl font-bold text-gray-800">QC Timbang</h1>
        <p class="text-xs text-gray-500">Pilih order produksi & produk, taruh bungkus di timbangan — berat stabil tercatat otomatis lalu dibandingkan dengan acuan.</p>
    </div>
    @include('erp.production.qc._tabs')

    <div class="grid grid-cols-12 gap-5">
        {{-- ───────────── kiri: pilih OP & produk ───────────── --}}
        <div class="col-span-3 space-y-4">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
                <label class="block text-xs font-semibold text-gray-600">Order Produksi</label>
                <select onchange="location.href='{{ route('production.qc.index') }}' + (this.value ? '?op=' + this.value : '')"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                    <option value="">— pilih OP —</option>
                    @foreach($orders as $o)
                        <option value="{{ $o->id }}" @selected($order && $order->id === $o->id)>
                            {{ $o->order_number }} · {{ $o->outputs->map(fn ($x) => $x->product?->sku)->filter()->implode(', ') }}
                        </option>
                    @endforeach
                </select>
                @if($orders->isEmpty())
                    <p class="text-xs text-gray-400">Belum ada OP berjalan yang hasilnya punya profil QC. Buat profilnya di tab Sampel Acuan.</p>
                @endif

                @if($order && $produkPilihan->count() > 1)
                    <label class="block text-xs font-semibold text-gray-600">Produk</label>
                    <div class="space-y-1">
                        @foreach($produkPilihan as $out)
                            <a href="{{ route('production.qc.index', ['op' => $order->id, 'produk' => $out->product_id]) }}"
                               class="block px-3 py-2 rounded-lg text-sm border {{ $productId === $out->product_id ? 'border-blue-400 bg-blue-50 font-semibold' : 'border-gray-200 hover:bg-gray-50' }}">
                                {{ $out->product?->sku }} <span class="text-gray-400 text-xs">{{ $out->product?->name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($profil)
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-sm space-y-2">
                    <div class="font-bold text-gray-700">Acuan</div>
                    <div class="flex justify-between"><span class="text-gray-500">Target</span><b>{{ number_format($target, 1, ',', '.') }} g</b></div>
                    <div class="flex justify-between"><span class="text-gray-500">Toleransi</span><b>± {{ number_format($toleransi, 1, ',', '.') }} g</b></div>
                    @if($profil->components->isNotEmpty())
                        <div class="pt-2 border-t border-gray-100 space-y-0.5">
                            @foreach($profil->components as $k)
                                <div class="flex justify-between text-xs"><span>{{ $k->qty }}× {{ $k->name }}</span><span class="text-gray-500">{{ $k->unit_gram ? number_format($k->unit_gram, 1, ',', '.') . ' g' : '—' }}</span></div>
                            @endforeach
                        </div>
                    @endif
                    <div class="text-[11px] text-gray-400">Sampel {{ optional($profil->sampled_at)->format('d/m/Y') ?? '—' }}{{ $profil->batch_note ? ' · ' . $profil->batch_note : '' }}</div>
                </div>
            @endif
        </div>

        @if($profil && $target)
        <div class="col-span-9 grid grid-cols-9 gap-5"
             x-data="qcTimbang(@js([
                'op'        => $order->id,
                'produk'    => $productId,
                'url'       => route('production.qc.timbang'),
                'ringkasan' => $ringkasan,
                'timer'     => $langkah?->status,
                'penimbang' => $penimbang,
                'aktif'     => $langkah?->executors->pluck('id')->first(),
                'riwayat'   => $riwayat->map(fn ($c) => [
                    'id' => $c->id, 'result' => $c->result, 'aktual' => $c->actual_gram, 'selisih' => $c->diff_gram,
                    'mode' => $c->mode, 'qty' => $c->qty, 'pesan' => $c->analysis['pesan'] ?? $c->reason,
                    'repair' => $c->repair_status, 'waktu' => $c->created_at->format('d/m H:i'), 'oleh' => $c->user?->name,
                ])->values(),
             ]))" @timbangan-tangkap.window="tangkap($event.detail)" @timbangan-beban.window="beban($event.detail.ada)">

            {{-- ───────────── tengah: timbangan & hasil ───────────── --}}
            <div class="col-span-5 space-y-4">
                @if($langkah)
                    {{-- Langkah QC Timbang: timer jalan sejak penimbangan pertama, dijeda saat hasil sebagian masuk stok. --}}
                    <div class="flex items-center gap-2 flex-wrap bg-white rounded-2xl border border-gray-100 shadow-sm px-4 py-3">
                        <span class="text-xs font-semibold text-gray-600">Penimbang:</span>
                        <select x-model.number="penimbangId" @change="simpanPenimbang()"
                                class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm"
                                :class="!penimbangId && timer === 'pending' ? 'border-red-300 bg-red-50' : ''">
                            <option value="">— pilih —</option>
                            <template x-for="e in cfg.penimbang" :key="e.id">
                                <option :value="e.id" x-text="e.name" :selected="e.id === penimbangId"></option>
                            </template>
                        </select>
                        <span class="ml-auto text-xs font-semibold px-2 py-1 rounded-lg"
                              :class="{ 'bg-gray-100 text-gray-500': timer === 'pending', 'bg-indigo-50 text-indigo-600': timer === 'in_progress',
                                        'bg-orange-50 text-orange-600': timer === 'paused', 'bg-emerald-50 text-emerald-600': timer === 'completed' }"
                              x-text="{ pending: '⏱ Timer mulai saat bungkus pertama ditimbang', in_progress: '⏱ Timer QC berjalan',
                                        paused: '⏸ Timer QC dijeda — lanjut saat menimbang', completed: '✓ Langkah QC selesai' }[timer] || ''"></span>
                    </div>
                    <div x-show="jeda.length" x-cloak class="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs px-4 py-2">
                        <template x-for="j in jeda" :key="j"><div x-text="'⏸ ' + j"></div></template>
                    </div>
                @endif

                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold text-gray-600">Mode:</span>
                    <button type="button" @click="mode = 'normal'"
                            class="text-xs px-3 py-1.5 rounded-lg border font-semibold"
                            :class="mode === 'normal' ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-200 text-gray-600'">Bungkus baru</button>
                    <button type="button" @click="mode = 'perbaikan'" :disabled="!ringkasan.menunggu"
                            class="text-xs px-3 py-1.5 rounded-lg border font-semibold disabled:opacity-40"
                            :class="mode === 'perbaikan' ? 'bg-orange-500 text-white border-orange-500' : 'border-gray-200 text-gray-600'">
                        Timbang perbaikan (<span x-text="ringkasan.menunggu"></span>)</button>
                </div>

                @include('erp.production.qc._timbangan')

                {{-- Hasil besar: hijau LULUS / merah CEK ULANG. --}}
                <div x-show="hasil" x-cloak @click="hasil = null"
                     class="rounded-2xl p-6 text-white cursor-pointer select-none"
                     :class="hasil && hasil.result === 'lulus' ? 'bg-emerald-600' : 'bg-red-600'">
                    <div class="text-4xl font-black tracking-wide" x-text="hasil && (hasil.result === 'lulus' ? '✓ LULUS' : '✗ CEK ULANG')"></div>
                    <div class="mt-2 text-lg font-semibold" x-text="hasil && hasil.pesan"></div>
                    <div class="mt-1 text-sm opacity-90"
                         x-text="hasil && ('Aktual ' + g(hasil.aktual) + ' · target ' + g(hasil.target) + ' ± ' + g(hasil.toleransi) + ' · selisih ' + (hasil.selisih > 0 ? '+' : '') + g(hasil.selisih))"></div>
                    <template x-if="hasil && hasil.kandidat && hasil.kandidat.length > 1">
                        <ul class="mt-3 text-sm space-y-0.5 opacity-95">
                            <template x-for="k in hasil.kandidat" :key="k.label">
                                <li x-text="'• ' + k.label + ' (' + g(k.gram) + ')'"></li>
                            </template>
                        </ul>
                    </template>
                    <div class="mt-3 text-xs opacity-75" x-show="hasil && hasil.mode === 'perbaikan'">Timbang perbaikan</div>
                </div>
                {{-- Belum ada hasil untuk bungkus di timbangan: jangan sampai tertukar dengan hasil bungkus sebelumnya. --}}
                <div x-show="!hasil && (adaBeban || sibuk)" x-cloak
                     class="rounded-2xl p-6 bg-gray-100 border-2 border-dashed border-gray-300 text-gray-500">
                    <div class="text-3xl font-black tracking-wide" x-text="sibuk ? 'Mencatat…' : '⏳ Menimbang…'"></div>
                    <div class="mt-2 text-sm">Tunggu angka stabil — belum dinilai.</div>
                </div>
                <div x-show="!hasil && !adaBeban && !sibuk" x-cloak
                     class="rounded-2xl p-6 border-2 border-dashed border-gray-200 text-gray-400 text-center text-sm">
                    Taruh bungkus di timbangan
                </div>
                <div x-show="galat" x-cloak class="rounded-xl bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3" x-text="galat"></div>

                {{-- Cadangan tanpa timbangan. --}}
                <form @submit.prevent="kirim(Number(manual), 'manual', null); manual = ''" class="flex items-center gap-2">
                    <input type="number" step="0.1" min="0.1" x-model="manual" placeholder="Ketik berat (g)"
                           class="w-40 border border-gray-200 rounded-xl px-3 py-2 text-sm text-right">
                    <button class="text-xs border border-gray-200 text-gray-600 px-3 py-2 rounded-xl">Catat manual</button>
                    <span class="text-[11px] text-gray-400">bila timbangan belum tersambung</span>
                </form>
            </div>

            {{-- ───────────── kanan: ringkasan, finalisasi, riwayat ───────────── --}}
            <div class="col-span-4 space-y-4">
                @unless($wajib)
                    <div class="rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-600 px-3 py-2">
                        OP ini dibuat sebelum profil QC produknya ada — QC boleh dilakukan, tapi tidak mengunci finalisasi.
                    </div>
                @endunless

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div><div class="text-[10px] text-gray-500 uppercase">Target</div><div class="text-xl font-black" x-text="n(ringkasan.target)"></div></div>
                        <div><div class="text-[10px] text-gray-500 uppercase">Ditimbang</div><div class="text-xl font-black" x-text="ringkasan.ditimbang"></div></div>
                        <div><div class="text-[10px] text-emerald-600 uppercase">Lulus</div><div class="text-xl font-black text-emerald-600" x-text="ringkasan.lulus"></div></div>
                        <div><div class="text-[10px] text-orange-600 uppercase">Tunggu perbaikan</div><div class="text-xl font-black text-orange-600" x-text="ringkasan.menunggu"></div></div>
                        <div><div class="text-[10px] text-gray-500 uppercase">Difinalisasi</div><div class="text-xl font-black" x-text="n(ringkasan.difinalisasi)"></div></div>
                        <div><div class="text-[10px] text-blue-600 uppercase">Siap final</div><div class="text-xl font-black text-blue-600" x-text="n(ringkasan.boleh)"></div></div>
                    </div>

                    @if($langkah && $langkah->status !== 'completed')
                        {{-- QC = langkah terakhir OP: masuk stok sebagian, atau tutup langkah QC (modal hasil produksi). --}}
                        <a :href="'{{ route('production.process.partial-confirm', $order->id) }}?qc_produk={{ $productId }}&qc_qty=' + ringkasan.boleh"
                           x-show="ringkasan.boleh > 0 && timer !== 'pending'"
                           class="mt-4 block text-center border border-blue-300 text-blue-700 hover:bg-blue-50 px-4 py-2.5 rounded-xl text-sm font-semibold">
                            Masukkan stok sebagian (<span x-text="n(ringkasan.boleh)"></span> lulus)
                        </a>
                        @if($produksiSelesai)
                            <a href="{{ route('production.process.index', ['department_id' => $langkah->department_id, 'selesai' => $langkah->id]) }}"
                               x-show="timer !== 'pending' && !ringkasan.menunggu"
                               class="mt-2 block text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold">
                                ✓ Selesai QC &amp; masukkan stok
                            </a>
                            <p x-show="ringkasan.menunggu" class="mt-2 text-[11px] text-orange-600">Timbang ulang bungkus yang diperbaiki dulu sebelum QC ditutup.</p>
                        @else
                            <p class="mt-2 text-[11px] text-gray-500">Produksi ({{ $order->steps->where('is_qc', false)->where('status', '!=', 'completed')->pluck('name')->implode(', ') }}) masih berjalan — yang sudah lulus bisa dimasukkan stok sebagian.</p>
                        @endif
                        <p class="mt-2 text-[11px] text-gray-400">Masuk stok hanya sebanyak bungkus yang lulus. Masuk stok sebagian menjeda timer QC.</p>
                    @elseif(!$langkah)
                        @php
                            $bisaSebagian = in_array($order->status, ['in_progress', 'partial'], true);
                            $urlFinal = $bisaSebagian
                                ? route('production.process.partial-confirm', $order->id)
                                : route('production.orders.finalize-confirm', $order->id);
                        @endphp
                        <a :href="'{{ $urlFinal }}?qc_produk={{ $productId }}&qc_qty=' + ringkasan.boleh"
                           x-show="ringkasan.boleh > 0"
                           class="mt-4 block text-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-sm font-semibold">
                            {{ $bisaSebagian ? 'Selesaikan sebagian' : 'Finalisasi' }} (<span x-text="n(ringkasan.boleh)"></span> lulus)
                        </a>
                        <p class="mt-2 text-[11px] text-gray-400">Finalisasi hanya boleh sebanyak bungkus yang lulus. Order ditutup setelah tidak ada yang menunggu perbaikan.</p>
                    @endif
                </div>

                @if($isAdmin)
                    <details class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 text-sm">
                        <summary class="cursor-pointer font-semibold text-gray-700">🔓 Lepas tanpa timbang (admin)</summary>
                        <form method="POST" action="{{ route('production.qc.lepas') }}" class="mt-3 space-y-2">
                            @csrf
                            <input type="hidden" name="production_order_id" value="{{ $order->id }}">
                            <input type="hidden" name="product_id" value="{{ $productId }}">
                            <input type="number" name="qty" min="1" required placeholder="Jumlah bungkus"
                                   class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                            <input type="text" name="reason" required maxlength="255" placeholder="Alasan (wajib), mis. timbangan rusak"
                                   class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                            <button class="w-full border border-orange-300 text-orange-700 hover:bg-orange-50 px-3 py-2 rounded-xl text-sm font-semibold">Lepas</button>
                        </form>
                    </details>
                @endif

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                    <div class="font-bold text-gray-700 text-sm mb-2">Riwayat</div>
                    <div class="space-y-1 max-h-96 overflow-y-auto">
                        <template x-for="r in riwayat" :key="r.id">
                            <div class="flex items-start justify-between gap-2 text-xs border-b border-gray-50 pb-1">
                                <div class="min-w-0">
                                    <span class="font-bold"
                                          :class="r.result === 'lulus' ? 'text-emerald-600' : (r.result === 'dilepas' ? 'text-orange-600' : 'text-red-600')"
                                          x-text="r.result === 'dilepas' ? ('DILEPAS ×' + r.qty) : r.result.toUpperCase()"></span>
                                    <span x-show="r.aktual" x-text="g(r.aktual)"></span>
                                    <span x-show="r.mode === 'perbaikan'" class="text-orange-500">· perbaikan</span>
                                    <span x-show="r.repair === 'menunggu'" class="text-orange-500">· tunggu perbaikan</span>
                                    <span x-show="r.repair === 'diperbaiki'" class="text-gray-400">· sudah diperbaiki</span>
                                    <div class="text-gray-500 truncate" x-text="r.pesan"></div>
                                </div>
                                <div class="text-gray-400 shrink-0 text-right"><div x-text="r.waktu"></div><div x-text="r.oleh"></div></div>
                            </div>
                        </template>
                        <p x-show="!riwayat.length" class="text-xs text-gray-400">Belum ada penimbangan.</p>
                    </div>
                </div>
            </div>
        </div>
        @elseif($order && $productId && !$target)
            <div class="col-span-9 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 h-fit">
                Profil QC produk ini belum punya berat acuan. Ambil sampel dulu di tab Sampel Acuan.
            </div>
        @elseif($order)
            <div class="col-span-9 text-sm text-gray-400">Pilih produk yang akan di-QC.</div>
        @else
            <div class="col-span-9 text-sm text-gray-400">Pilih order produksi di sebelah kiri.</div>
        @endif
    </div>
</div>

<script>
    function qcTimbang(cfg) {
        return {
            mode: 'normal', hasil: null, galat: '', manual: '', sibuk: false, adaBeban: false,
            ringkasan: cfg.ringkasan, riwayat: cfg.riwayat, cfg,
            timer: cfg.timer, jeda: [],
            // Penimbang: yang sedang tercatat di langkah QC, atau pilihan terakhir di komputer ini.
            penimbangId: cfg.aktif || (() => { try { return Number(localStorage.getItem('qc_penimbang')) || ''; } catch (e) { return ''; } })(),

            init() {
                if (this.penimbangId && !(cfg.penimbang || []).some((e) => e.id === this.penimbangId)) this.penimbangId = '';
            },
            simpanPenimbang() { try { localStorage.setItem('qc_penimbang', this.penimbangId || ''); } catch (e) {} },

            // Bungkus diangkat → hasil lama hilang, supaya bungkus berikutnya yang belum stabil
            // tidak terlihat "LULUS" karena kartu bungkus sebelumnya masih tampil.
            beban(ada) {
                if (!ada) { this.hasil = null; this.galat = ''; }
                this.adaBeban = ada;
            },

            g(v) { return (Number(v) || 0).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' g'; },
            n(v) { return (Number(v) || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }); },

            tangkap(d) { this.kirim(d.gram, d.source, d.raw); },

            async kirim(gram, source, raw) {
                if (!gram || this.sibuk) return;
                if (this.timer === 'pending' && !this.penimbangId) {
                    this.galat = 'Pilih penimbang dulu — timer QC dicatat atas namanya.'; window.bunyiQc(false); return;
                }
                this.sibuk = true; this.galat = '';
                try {
                    const r = await fetch(cfg.url, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json',
                                   'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                        body: JSON.stringify({ production_order_id: cfg.op, product_id: cfg.produk, gram, mode: this.mode, source, raw,
                                               executor_ids: this.penimbangId ? [this.penimbangId] : [] }),
                    });
                    const d = await r.json().catch(() => ({}));
                    if (!d.success) { this.galat = d.pesan || d.message || 'Gagal mencatat timbangan.'; window.bunyiQc(false); return; }

                    this.hasil = d.check;
                    this.ringkasan = d.ringkasan;
                    if (d.timer) this.timer = d.timer;
                    if (d.jeda && d.jeda.length) this.jeda = d.jeda;
                    // Perbaikan lulus menutup catatan gagal TERTUA (sama seperti di server).
                    if (d.check.mode === 'perbaikan' && d.check.result === 'lulus') {
                        const tua = [...this.riwayat].reverse().find((x) => x.repair === 'menunggu');
                        if (tua) tua.repair = 'diperbaiki';
                    }
                    this.riwayat.unshift({ id: d.check.id, result: d.check.result, aktual: d.check.aktual, selisih: d.check.selisih,
                        mode: d.check.mode, qty: 1, pesan: d.check.pesan,
                        repair: d.check.result !== 'lulus' && d.check.mode !== 'perbaikan' ? 'menunggu' : null,
                        waktu: new Date().toLocaleString('id-ID', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }),
                        oleh: '{{ auth()->user()?->name }}' });
                    window.bunyiQc(d.check.result === 'lulus');
                    if (this.mode === 'perbaikan' && !this.ringkasan.menunggu) this.mode = 'normal';
                } catch (e) {
                    this.galat = 'Gagal mengirim — periksa koneksi.';
                } finally {
                    this.sibuk = false;
                }
            },
        };
    }
</script>
@endsection
