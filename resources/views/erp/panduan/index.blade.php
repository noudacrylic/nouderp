@extends('layouts.erp')

@section('content')
@php
    // Ubah map kategori jadi list + sisipkan key, untuk dipakai Alpine.
    $cats = collect($categories)->map(fn($c, $k) => array_merge($c, ['key' => $k]))->values()->all();
@endphp

<div class="mb-4">
    <h1 class="text-lg font-semibold flex items-center gap-2">📖 Panduan Penggunaan</h1>
    <p class="text-sm text-gray-500 mt-1">Panduan langkah demi langkah, dikelompokkan per tugas. Cari atau pilih kategori di bawah.</p>
</div>

<div x-data="panduanApp()" x-init="init()">

    {{-- Search --}}
    <div class="relative mb-4 max-w-xl">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="text" x-model="q" placeholder="Cari panduan... (mis. kasir, slip gaji, retur)"
               class="w-full border border-gray-200 rounded-xl pl-9 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
    </div>

    {{-- ===== Hasil pencarian ===== --}}
    <div x-show="q.trim() !== ''" x-cloak>
        <template x-if="filtered.length === 0">
            <div class="bg-white rounded-xl shadow-sm p-6 text-center text-gray-400 text-sm">Tidak ada panduan yang cocok.</div>
        </template>
        <div class="space-y-3">
            <template x-for="g in filtered" :key="g.cat + g.title">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                    <div class="text-[11px] uppercase tracking-wide text-blue-600 font-semibold" x-text="g.cat"></div>
                    <h3 class="font-semibold text-gray-800 mt-0.5" x-text="g.title"></h3>
                    <p class="text-sm text-gray-500 mt-1" x-text="g.intro"></p>
                    <ol class="list-decimal ml-5 mt-2 space-y-1 text-sm text-gray-700">
                        <template x-for="(s, i) in g.steps" :key="i"><li x-text="s"></li></template>
                    </ol>
                    <template x-if="g.tips && g.tips.length">
                        <div class="mt-3 bg-amber-50 border border-amber-100 rounded-lg p-3">
                            <div class="text-[11px] font-bold text-amber-700 mb-1">💡 Tips</div>
                            <ul class="list-disc ml-4 space-y-0.5 text-xs text-amber-800">
                                <template x-for="(t, i) in g.tips" :key="i"><li x-text="t"></li></template>
                            </ul>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- ===== Tampilan kategori (saat tidak mencari) ===== --}}
    <div x-show="q.trim() === ''" class="grid grid-cols-12 gap-4">

        {{-- Nav kategori --}}
        <div class="col-span-12 md:col-span-3">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-2 sticky top-4">
                <template x-for="c in cats" :key="c.key">
                    <button type="button" @click="activeKey = c.key"
                            class="w-full text-left px-3 py-2.5 rounded-lg text-sm flex items-center gap-2 transition"
                            :class="activeKey === c.key ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-700 hover:bg-gray-50'">
                        <span x-text="c.icon"></span>
                        <span class="flex-1" x-text="c.label"></span>
                        <template x-if="c.relevant">
                            <span class="text-[9px] uppercase font-bold bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded">Untuk Anda</span>
                        </template>
                    </button>
                </template>
            </div>
        </div>

        {{-- Isi kategori aktif --}}
        <div class="col-span-12 md:col-span-9">
            <template x-if="activeCat">
                <div>
                    <div class="mb-3">
                        <h2 class="text-base font-bold text-gray-800 flex items-center gap-2">
                            <span x-text="activeCat.icon"></span><span x-text="activeCat.label"></span>
                        </h2>
                        <p class="text-sm text-gray-500" x-text="activeCat.desc"></p>
                    </div>

                    <div class="space-y-2.5">
                        <template x-for="(g, gi) in activeCat.guides" :key="gi">
                            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden" x-data="{ open: gi === 0 }">
                                <button type="button" @click="open = !open"
                                        class="w-full flex items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50">
                                    <span class="font-semibold text-gray-800 text-sm" x-text="g.title"></span>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform shrink-0" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open" x-collapse class="px-4 pb-4">
                                    <p class="text-sm text-gray-500 mb-2" x-text="g.intro"></p>
                                    <ol class="list-decimal ml-5 space-y-1 text-sm text-gray-700">
                                        <template x-for="(s, i) in g.steps" :key="i"><li x-text="s"></li></template>
                                    </ol>
                                    <template x-if="g.tips && g.tips.length">
                                        <div class="mt-3 bg-amber-50 border border-amber-100 rounded-lg p-3">
                                            <div class="text-[11px] font-bold text-amber-700 mb-1">💡 Tips</div>
                                            <ul class="list-disc ml-4 space-y-0.5 text-xs text-amber-800">
                                                <template x-for="(t, i) in g.tips" :key="i"><li x-text="t"></li></template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
<script>
    function panduanApp() {
        return {
            cats: @json($cats),
            q: '',
            activeKey: null,
            init() {
                this.activeKey = (this.cats.find(c => c.relevant) || this.cats[0] || {}).key || null;
            },
            get activeCat() {
                return this.cats.find(c => c.key === this.activeKey) || null;
            },
            get filtered() {
                const q = this.q.trim().toLowerCase();
                if (!q) return [];
                const out = [];
                this.cats.forEach(c => {
                    (c.guides || []).forEach(g => {
                        const hay = (g.title + ' ' + g.intro + ' ' + (g.steps || []).join(' ') + ' ' + (g.tips || []).join(' ')).toLowerCase();
                        if (hay.includes(q)) out.push(Object.assign({ cat: c.label }, g));
                    });
                });
                return out;
            },
        };
    }
</script>
@endsection
