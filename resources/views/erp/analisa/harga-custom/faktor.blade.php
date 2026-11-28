@extends('layouts.erp')

@php
    $angka = fn (?float $v, int $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
    $menit = fn (?float $sec) => $sec === null || $sec <= 0 ? '—' : number_format($sec / 60, 1, ',', '.');
    $samples = $factors['samples'];
    $sumber = ['manual' => 'dikunci manual', 'data' => 'dari sampel', 'bawaan' => 'bawaan (belum ada sampel)'];
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Faktor Waktu &middot; {{ $recipe->label() }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                Rumus memperkirakan waktu dari ukuran (panjang potong, panjang lem, jumlah aksesoris). Faktor menggeser
                perkiraan itu supaya cocok dengan waktu nyata dari timer OP. Faktor 1,30 = kenyataannya 30% lebih lama
                dari rumus. Sampel = OP yang sudah selesai, diisi ukurannya sekali; waktunya dibaca langsung dari timer.
            </p>
        </div>
    </div>

    @include('erp.analisa.harga-custom._kategori', ['routeName' => 'analisa.harga-custom.faktor'])

    {{-- ── Faktor ── --}}
    <form method="POST" action="{{ route('analisa.harga-custom.faktor.save') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
        @csrf
        <input type="hidden" name="kategori" value="{{ $category->code }}">
        @foreach(['cnc' => ['CNC (potong)', 'cnc_factor_manual'], 'assembly' => ['Rakit (assembling)', 'assembly_factor_manual']] as $key => [$lbl, $field])
            @php $f = $factors[$key]; @endphp
            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400">{{ $lbl }}</div>
                        <div class="text-3xl font-black text-slate-800">{{ $angka($f['effective']) }}</div>
                        <div class="text-xs text-slate-500">{{ $sumber[$f['source']] }}</div>
                    </div>
                    <div class="text-right text-xs text-slate-500">
                        <div>Dari sampel: <strong>{{ $angka($f['data']) }}</strong></div>
                        <div>{{ $f['n'] }} sampel terpakai</div>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Kunci manual (kosong = ikut sampel)</label>
                    <input type="text" inputmode="decimal" name="{{ $field }}" value="{{ $f['manual'] !== null ? $angka($f['manual']) : '' }}"
                           placeholder="{{ $angka($f['data'] ?? 1) }}"
                           class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-32 text-right">
                </div>
            </div>
        @endforeach
        <div class="md:col-span-2 flex justify-end">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-bold">Simpan faktor</button>
        </div>
    </form>

    {{-- ── Sampel ── --}}
    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden mb-5">
        <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap justify-between gap-2">
            <div class="text-sm font-bold text-slate-700">Sampel ({{ count($samples) }})</div>
            <div class="text-[11px] text-slate-400">Waktu per pcs, dalam menit. Rasio = terukur &divide; rumus.</div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:1000px">
                <thead class="bg-slate-50 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-2 text-left">OP</th>
                        <th class="px-3 py-2 text-left">Ukuran</th>
                        <th class="px-3 py-2 text-right">Unit</th>
                        <th class="px-3 py-2 text-right">CNC rumus</th>
                        <th class="px-3 py-2 text-right">CNC nyata</th>
                        <th class="px-3 py-2 text-right">Rasio</th>
                        <th class="px-3 py-2 text-right">Rakit rumus</th>
                        <th class="px-3 py-2 text-right">Rakit nyata</th>
                        <th class="px-3 py-2 text-right">Rasio</th>
                        <th class="px-5 py-2 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($samples as $s)
                        <tr class="{{ $s['excluded'] ? 'text-slate-400 line-through decoration-slate-300' : '' }} align-top">
                            <td class="px-5 py-2">
                                <a href="{{ route('production.orders.show', $s['order_id']) }}" class="font-semibold text-blue-600 hover:underline no-underline">{{ $s['order_number'] }}</a>
                                <div class="text-[11px] text-slate-400 no-underline">{{ $s['product_name'] }} &middot; {{ $s['production_date'] }}</div>
                                @if($s['unfinished'])<div class="text-[11px] text-amber-600">⚠ ada langkah belum selesai</div>@endif
                                @if($s['excluded'])<div class="text-[11px] text-red-500" style="text-decoration:none">dicoret: {{ $s['exclude_reason'] }}</div>@endif
                                @if($s['notes'])<div class="text-[11px] text-slate-500">{{ $s['notes'] }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-xs">
                                {{ $s['description'] ?? '' }}
                                @foreach($s['errors'] as $e)<div class="text-red-500">{{ $e }}</div>@endforeach
                            </td>
                            <td class="px-3 py-2 text-right">{{ $angka($s['units'], 0) }}</td>
                            <td class="px-3 py-2 text-right">{{ $menit($s['predicted_cnc']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $menit($s['measured_cnc']) }}</td>
                            <td class="px-3 py-2 text-right font-semibold">{{ $angka($s['ratio_cnc']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $menit($s['predicted_assembly']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $menit($s['measured_assembly']) }}</td>
                            <td class="px-3 py-2 text-right font-semibold">{{ $angka($s['ratio_assembly']) }}</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap" style="text-decoration:none" x-data="{ coret: false }">
                                @if($s['excluded'])
                                    <form method="POST" action="{{ route('analisa.harga-custom.sampel.toggle', $s['id']) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-blue-600 hover:underline">Pakai lagi</button>
                                    </form>
                                @else
                                    <button type="button" @click="coret = !coret" class="text-xs text-amber-600 hover:underline">Coret</button>
                                    <form x-show="coret" x-cloak method="POST" action="{{ route('analisa.harga-custom.sampel.toggle', $s['id']) }}" class="mt-1 flex gap-1 justify-end">
                                        @csrf
                                        <input type="text" name="reason" required placeholder="alasan" class="border border-slate-200 rounded-lg px-2 py-1 text-xs w-32">
                                        <button class="text-xs bg-amber-500 text-white rounded-lg px-2">OK</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('analisa.harga-custom.sampel.destroy', $s['id']) }}" class="inline ml-2"
                                      onsubmit="return confirm('Hapus sampel {{ $s['order_number'] }}?')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-red-500 hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-5 py-8 text-center text-slate-400 text-sm">
                            Belum ada sampel. Selama belum ada, faktor = 1 (rumus apa adanya, angka awal dari spreadsheet).
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Tambah sampel ── --}}
    <form method="POST" action="{{ route('analisa.harga-custom.sampel.store') }}"
          class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 max-w-3xl space-y-4">
        @csrf
        <input type="hidden" name="kategori" value="{{ $category->code }}">
        <div class="text-sm font-bold text-slate-700">Tambah sampel</div>
        <p class="text-[11px] text-slate-500 -mt-2">
            Pilih OP yang timernya bersih (tidak lupa ditutup), lalu isi ukuran produknya seperti saat menghitung harga.
            OP yang sama diisi ulang = memperbarui sampelnya.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
                <label class="block text-[11px] font-bold text-slate-500 mb-1">Nomor OP</label>
                <input type="text" name="order_number" list="op-kandidat" value="{{ $raw['order_number'] ?? '' }}" required
                       class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full">
                <datalist id="op-kandidat">
                    @foreach($candidates as $o)
                        <option value="{{ $o->order_number }}">{{ $o->outputs->first()?->product?->name }} · {{ optional($o->production_date)->format('d/m/Y') }}</option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-500 mb-1">Jumlah unit di OP</label>
                <input type="text" inputmode="decimal" name="units" value="{{ $raw['units'] ?? '' }}" placeholder="otomatis"
                       class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full text-right">
            </div>
        </div>

        @include($recipe->formView())

        <div>
            <label class="block text-[11px] font-bold text-slate-500 mb-1">Catatan</label>
            <input type="text" name="notes" value="{{ $raw['notes'] ?? '' }}" maxlength="255"
                   class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-full">
        </div>

        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-bold">Simpan sampel</button>
    </form>
</div>
@endsection
