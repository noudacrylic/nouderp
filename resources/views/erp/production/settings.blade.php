@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="max-w-5xl mx-auto flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Pengaturan Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Konfigurasi kalkulasi Score BOM dan parameter produksi.</p>
        </div>
        <a href="{{ route('production.boms.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← BOM</a>
    </div>



    @php
        $curChoice = match($setting->score_period_mode ?? 'months') {
            'week'  => 'week',
            'range' => 'range',
            default => (string) max(1, (int) ($setting->score_sales_period ?? 1)),
        };
        if (!in_array($curChoice, ['week','1','2','3','range'], true)) $curChoice = '1';
    @endphp

    <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      {{-- Kolom kiri: Periode Score + Aksi Cepat --}}
      <div class="space-y-4">
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6"
             x-data="{ choice: '{{ $curChoice }}' }">
            <h3 class="font-bold text-gray-700 mb-1 text-sm">Periode Penjualan untuk Kalkulasi Score</h3>
            <p class="text-xs text-gray-400 mb-5">
                Score BOM = Demand + Bobot Stok. Demand dihitung dari total penjualan pada periode terpilih
                dibagi kapasitas produksi (Jumlah Siklus × Output Utama per Siklus).
            </p>

            <form action="{{ route('production.settings.update') }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-500 mb-1">Periode penjualan</label>
                    <select name="period_choice" x-model="choice"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
                        <option value="week">Mingguan (7 hari terakhir)</option>
                        <option value="1">1 Bulan Terakhir</option>
                        <option value="2">2 Bulan Terakhir</option>
                        <option value="3">3 Bulan Terakhir</option>
                        <option value="range">Rentang Tanggal (tetap)</option>
                    </select>
                </div>

                {{-- Input rentang — muncul saat pilih "Rentang Tanggal" --}}
                <div x-show="choice === 'range'" x-transition style="display:none" class="mb-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Dari Tanggal</label>
                        <input type="date" name="period_start"
                               value="{{ optional($setting->score_period_start)->format('Y-m-d') }}"
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Sampai Tanggal</label>
                        <input type="date" name="period_end"
                               value="{{ optional($setting->score_period_end)->format('Y-m-d') }}"
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 mb-4 text-xs text-blue-700">
                    <span x-show="choice === 'week'">📅 <b>Mingguan</b>: penjualan 7 hari terakhir (bergulir). Data awal penjualan ikut diproporsikan per jumlah hari.</span>
                    <span x-show="choice !== 'week' && choice !== 'range'">📅 <b>Bulanan</b>: penjualan N bulan terakhir penuh (bergulir, selalu mengikuti bulan berjalan).</span>
                    <span x-show="choice === 'range'">📅 <b>Rentang tetap</b>: periode membeku pada tanggal yang dipilih — tidak bergulir otomatis seiring waktu.</span>
                </div>

                <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 mb-5 text-xs text-amber-700">
                    <strong>Rumus Score:</strong><br>
                    Kapasitas = Jumlah Siklus × Qty Output Utama per Siklus<br>
                    Demand = Total Penjualan (SO + Data Awal) pada Periode / Kapasitas<br>
                    Bobot Stok: Minus +300 | Habis +200 | Menipis +100 | OK +0 | PreOrder +300<br>
                    <strong>Score = Demand + Bobot Stok</strong>
                </div>

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                    Simpan Pengaturan
                </button>
            </form>
        </div>

        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
            <h3 class="font-bold text-gray-700 mb-3 text-sm">Aksi Cepat</h3>
            <div class="space-y-2">
                <form action="{{ route('production.boms.recalculate') }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="w-full border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition text-left px-4">
                        ↻ Hitung Ulang Score Semua BOM Aktif
                    </button>
                </form>
                <a href="{{ route('sales.reports.manual-sales') }}"
                   class="block w-full border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition px-4">
                    ✏ Input Data Awal Penjualan →
                </a>
            </div>
        </div>

        {{-- Mode Testing --}}
        <div class="bg-white border rounded-2xl shadow-sm p-5 {{ ($setting->testing_mode ?? false) ? 'border-amber-300' : 'border-gray-100' }}">
            <form action="{{ route('production.settings.testing.update') }}" method="POST">
                @csrf
                <input type="hidden" name="testing_mode" value="0">
                <label class="flex items-start gap-3 cursor-pointer">
                    <span class="flex-1">
                        <span class="block font-bold text-gray-700 text-sm">Mode Testing</span>
                        <span class="block text-[11px] text-gray-500 mt-1 leading-relaxed">
                            Bila aktif, <b>Mulai</b> &amp; lanjut task <b>tidak butuh scan sidik jari</b>
                            (lewati cek check-in &amp; jam kerja). Khusus uji coba alur produksi — matikan saat produksi nyata.
                        </span>
                    </span>
                    {{-- Toggle switch (auto-submit saat diubah) --}}
                    <span class="relative inline-flex items-center flex-shrink-0 mt-0.5">
                        <input type="checkbox" name="testing_mode" value="1" class="sr-only peer"
                               {{ ($setting->testing_mode ?? false) ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        <span class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-amber-500 transition"></span>
                        <span class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>
            </form>
            @if($setting->testing_mode ?? false)
                <div class="mt-3 text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                    ⚠ Mode testing AKTIF — task bisa dimulai tanpa scan. Jangan lupa matikan di produksi nyata.
                </div>
            @endif
        </div>
      </div>

      {{-- Kolom kanan: Bahan Baku + Produk Sampingan (by-product) --}}
      <div class="space-y-4">
        @php
            $fmtDim = fn($v) => $v ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
            $initRawMaterials = ($rawMaterials ?? collect())->map(fn($m) => [
                'product_id'   => $m->product_id,
                'productQuery' => ($m->product?->sku ? $m->product->sku . ' - ' : '') . ($m->product?->name ?? '—'),
                'panjang'      => $fmtDim($m->panjang),
                'lebar'        => $fmtDim($m->lebar),
                'results'      => [],
                'showDrop'     => false,
            ])->values()->all();
            $initByproducts = ($byproducts ?? collect())->map(fn($b) => [
                'product_id'   => $b->product_id,
                'productQuery' => ($b->product?->sku ? $b->product->sku . ' - ' : '') . ($b->product?->name ?? '—'),
                'percentage'   => $b->percentage === null ? '' : rtrim(rtrim(number_format((float) $b->percentage, 4, '.', ''), '0'), '.'),
                'raw_material_product_id' => $b->raw_material_product_id,
                'rawQuery'     => ($b->rawMaterial?->sku ? $b->rawMaterial->sku . ' - ' : '') . ($b->rawMaterial?->name ?? ''),
                'panjang'      => $fmtDim($b->panjang),
                'lebar'        => $fmtDim($b->lebar),
                'results'      => [],
                'showDrop'     => false,
                'rawResults'   => [],
                'rawDrop'      => false,
            ])->values()->all();
        @endphp

        {{-- Bahan Baku (lembaran) + ukuran PxL — untuk Kalkulator Produk Custom --}}
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6"
             x-data="rawMaterialSettings({{ Js::from($initRawMaterials) }})">
            <div class="flex justify-between items-center mb-1">
                <h3 class="font-bold text-gray-700 text-sm">Bahan Baku</h3>
                <button type="button" @click="addRow()"
                        class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah</button>
            </div>
            <p class="text-xs text-gray-400 mb-4">
                Daftar produk <b>bahan baku</b> (lembaran) beserta ukuran lembar <b>Panjang × Lebar (cm)</b>.
                Dipakai <b>Kalkulator Produk Custom</b> di OP untuk menghitung kebutuhan bahan baku.
            </p>
            <form action="{{ route('production.settings.raw-materials.update') }}" method="POST">
                @csrf
                <div class="space-y-2">
                    <template x-for="(r, idx) in rows" :key="idx">
                        <div class="flex gap-2 items-start">
                            <div class="flex-1 relative" @click.outside="r.showDrop = false">
                                <input type="text" x-model="r.productQuery"
                                       @input.debounce.300ms="search(idx)"
                                       @focus="r.showDrop = true"
                                       placeholder="Cari produk bahan baku..."
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                <input type="hidden" :name="`rows[${idx}][product_id]`" :value="r.product_id">
                                <div x-show="r.showDrop && r.results.length > 0"
                                     class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                    <template x-for="p in r.results" :key="p.id">
                                        <div @click="select(idx, p)"
                                             class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                            <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                            <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <input type="number" :name="`rows[${idx}][panjang]`" x-model="r.panjang"
                                   min="0" step="0.01" placeholder="P (cm)"
                                   class="w-24 border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                            <input type="number" :name="`rows[${idx}][lebar]`" x-model="r.lebar"
                                   min="0" step="0.01" placeholder="L (cm)"
                                   class="w-24 border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                            <button type="button" @click="rows.splice(idx, 1)"
                                    class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                        </div>
                    </template>
                    <div x-show="rows.length === 0" class="text-xs text-gray-400 text-center py-4">
                        Belum ada bahan baku. Klik "+ Tambah" untuk mendaftarkan.
                    </div>
                </div>
                <button type="submit"
                        class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                    Simpan Bahan Baku
                </button>
            </form>
        </div>
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6"
             x-data="byproductSettings({{ Js::from($initByproducts) }})">
            <div class="flex justify-between items-center mb-1">
                <h3 class="font-bold text-gray-700 text-sm">Produk Sampingan</h3>
                <button type="button" @click="addRow()"
                        class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah</button>
            </div>
            <p class="text-xs text-gray-400 mb-4">
                Daftar produk <b>ready stok</b> atau <b>custom/preorder</b> yang boleh menjadi output sampingan di BOM/OP, beserta
                <b>% biaya per unit</b> (basis 1 siklus produksi). Persentase ini otomatis mengisi BOM/OP dan
                menghitung HPP saat finalisasi. <b>% boleh dikosongi</b> — artinya tidak ada nilai default
                sehingga di OP wajib diisi manual (komposisi/ukuran sering beda per order). <b>Bahan baku + PxL (cm)</b>
                (baris bawah) opsional — wajib bila dipakai Kalkulator Produk Custom.
            </p>

            <form action="{{ route('production.settings.byproducts.update') }}" method="POST">
                @csrf
                <div class="space-y-2">
                    <template x-for="(r, idx) in rows" :key="idx">
                        <div class="border border-gray-100 rounded-xl p-2.5 space-y-2 bg-gray-50/40">
                            {{-- Baris 1: produk sampingan + % biaya --}}
                            <div class="flex gap-2 items-start">
                                <div class="flex-1 relative" @click.outside="r.showDrop = false">
                                    <input type="text" x-model="r.productQuery"
                                           @input.debounce.300ms="search(idx)"
                                           @focus="r.showDrop = true"
                                           placeholder="Cari produk ready stok / custom..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <input type="hidden" :name="`rows[${idx}][product_id]`" :value="r.product_id">
                                    <div x-show="r.showDrop && r.results.length > 0"
                                         class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                        <template x-for="p in r.results" :key="p.id">
                                            <div @click="select(idx, p)"
                                                 class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                                <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="w-24 relative">
                                    <input type="number" :name="`rows[${idx}][percentage]`" x-model="r.percentage"
                                           min="0" max="100" step="0.0001" placeholder="%"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 pr-7 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">%</span>
                                </div>
                                <button type="button" @click="rows.splice(idx, 1)"
                                        class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                            </div>
                            {{-- Baris 2: bahan baku + PxL kebutuhan (untuk Kalkulator Custom) --}}
                            <div class="flex gap-2 items-start">
                                <div class="flex-1 relative" @click.outside="r.rawDrop = false">
                                    <input type="text" x-model="r.rawQuery"
                                           @input.debounce.300ms="searchRaw(idx)"
                                           @focus="r.rawDrop = true"
                                           placeholder="Bahan baku (opsional)..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                    <input type="hidden" :name="`rows[${idx}][raw_material_product_id]`" :value="r.raw_material_product_id">
                                    <div x-show="r.rawDrop && r.rawResults.length > 0"
                                         class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                        <template x-for="p in r.rawResults" :key="p.id">
                                            <div @click="selectRaw(idx, p)"
                                                 class="px-3 py-2 text-sm hover:bg-emerald-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                <span class="font-bold text-emerald-600" x-text="p.sku"></span>
                                                <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <input type="number" :name="`rows[${idx}][panjang]`" x-model="r.panjang"
                                       min="0" step="0.01" placeholder="P (cm)"
                                       class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                <input type="number" :name="`rows[${idx}][lebar]`" x-model="r.lebar"
                                       min="0" step="0.01" placeholder="L (cm)"
                                       class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                <span class="w-9"></span>
                            </div>
                        </div>
                    </template>
                    <div x-show="rows.length === 0" class="text-xs text-gray-400 text-center py-4">
                        Belum ada produk sampingan. Klik "+ Tambah" untuk mendaftarkan.
                    </div>
                </div>

                <button type="submit"
                        class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                    Simpan Produk Sampingan
                </button>
            </form>
        </div>
      </div>
    </div>

</div>

@push('scripts')
<script>
function byproductSettings(init) {
    return {
        rows: init || [],
        addRow() {
            this.rows.push({
                product_id: '', productQuery: '', percentage: '', results: [], showDrop: false,
                raw_material_product_id: '', rawQuery: '', panjang: '', lebar: '', rawResults: [], rawDrop: false,
            });
        },
        search(idx) {
            const r = this.rows[idx];
            const q = (r.productQuery || '').trim();
            if (q.length < 1) { r.results = []; return; }
            fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}&byproduct_only=1`)
                .then(res => res.json())
                .then(data => { r.results = data; r.showDrop = true; });
        },
        select(idx, p) {
            const r = this.rows[idx];
            r.product_id = p.id;
            r.productQuery = (p.sku ? p.sku + ' - ' : '') + p.name;
            r.results = [];
            r.showDrop = false;
        },
        // Bahan baku untuk produk sampingan (cari semua produk).
        searchRaw(idx) {
            const r = this.rows[idx];
            const q = (r.rawQuery || '').trim();
            if (q.length < 1) { r.rawResults = []; return; }
            fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => { r.rawResults = data; r.rawDrop = true; });
        },
        selectRaw(idx, p) {
            const r = this.rows[idx];
            r.raw_material_product_id = p.id;
            r.rawQuery = (p.sku ? p.sku + ' - ' : '') + p.name;
            r.rawResults = [];
            r.rawDrop = false;
        },
    };
}

function rawMaterialSettings(init) {
    return {
        rows: init || [],
        addRow() {
            this.rows.push({ product_id: '', productQuery: '', panjang: '', lebar: '', results: [], showDrop: false });
        },
        search(idx) {
            const r = this.rows[idx];
            const q = (r.productQuery || '').trim();
            if (q.length < 1) { r.results = []; return; }
            fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => { r.results = data; r.showDrop = true; });
        },
        select(idx, p) {
            const r = this.rows[idx];
            r.product_id = p.id;
            r.productQuery = (p.sku ? p.sku + ' - ' : '') + p.name;
            r.results = [];
            r.showDrop = false;
        },
    };
}
</script>
@endpush
@endsection
