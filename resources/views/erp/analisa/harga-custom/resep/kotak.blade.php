{{--
    Field input resep Kotak hadap atas. Dipakai layar Hitung dan form sampel Faktor Waktu —
    satu form, supaya sampel diisi dengan cara yang persis sama dengan harga yang dihitung.

    Butuh: $raw (array input sebelumnya), $materials, $hinges, $recipe.
--}}
@php
    $v      = fn (string $k, $d = '') => $raw[$k] ?? $d;
    $bahan  = $raw['bahan'] ?? [];
    $beda   = collect($recipe->materialRoles())->keys()->contains(fn ($r) => !empty($bahan[$r]));
    $input  = 'border border-slate-200 rounded-xl px-3 py-2 text-sm w-full';
    $label  = 'block text-[11px] font-bold text-slate-500 mb-1';
    $rpcm   = fn ($m) => $m['price_per_cm2'] !== null ? 'Rp' . number_format($m['price_per_cm2'], 2, ',', '.') . '/cm²' : 'harga belum ada';
@endphp

<div x-data="{
        kantong: {{ $v('kantong') ? 'true' : 'false' }},
        {{-- Form baru: tutup dicentang (lebih sering dipakai). Sesudah dikirim: ikut isian. --}}
        tutup: {{ (isset($raw['p']) ? $v('tutup') : true) ? 'true' : 'false' }},
        tipe: '{{ $v('tutup_tipe', 'engsel') }}',
        beda: {{ $beda ? 'true' : 'false' }},
     }" class="space-y-4">

    {{-- Ukuran --}}
    <div class="grid grid-cols-3 gap-3">
        @foreach(['p' => 'Panjang', 'l' => 'Lebar', 't' => 'Tinggi'] as $k => $lbl)
            <div>
                <label class="{{ $label }}">{{ $lbl }} (cm, luar)</label>
                <input type="text" inputmode="decimal" name="{{ $k }}" value="{{ $v($k) }}" required class="{{ $input }} text-right">
            </div>
        @endforeach
    </div>

    {{-- Bahan --}}
    <div>
        <label class="{{ $label }}">Bahan utama</label>
        <select name="bahan[utama]" required class="{{ $input }}">
            <option value="">— pilih bahan lembaran —</option>
            @foreach($materials as $m)
                <option value="{{ $m['product_id'] }}" @selected((string) ($bahan['utama'] ?? '') === (string) $m['product_id'])>
                    {{ $m['name'] }} · {{ $rpcm($m) }}
                </option>
            @endforeach
        </select>
        @if($materials->isEmpty())
            <p class="text-[11px] text-red-600 mt-1">
                Belum ada bahan lembaran. Isi dulu di Pengaturan Produksi &rsaquo; Bahan Baku (produk + ukuran lembar).
            </p>
        @endif

        <label class="inline-flex items-center gap-2 mt-2 text-xs text-slate-600 cursor-pointer">
            <input type="checkbox" x-model="beda" class="rounded"> Bedakan bahan per bagian
        </label>
        <div x-show="beda" x-cloak class="grid grid-cols-2 md:grid-cols-3 gap-2 mt-2">
            @foreach($recipe->materialRoles() as $role => $lbl)
                <div>
                    <label class="{{ $label }}">{{ $lbl }}</label>
                    <select name="bahan[{{ $role }}]" class="{{ $input }}" x-bind:disabled="!beda">
                        <option value="">sama dengan utama</option>
                        @foreach($materials as $m)
                            <option value="{{ $m['product_id'] }}" @selected((string) ($bahan[$role] ?? '') === (string) $m['product_id'])>{{ $m['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Sekat --}}
    <div>
        <div class="{{ $label }}">Sekat (jumlah)</div>
        <div class="grid grid-cols-3 gap-3">
            @foreach([
                'sekat_pt' => ['Memanjang P×T', 'bg-blue-500'],
                'sekat_lt' => ['Melintang L×T', 'bg-red-500'],
                'sekat_pl' => ['Mendatar P×L', 'bg-green-500'],
            ] as $k => [$lbl, $warna])
                <div>
                    <div class="flex items-center gap-1.5 text-[11px] text-slate-500 mb-1">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm {{ $warna }}"></span>{{ $lbl }}
                    </div>
                    <input type="text" inputmode="numeric" name="{{ $k }}" value="{{ $v($k, 0) }}" class="{{ $input }} text-right">
                </div>
            @endforeach
        </div>
    </div>

    {{-- Kepala & kantong --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="{{ $label }}">Tinggi kepala (cm, kosong = tanpa kepala)</label>
            <input type="text" inputmode="decimal" name="kepala_tinggi" value="{{ $v('kepala_tinggi') }}" class="{{ $input }} text-right">
            <p class="text-[10px] text-slate-400 mt-1">Panel kepala = Panjang × tinggi kepala.</p>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer mb-1">
                <input type="checkbox" name="kantong" value="1" x-model="kantong" class="rounded"> Pakai kantong
            </label>
            <div x-show="kantong" x-cloak class="space-y-2">
                <select name="kantong_posisi" class="{{ $input }}">
                    <option value="depan" @selected($v('kantong_posisi') !== 'samping')>Di depan</option>
                    <option value="samping" @selected($v('kantong_posisi') === 'samping')>Di samping</option>
                </select>
                <div class="grid grid-cols-3 gap-2">
                    @foreach(['kantong_p' => 'P', 'kantong_l' => 'L', 'kantong_t' => 'T'] as $k => $lbl)
                        <input type="text" inputmode="decimal" name="{{ $k }}" value="{{ $v($k) }}" placeholder="{{ $lbl }} cm" class="{{ $input }} text-right">
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Tutup --}}
    <div class="border border-slate-100 rounded-xl p-3">
        <label class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer">
            <input type="checkbox" name="tutup" value="1" x-model="tutup" class="rounded"> Pakai tutup (P×L, di atas)
        </label>
        <div x-show="tutup" x-cloak class="mt-3 space-y-3">
            <div class="flex gap-4 text-sm">
                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="tutup_tipe" value="engsel" x-model="tipe"> Engsel
                </label>
                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="tutup_tipe" value="geser" x-model="tipe"> Geser (rail)
                </label>
            </div>
            <div x-show="tipe === 'engsel'" class="grid grid-cols-3 gap-2">
                <div class="col-span-2">
                    <label class="{{ $label }}">Produk engsel</label>
                    <select name="engsel_product_id" class="{{ $input }}">
                        <option value="">— pilih —</option>
                        @foreach($hinges as $h)
                            <option value="{{ $h->id }}" @selected((string) $v('engsel_product_id') === (string) $h->id)>{{ $h->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">Jumlah</label>
                    <input type="text" inputmode="numeric" name="engsel_qty" value="{{ $v('engsel_qty', 2) }}" class="{{ $input }} text-right">
                </div>
            </div>
            <p x-show="tipe === 'geser'" class="text-[11px] text-slate-500">Rail ikut dihitung otomatis (lihat Pengaturan untuk lebar &amp; jumlahnya).</p>
        </div>
    </div>

    {{-- Pengunci --}}
    <div>
        <label class="{{ $label }}">Pengunci</label>
        <select name="pengunci" class="{{ $input }}">
            <option value="tidak" @selected($v('pengunci', 'tidak') === 'tidak')>Tanpa pengunci</option>
            <option value="beli" @selected($v('pengunci') === 'beli')>Beli (grendel)</option>
            <option value="buat" @selected($v('pengunci') === 'buat')>Bikin sendiri</option>
        </select>
    </div>
</div>
