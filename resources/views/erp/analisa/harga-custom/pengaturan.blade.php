@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',');
    $input = 'border border-slate-200 rounded-xl px-3 py-2 text-sm w-full';
    $label = 'block text-[11px] font-bold text-slate-500 mb-1';
    $saved = $category->params ?? [];
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">Pengaturan Harga Custom &middot; {{ $recipe->label() }}</h1>
        <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
            Angka yang jarang diubah. Tarif per slot-jam dan jam kerja TIDAK diatur di sini &mdash; keduanya diambil dari
            Kuota Produksi supaya harga custom memakai dasar yang sama dengan HPP produk Ready.
        </p>
    </div>

    @include('erp.analisa.harga-custom._kategori', ['routeName' => 'analisa.harga-custom.pengaturan'])

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 items-start">

        {{-- ── Kategori ── --}}
        <form method="POST" action="{{ route('analisa.harga-custom.pengaturan.save') }}"
              class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 space-y-4">
            @csrf
            <input type="hidden" name="kategori" value="{{ $category->code }}">
            <div class="text-sm font-bold text-slate-700">{{ $category->name }}</div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="{{ $label }}">Biaya setting per pesanan</label>
                    <input type="text" name="setup_cost" value="{{ number_format($category->setup_cost, 0, ',', '.') }}" required class="rupiah-input {{ $input }} text-right">
                </div>
                <div>
                    <label class="{{ $label }}">Markup (%)</label>
                    <input type="text" inputmode="decimal" name="markup_percent" value="{{ $angka($category->markup_percent, 2) }}" required class="{{ $input }} text-right">
                </div>
                <div>
                    <label class="{{ $label }}">Sisa bahan (%)</label>
                    <input type="text" inputmode="decimal" name="waste_percent" value="{{ $angka($category->waste_percent, 2) }}" required class="{{ $input }} text-right">
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                <input type="checkbox" name="min_order_enabled" value="1" @checked($category->min_order_enabled) class="rounded">
                Pakai minimal order (½ hari kerja seluruh produksi)
            </label>

            <div class="grid grid-cols-2 gap-3">
                @foreach(['cnc_department_id' => 'Divisi potong (CNC)', 'assembly_department_id' => 'Divisi rakit (Assembling)'] as $field => $lbl)
                    <div>
                        <label class="{{ $label }}">{{ $lbl }}</label>
                        <select name="{{ $field }}" class="{{ $input }}">
                            <option value="">— pilih —</option>
                            @foreach($departments as $d)
                                <option value="{{ $d->id }}" @selected((int) $category->{$field} === (int) $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>

            <div class="pt-3 border-t border-slate-100">
                <div class="text-xs font-bold text-slate-600 mb-2">Parameter rumus</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($paramDefs as $key => $def)
                        <div>
                            <label class="{{ $label }}">{{ $def['label'] }} <span class="font-normal text-slate-400">({{ $def['unit'] }})</span></label>
                            <input type="text" inputmode="decimal" name="params[{{ $key }}]"
                                   value="{{ isset($saved[$key]) ? $angka($saved[$key], 2) : '' }}"
                                   placeholder="{{ $angka($def['default'], 2) }}"
                                   class="{{ $input }} text-right">
                        </div>
                    @endforeach
                </div>
                <p class="text-[11px] text-slate-400 mt-2">Kosong = memakai angka bawaan (abu-abu). Angka waktu ini dikalikan faktor dari halaman Faktor Waktu.</p>
            </div>

            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-bold">Simpan pengaturan</button>
        </form>

        {{-- ── Bahan ── --}}
        <form method="POST" action="{{ route('analisa.harga-custom.pengaturan.bahan') }}"
              class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            @csrf
            <input type="hidden" name="kategori" value="{{ $category->code }}">
            <div class="px-5 py-3 border-b border-slate-100">
                <div class="text-sm font-bold text-slate-700">Bahan lembaran</div>
                <div class="text-[11px] text-slate-500">
                    Daftar &amp; ukuran lembar dari Pengaturan Produksi &rsaquo; Bahan Baku. Harga = harga beli terakhir.
                    Tebal kosong = dibaca dari nama; kecepatan kosong = bawaan tebal (1,5mm 30 · 2mm 25 · 3mm 18 · 5mm 10 mm/dtk).
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="min-width:640px">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400">
                        <tr>
                            <th class="px-5 py-2 text-left">Bahan</th>
                            <th class="px-3 py-2 text-right">Lembar</th>
                            <th class="px-3 py-2 text-right">Harga</th>
                            <th class="px-3 py-2 text-right">Rp/cm²</th>
                            <th class="px-3 py-2 text-right">Tebal mm</th>
                            <th class="px-5 py-2 text-right">mm/dtk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($materials as $m)
                            <tr>
                                <td class="px-5 py-2">
                                    {{ $m['name'] }}
                                    <div class="text-[11px] text-slate-400">{{ $m['sku'] }}</div>
                                </td>
                                <td class="px-3 py-2 text-right text-xs">{{ $angka($m['sheet_p']) }}×{{ $angka($m['sheet_l']) }}</td>
                                <td class="px-3 py-2 text-right text-xs">
                                    {{ $rp($m['price']) }}
                                    @if($m['price_date'])<div class="text-[10px] text-slate-400">{{ $m['price_date'] }}</div>@endif
                                </td>
                                <td class="px-3 py-2 text-right text-xs">{{ $m['price_per_cm2'] !== null ? number_format($m['price_per_cm2'], 2, ',', '.') : '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <input type="text" inputmode="decimal" name="bahan[{{ $m['product_id'] }}][tebal_mm]"
                                           value="{{ $angka($m['tebal_saved'], 2) }}" placeholder="{{ $angka($m['tebal_mm'], 2) ?: '?' }}"
                                           class="border border-slate-200 rounded-lg px-2 py-1 text-sm w-16 text-right">
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <input type="text" inputmode="decimal" name="bahan[{{ $m['product_id'] }}][cut_speed]"
                                           value="{{ $angka($m['speed_saved'], 2) }}" placeholder="{{ $angka($m['cut_speed'], 2) }}"
                                           class="border border-slate-200 rounded-lg px-2 py-1 text-sm w-16 text-right">
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-slate-400 text-sm">
                                Belum ada bahan lembaran. Isi di Pengaturan Produksi &rsaquo; Bahan Baku.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($materials->isNotEmpty())
                <div class="px-5 py-3 border-t border-slate-100 flex justify-end">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-bold">Simpan bahan</button>
                </div>
            @endif
        </form>
    </div>
</div>
@endsection
