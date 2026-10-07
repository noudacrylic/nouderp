@extends('layouts.erp')

@section('content')
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
@endphp
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-start mb-5 gap-3 flex-wrap">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Ubah Kelompok {{ $group->number }}</h1>
            <p class="text-xs text-gray-500 mt-0.5">Isi qty 0 untuk mengeluarkan barang dari kelompok. Qty dibatasi barang yang belum masuk kelompok lain atau OP.</p>
        </div>
        <a href="{{ route('production.perbaikan.index', ['tab' => 'kelompok']) }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kelompok</a>
    </div>

    @if(!$group->isTerbuka())
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            Kelompok ini berstatus <b>{{ $group->statusLabel() }}</b> — hanya kelompok Terbuka yang bisa diubah.
        </div>
    @else
    <form method="POST" action="{{ route('production.perbaikan.kelompok.update', $group->id) }}" x-data="{ isi: {}, hpp: {} }"
          class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 px-5 py-4 border-b border-gray-100">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">Nama kelompok *</label>
                <input type="text" name="name" value="{{ old('name', $group->name) }}" required maxlength="255"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">Catatan</label>
                <input type="text" name="notes" value="{{ old('notes', $group->notes) }}" maxlength="1000"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
        </div>

        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                <tr>
                    <th class="px-4 py-2 text-left">Produk</th>
                    <th class="px-4 py-2 text-right w-28">HPP / unit</th>
                    <th class="px-4 py-2 text-right w-32">Maks. untuk kelompok ini</th>
                    <th class="px-4 py-2 text-right w-32">Qty di kelompok</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($menunggu as $m)
                    @php $maks = round(max($m['bisa'], 0), 4); @endphp
                    <tr class="{{ $m['isi'] > 0 ? 'bg-blue-50/40' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-800">{{ $m['nama'] }}</div>
                            <div class="text-[11px] font-mono text-gray-400">{{ $m['sku'] }}</div>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($m['hpp_unit'], 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right text-gray-500">{{ $qty($maks) }}</td>
                        <td class="px-4 py-3 text-right">
                            <input type="number" step="any" min="0" max="{{ $maks }}"
                                   name="items[{{ $m['product_id'] }}]"
                                   value="{{ old('items.' . $m['product_id'], $m['isi'] > 0 ? $m['isi'] + 0 : 0) }}"
                                   x-init="isi[{{ $m['product_id'] }}] = $el.value; hpp[{{ $m['product_id'] }}] = {{ $m['hpp_unit'] }}"
                                   @input="isi[{{ $m['product_id'] }}] = $el.value"
                                   class="w-24 border rounded-lg px-2 py-1 text-sm text-right">
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="flex items-center justify-end gap-3 px-5 py-4 border-t border-gray-100">
            <span class="text-xs text-gray-500">
                Total HPP awal:
                <b x-text="'Rp ' + Math.round(Object.keys(isi).reduce((s, id) => s + (parseFloat(isi[id]) || 0) * (hpp[id] || 0), 0)).toLocaleString('id-ID')"></b>
            </span>
            <button type="submit" class="px-5 py-2 rounded-lg text-sm font-bold bg-blue-600 text-white hover:bg-blue-700">Simpan</button>
        </div>
    </form>
    @endif
</div>
@endsection
