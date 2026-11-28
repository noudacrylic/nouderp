{{--
    Field input resep Frame Mahar. Dipakai layar Hitung dan form sampel Faktor Waktu.
    Bahan dipilih per bagian: body (bawah, samping, depan-belakang, sliding) dan tutup.

    Butuh: $raw (array input sebelumnya), $materials.
--}}
@php
    $v     = fn (string $k, $d = '') => $raw[$k] ?? $d;
    $bahan = $raw['bahan'] ?? [];
    $input = 'border border-slate-200 rounded-xl px-3 py-2 text-sm w-full';
    $label = 'block text-[11px] font-bold text-slate-500 mb-1';
    $rpcm  = fn ($m) => $m['price_per_cm2'] !== null ? 'Rp' . number_format($m['price_per_cm2'], 2, ',', '.') . '/cm²' : 'harga belum ada';
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-3 gap-3">
        @foreach(['p' => 'Panjang', 'l' => 'Lebar', 't' => 'Tebal frame'] as $k => $lbl)
            <div>
                <label class="{{ $label }}">{{ $lbl }} (cm)</label>
                <input type="text" inputmode="decimal" name="{{ $k }}" value="{{ $v($k) }}" required class="{{ $input }} text-right">
            </div>
        @endforeach
    </div>
    <p class="text-[11px] text-slate-400 -mt-2">Sliding mengikuti sisi panjang (P). Mahar ready: tebal frame 6 cm.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
            <label class="{{ $label }}">Bahan body <span class="font-normal text-slate-400">(bawah, samping, depan-belakang, sliding)</span></label>
            <select name="bahan[utama]" required class="{{ $input }}">
                <option value="">— pilih bahan lembaran —</option>
                @foreach($materials as $m)
                    <option value="{{ $m['product_id'] }}" @selected((string) ($bahan['utama'] ?? '') === (string) $m['product_id'])>
                        {{ $m['name'] }} · {{ $rpcm($m) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}">Bahan tutup</label>
            <select name="bahan[tutup]" class="{{ $input }}">
                <option value="">sama dengan body</option>
                @foreach($materials as $m)
                    <option value="{{ $m['product_id'] }}" @selected((string) ($bahan['tutup'] ?? '') === (string) $m['product_id'])>
                        {{ $m['name'] }} · {{ $rpcm($m) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
    @if($materials->isEmpty())
        <p class="text-[11px] text-red-600">
            Belum ada bahan lembaran. Isi dulu di Pengaturan Produksi &rsaquo; Bahan Baku (produk + ukuran lembar).
        </p>
    @endif
</div>
