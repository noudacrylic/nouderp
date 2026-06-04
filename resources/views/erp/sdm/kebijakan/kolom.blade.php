@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')
@include('erp.sdm.kebijakan._subtabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Kolom Akibat &amp; Baris Summary</h1>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        <ul class="list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php
    $kolomJson = $kolom->mapWithKeys(fn ($k) => [$k->id => [
        'id'        => $k->id,
        'key'       => $k->key,
        'label'     => $k->label,
        'tipe'      => $k->tipe,
        'urutan'    => (int) $k->urutan,
        'is_system' => (bool) $k->is_system,
        'is_active' => (bool) $k->is_active,
    ]])->all();
@endphp

<div x-data="kolomModal()" @keydown.escape.window="show && close()">

<div class="flex items-center justify-between mt-4 mb-2">
    <h2 class="text-base font-semibold">Kolom Akibat (per-baris)</h2>
    <button type="button" @click="openCreate()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm font-semibold">
        + Tambah Kolom
    </button>
</div>

<div class="bg-white rounded shadow overflow-hidden">
    <div class="grid grid-cols-12 gap-3 px-4 py-2 bg-gray-50 border-b text-xs font-semibold text-gray-600 uppercase">
        <div class="col-span-1">Urutan</div>
        <div class="col-span-3">Label</div>
        <div class="col-span-3">Key</div>
        <div class="col-span-2">Tipe</div>
        <div class="col-span-1 text-center">Aktif</div>
        <div class="col-span-2 text-right">Aksi</div>
    </div>
    @foreach($kolom as $k)
        <div class="grid grid-cols-12 gap-3 px-4 py-2.5 border-b items-center text-sm hover:bg-gray-50">
            <div class="col-span-1 text-gray-700">{{ $k->urutan }}</div>
            <div class="col-span-3 font-medium text-gray-800">{{ $k->label }}</div>
            <div class="col-span-3 font-mono text-xs text-gray-500">
                {{ $k->key }}
                @if($k->is_system) <span class="text-[10px] bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded ml-1">system</span> @endif
            </div>
            <div class="col-span-2 text-xs text-gray-600">{{ \App\Modules\SDM\Models\KebijakanKolom::TIPE[$k->tipe] ?? '—' }}</div>
            <div class="col-span-1 text-center">
                @if($k->is_active)
                    <span class="text-emerald-600">✓</span>
                @else
                    <span class="text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded">nonaktif</span>
                @endif
            </div>
            <div class="col-span-2 text-right flex gap-1 justify-end">
                <button type="button" @click='openEdit(@json($kolomJson[$k->id]))' class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded">Edit</button>
                @if(! $k->is_system)
                    <button type="button" onclick="document.getElementById('del-kolom-{{ $k->id }}').submit()" class="text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1.5 rounded">×</button>
                @endif
            </div>
        </div>
        @if(! $k->is_system)
            <form id="del-kolom-{{ $k->id }}" method="POST" action="{{ route('sdm.kebijakan.kolom.destroy', $k->id) }}" onsubmit="return confirm('Hapus kolom {{ $k->label }}? Effects di rule yang merujuk kolom ini akan ikut dihapus.');" class="hidden">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach
</div>

{{-- ============================ MODAL KOLOM ============================ --}}
<div x-show="show" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-start justify-center pt-10 pb-10 overflow-y-auto" @click.self="close()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-xl mx-4 my-4">
        <form :method="'POST'" :action="formAction()">
            @csrf
            <template x-if="isEdit"><input type="hidden" name="_method" value="PUT"></template>

            <div class="flex items-center justify-between px-5 py-3 border-b">
                <h3 class="text-base font-semibold">
                    <span x-text="isEdit ? 'Edit Kolom Akibat' : 'Tambah Kolom Akibat'"></span>
                </h3>
                <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
            </div>

            <div class="px-5 py-4 space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-900 leading-relaxed">
                    <strong>Kolom Akibat</strong> = kolom yang muncul per-baris di tabel <strong>Daftar Absensi</strong>. Nilainya dihasilkan dari <a href="{{ route('sdm.kebijakan.rule.index') }}" class="underline">Rule Kebijakan</a>. Item <em>system</em> tidak dapat dihapus.
                </div>

                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-7">
                        <label class="block text-xs text-gray-500 mb-1">Label Kolom <span class="text-red-500">*</span></label>
                        <input type="text" name="label" x-model="form.label" required placeholder="Mis. Potongan Telat" class="border rounded px-3 py-2 w-full text-sm">
                    </div>
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-500 mb-1">Key <span class="text-red-500">*</span></label>
                        <input type="text" name="key" x-model="form.key" :readonly="isEdit" required pattern="[a-z0-9_]+"
                               placeholder="potongan_telat"
                               class="border rounded px-3 py-2 w-full text-sm font-mono"
                               :class="isEdit ? 'bg-gray-50 text-gray-500' : ''">
                        <p class="text-[11px] text-gray-400 mt-0.5">Huruf kecil &amp; underscore. Tidak bisa diubah setelah dibuat.</p>
                    </div>
                </div>

                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-6">
                        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
                        <select name="tipe" x-model="form.tipe" :disabled="form.is_system" class="border rounded px-3 py-2 w-full text-sm">
                            @foreach(\App\Modules\SDM\Models\KebijakanKolom::TIPE as $val => $lab)
                                <option value="{{ $val }}">{{ $lab }}</option>
                            @endforeach
                        </select>
                        <p x-show="form.is_system" class="text-[11px] text-amber-600 mt-0.5">Tipe kolom system tidak dapat diubah.</p>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Urutan</label>
                        <input type="number" name="urutan" x-model.number="form.urutan" min="0" class="border rounded px-3 py-2 w-full text-sm">
                    </div>
                    <div class="col-span-3 flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm pb-2 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="rounded">
                            <span>Aktif</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 px-5 py-3 border-t bg-gray-50">
                <button type="button" @click="close()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Batal</button>
                <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>
    </div>
</div>

</div> {{-- /x-data kolomModal --}}

{{-- ============================ BARIS SUMMARY ============================ --}}
@php
    $bulanList = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $tahunSekarang = (int) date('Y');
    $tahunList = range($tahunSekarang - 1, $tahunSekarang + 2);

    $summaryJson = $summary->map(function ($s) {
        return [
            'id'             => $s->id,
            'key'            => $s->key,
            'label'          => $s->label,
            'urutan'         => (int) $s->urutan,
            'mode'           => $s->mode,
            'arah'           => $s->arah,
            'scope'          => $s->scope ?? 'all',
            'recurrence'     => $s->recurrence ?? 'monthly',
            'nominal_manual' => (float) $s->nominal_manual,
            'is_system'      => (bool) $s->is_system,
            'is_active'      => (bool) $s->is_active,
            'values'         => $s->values->map(fn ($v) => [
                'karyawan_id' => $v->karyawan_id,
                'bulan'       => $v->bulan,
                'tahun'       => $v->tahun,
                'nominal'     => (float) $v->nominal,
            ])->all(),
        ];
    })->keyBy('id');

    $karyawanMap = $karyawans->mapWithKeys(fn ($k) => [$k->id => $k->name . ' (' . $k->staf_code . ')'])->all();
@endphp

<div x-data="summaryModal()" @keydown.escape.window="show && close()">

<div class="flex items-center justify-between mt-8 mb-2">
    <h2 class="text-base font-semibold">Baris Summary (kotak ringkasan)</h2>
    <button type="button" @click="openCreate()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm font-semibold">
        + Tambah Baris
    </button>
</div>

<div class="bg-white rounded shadow overflow-hidden">
    <div class="grid grid-cols-12 gap-2 px-4 py-2 bg-gray-50 border-b text-xs font-semibold text-gray-600 uppercase">
        <div class="col-span-1">Urutan</div>
        <div class="col-span-2">Label</div>
        <div class="col-span-1">Key</div>
        <div class="col-span-1">Mode</div>
        <div class="col-span-1">Arah</div>
        <div class="col-span-1">Scope</div>
        <div class="col-span-2">Berlaku</div>
        <div class="col-span-2">Nilai</div>
        <div class="col-span-1 text-right">Aksi</div>
    </div>
    @foreach($summary as $s)
        @php
            $scope = $s->scope ?? 'all';
            $recur = $s->recurrence ?? 'monthly';
            $valCount = $s->values->count();
        @endphp
        <div class="grid grid-cols-12 gap-2 px-4 py-2.5 border-b items-center text-sm hover:bg-gray-50">
            <div class="col-span-1 text-gray-700">{{ $s->urutan }}</div>
            <div class="col-span-2">
                <div class="font-medium text-gray-800">{{ $s->label }}</div>
                @if(! $s->is_active)<span class="text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded">nonaktif</span>@endif
            </div>
            <div class="col-span-1 font-mono text-[11px] text-gray-500 truncate" title="{{ $s->key }}">
                {{ $s->key }}
                @if($s->is_system) <span class="text-[10px] bg-gray-200 text-gray-600 px-1 py-0.5 rounded ml-1">sys</span> @endif
            </div>
            <div class="col-span-1 text-xs text-gray-600">{{ $s->mode === 'manual' ? 'Manual' : 'Auto' }}</div>
            <div class="col-span-1 text-xs text-gray-600">{{ $s->arah === 'plus' ? '+' : '−' }}</div>
            <div class="col-span-1 text-xs text-gray-600">{{ $scope === 'all' ? 'Semua' : 'Per-Org' }}</div>
            <div class="col-span-2 text-xs text-gray-600">{{ \App\Modules\SDM\Models\KebijakanSummary::RECURRENCES[$recur] ?? '—' }}</div>
            <div class="col-span-2 text-xs">
                @if($s->is_system)
                    <span class="text-gray-400">—</span>
                @elseif($s->mode === 'auto')
                    <span class="text-blue-600">Diatur via <a href="{{ route('sdm.kebijakan.rule.index') }}" class="hover:underline">Rule</a></span>
                @elseif($scope === 'all' && $recur === 'monthly')
                    <span class="font-mono text-gray-700">Rp {{ number_format($s->nominal_manual, 0, ',', '.') }}</span>
                @elseif($scope === 'per_karyawan' && $recur === 'one_time')
                    <span class="text-gray-400 italic">Diisi di Summary Absensi</span>
                @else
                    <span class="text-gray-700">{{ $valCount }} entri</span>
                    @if($valCount === 0)<span class="text-[10px] text-amber-600 ml-1">(belum di-set)</span>@endif
                @endif
            </div>
            <div class="col-span-1 text-right flex gap-1 justify-end items-center">
                <button type="button" @click='openEdit(@json($summaryJson[$s->id]))' class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded">Edit</button>
                @if(! $s->is_system)
                    <button type="button" onclick="document.getElementById('del-sum-{{ $s->id }}').submit()" class="text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded">×</button>
                @endif
            </div>
        </div>
        @if(! $s->is_system)
            <form id="del-sum-{{ $s->id }}" method="POST" action="{{ route('sdm.kebijakan.summary.destroy', $s->id) }}" onsubmit="return confirm('Hapus baris {{ $s->label }}? Semua nilai per-karyawan/per-bulan untuk baris ini juga akan ikut terhapus.');" class="hidden">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach
</div>

{{-- ============================ MODAL ============================ --}}
<div x-show="show" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-start justify-center pt-10 pb-10 overflow-y-auto" @click.self="close()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 my-4">
        <form :method="'POST'" :action="formAction()" @submit="onSubmit">
            @csrf
            <template x-if="isEdit"><input type="hidden" name="_method" value="PUT"></template>

            <div class="flex items-center justify-between px-5 py-3 border-b">
                <h3 class="text-base font-semibold">
                    <span x-text="isEdit ? 'Edit Baris Summary' : 'Tambah Baris Summary'"></span>
                </h3>
                <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
            </div>

            <div class="px-5 py-4 space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-900 leading-relaxed">
                    <div class="font-semibold mb-1">Kombinasi Scope &amp; Berlaku:</div>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li><strong>Semua + Tiap Bulan</strong>: nominal sama untuk semua orang, dipakai tiap bulan.</li>
                        <li><strong>Semua + Bulan Tertentu</strong>: pilih bulan-bulan mana saja (bisa lebih dari satu), nominal sama untuk semua orang.</li>
                        <li><strong>Per Karyawan + Tiap Bulan</strong>: pilih karyawan tertentu (bisa lebih dari satu) dengan nominal masing-masing, dipakai tiap bulan.</li>
                        <li><strong>Per Karyawan + Bulan Tertentu</strong>: nominal di-input langsung di Summary <a href="{{ route('sdm.absensi.index') }}" class="underline">Daftar Absensi</a>.</li>
                    </ul>
                </div>

                {{-- Baris 1: Label, Key, Urutan, Aktif --}}
                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-5">
                        <label class="block text-xs text-gray-500 mb-1">Label Baris <span class="text-red-500">*</span></label>
                        <input type="text" name="label" x-model="form.label" required class="border rounded px-3 py-2 w-full text-sm">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Key <span class="text-red-500">*</span></label>
                        <input type="text" name="key" x-model="form.key" :readonly="isEdit" required pattern="[a-z0-9_]+"
                               class="border rounded px-3 py-2 w-full text-sm font-mono"
                               :class="isEdit ? 'bg-gray-50 text-gray-500' : ''">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs text-gray-500 mb-1">Urutan</label>
                        <input type="number" name="urutan" x-model.number="form.urutan" min="0" class="border rounded px-3 py-2 w-full text-sm">
                    </div>
                    <div class="col-span-2 flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm pb-1.5 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="rounded">
                            <span>Aktif</span>
                        </label>
                    </div>
                </div>

                {{-- Baris 2: Mode, Arah, Scope, Berlaku --}}
                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Mode</label>
                        <select name="mode" x-model="form.mode" :disabled="form.is_system" class="border rounded px-2 py-2 w-full text-sm">
                            <option value="manual">Nominal Manual</option>
                            <option value="auto">Auto dari Rule</option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Arah</label>
                        <select name="arah" x-model="form.arah" :disabled="form.is_system" class="border rounded px-2 py-2 w-full text-sm">
                            <option value="plus">+ Ditambahkan</option>
                            <option value="minus">− Potongan</option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Scope</label>
                        <select name="scope" x-model="form.scope" :disabled="form.is_system || form.mode === 'auto'" class="border rounded px-2 py-2 w-full text-sm">
                            <option value="all">Semua Karyawan</option>
                            <option value="per_karyawan">Per Karyawan</option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs text-gray-500 mb-1">Berlaku</label>
                        <select name="recurrence" x-model="form.recurrence" :disabled="form.is_system || form.mode === 'auto'" class="border rounded px-2 py-2 w-full text-sm">
                            <option value="monthly">Tiap Bulan</option>
                            <option value="one_time">Bulan Tertentu</option>
                        </select>
                    </div>
                </div>

                {{-- Section Nilai (dynamic) --}}
                <div class="border-t pt-4">
                    <div class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Nilai</div>

                    {{-- Mode = auto --}}
                    <div x-show="form.mode === 'auto'" class="bg-gray-50 border rounded p-3 text-xs text-gray-600">
                        Nilai akan diisi otomatis berdasarkan Rule Kebijakan yang punya effect ke key
                        <code class="bg-white px-1 rounded font-mono" x-text="form.key"></code>.
                        Atur di <a href="{{ route('sdm.kebijakan.rule.index') }}" class="text-blue-600 hover:underline">tab Rule</a>.
                        <input type="hidden" name="nominal_manual" value="0">
                    </div>

                    {{-- Shape B: all + monthly → single nominal_manual --}}
                    <div x-show="form.mode === 'manual' && form.scope === 'all' && form.recurrence === 'monthly'">
                        <label class="block text-xs text-gray-500 mb-1">Nominal (Rp/bulan)</label>
                        <input type="number" step="0.01" min="0" name="nominal_manual" x-model.number="form.nominal_manual" class="border rounded px-3 py-2 w-full md:w-1/2 text-sm text-right">
                        <p class="text-[11px] text-gray-400 mt-1">Berlaku untuk semua karyawan, setiap bulan.</p>
                    </div>

                    {{-- Shape D: per_karyawan + one_time → info only --}}
                    <div x-show="form.mode === 'manual' && form.scope === 'per_karyawan' && form.recurrence === 'one_time'" class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900">
                        <input type="hidden" name="nominal_manual" value="0">
                        <strong>Diisi langsung di Summary Absensi.</strong> Buka <a href="{{ route('sdm.absensi.index') }}" class="text-blue-600 hover:underline">Daftar Absensi</a>, pilih karyawan + bulan, lalu isi nominal di baris ini.
                    </div>

                    {{-- Shape C: all + one_time → list bulan + nominal --}}
                    <div x-show="form.mode === 'manual' && form.scope === 'all' && form.recurrence === 'one_time'">
                        <input type="hidden" name="nominal_manual" value="0">
                        <p class="text-[11px] text-gray-500 mb-2">Berlaku untuk semua karyawan, hanya pada bulan-bulan berikut:</p>
                        <table class="w-full text-sm border">
                            <thead class="bg-gray-50 text-xs text-gray-600">
                                <tr>
                                    <th class="px-2 py-1.5 text-left w-1/3">Bulan</th>
                                    <th class="px-2 py-1.5 text-left w-1/4">Tahun</th>
                                    <th class="px-2 py-1.5 text-right">Nominal (Rp)</th>
                                    <th class="px-2 py-1.5 w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(v, i) in form.values" :key="i">
                                    <tr class="border-t">
                                        <td class="px-2 py-1.5">
                                            <select :name="`values[${i}][bulan]`" x-model.number="v.bulan" class="border rounded px-2 py-1 w-full text-sm">
                                                @foreach($bulanList as $bn => $bl)
                                                    <option value="{{ $bn }}">{{ $bl }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <select :name="`values[${i}][tahun]`" x-model.number="v.tahun" class="border rounded px-2 py-1 w-full text-sm">
                                                @foreach($tahunList as $thn)
                                                    <option value="{{ $thn }}">{{ $thn }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="number" step="0.01" min="0" :name="`values[${i}][nominal]`" x-model.number="v.nominal" class="border rounded px-2 py-1 w-full text-sm text-right font-mono">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <button type="button" @click="removeValue(i)" class="text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="form.values.length === 0"><td colspan="4" class="px-2 py-3 text-center text-xs text-gray-400 italic">Belum ada bulan yang di-set. Klik "+ Tambah Bulan".</td></tr>
                            </tbody>
                        </table>
                        <button type="button" @click="addValueOneTime()" class="mt-2 text-xs bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-300 px-3 py-1.5 rounded">
                            + Tambah Bulan
                        </button>
                    </div>

                    {{-- Shape A: per_karyawan + monthly → list karyawan + nominal --}}
                    <div x-show="form.mode === 'manual' && form.scope === 'per_karyawan' && form.recurrence === 'monthly'">
                        <input type="hidden" name="nominal_manual" value="0">
                        <p class="text-[11px] text-gray-500 mb-2">Pilih karyawan yang menerima komponen ini setiap bulan, dengan nominal masing-masing:</p>
                        <table class="w-full text-sm border">
                            <thead class="bg-gray-50 text-xs text-gray-600">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">Karyawan</th>
                                    <th class="px-2 py-1.5 text-right w-1/3">Nominal (Rp/bulan)</th>
                                    <th class="px-2 py-1.5 w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(v, i) in form.values" :key="i">
                                    <tr class="border-t">
                                        <td class="px-2 py-1.5">
                                            <select :name="`values[${i}][karyawan_id]`" x-model.number="v.karyawan_id" class="border rounded px-2 py-1 w-full text-sm">
                                                <option value="">— Pilih karyawan —</option>
                                                @foreach($karyawans as $k)
                                                    <option value="{{ $k->id }}">{{ $k->name }} ({{ $k->staf_code }})</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <input type="number" step="0.01" min="0" :name="`values[${i}][nominal]`" x-model.number="v.nominal" class="border rounded px-2 py-1 w-full text-sm text-right font-mono">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <button type="button" @click="removeValue(i)" class="text-red-500 hover:text-red-700">×</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="form.values.length === 0"><td colspan="3" class="px-2 py-3 text-center text-xs text-gray-400 italic">Belum ada karyawan. Klik "+ Tambah Karyawan".</td></tr>
                            </tbody>
                        </table>
                        <button type="button" @click="addValuePerKaryawan()" class="mt-2 text-xs bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-300 px-3 py-1.5 rounded">
                            + Tambah Karyawan
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 px-5 py-3 border-t bg-gray-50">
                <button type="button" @click="close()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Batal</button>
                <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>
    </div>
</div>

</div> {{-- /x-data --}}

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak] { display: none !important; }</style>
<script>
function kolomModal() {
    const ROUTE_STORE  = "{{ route('sdm.kebijakan.kolom.store') }}";
    const ROUTE_UPDATE = "{{ route('sdm.kebijakan.kolom.update', ['id' => '__ID__']) }}";

    return {
        show: false,
        isEdit: false,
        form: {
            id: null, key: '', label: '', tipe: 'rupiah', urutan: 100,
            is_active: true, is_system: false,
        },

        openCreate() {
            this.isEdit = false;
            this.form = { id: null, key: '', label: '', tipe: 'rupiah', urutan: 100, is_active: true, is_system: false };
            this.show = true;
        },

        openEdit(data) {
            this.isEdit = true;
            this.form = {
                id: data.id, key: data.key, label: data.label, tipe: data.tipe,
                urutan: data.urutan, is_active: data.is_active, is_system: data.is_system,
            };
            this.show = true;
        },

        close() { this.show = false; },

        formAction() {
            return this.isEdit ? ROUTE_UPDATE.replace('__ID__', this.form.id) : ROUTE_STORE;
        },
    };
}

function summaryModal() {
    const ROUTE_STORE  = "{{ route('sdm.kebijakan.summary.store') }}";
    const ROUTE_UPDATE = "{{ route('sdm.kebijakan.summary.update', ['id' => '__ID__']) }}";
    const NOW = { bulan: {{ (int) date('n') }}, tahun: {{ (int) date('Y') }} };

    return {
        show: false,
        isEdit: false,
        form: {
            id: null, key: '', label: '', urutan: 100,
            mode: 'manual', arah: 'plus', scope: 'all', recurrence: 'monthly',
            nominal_manual: 0, is_active: true, is_system: false,
            values: [],
        },

        openCreate() {
            this.isEdit = false;
            this.form = {
                id: null, key: '', label: '', urutan: 100,
                mode: 'manual', arah: 'plus', scope: 'all', recurrence: 'monthly',
                nominal_manual: 0, is_active: true, is_system: false,
                values: [],
            };
            this.show = true;
        },

        openEdit(data) {
            this.isEdit = true;
            this.form = {
                id: data.id,
                key: data.key,
                label: data.label,
                urutan: data.urutan,
                mode: data.mode,
                arah: data.arah,
                scope: data.scope,
                recurrence: data.recurrence,
                nominal_manual: data.nominal_manual,
                is_active: data.is_active,
                is_system: data.is_system,
                values: (data.values || []).map(v => ({
                    karyawan_id: v.karyawan_id ?? '',
                    bulan: v.bulan ?? NOW.bulan,
                    tahun: v.tahun ?? NOW.tahun,
                    nominal: v.nominal ?? 0,
                })),
            };
            this.show = true;
        },

        close() { this.show = false; },

        addValueOneTime() {
            this.form.values.push({ karyawan_id: '', bulan: NOW.bulan, tahun: NOW.tahun, nominal: 0 });
        },
        addValuePerKaryawan() {
            this.form.values.push({ karyawan_id: '', bulan: null, tahun: null, nominal: 0 });
        },
        removeValue(i) { this.form.values.splice(i, 1); },

        formAction() {
            return this.isEdit ? ROUTE_UPDATE.replace('__ID__', this.form.id) : ROUTE_STORE;
        },

        onSubmit(e) {
            // Validasi minimal di sisi client
            if (this.form.mode === 'manual') {
                if (this.form.scope === 'per_karyawan' && this.form.recurrence === 'monthly') {
                    const dup = new Set();
                    for (const v of this.form.values) {
                        if (!v.karyawan_id) { e.preventDefault(); alert('Salah satu karyawan belum dipilih.'); return; }
                        if (dup.has(v.karyawan_id)) { e.preventDefault(); alert('Karyawan duplikat.'); return; }
                        dup.add(v.karyawan_id);
                    }
                }
                if (this.form.scope === 'all' && this.form.recurrence === 'one_time') {
                    const dup = new Set();
                    for (const v of this.form.values) {
                        if (!v.bulan || !v.tahun) { e.preventDefault(); alert('Bulan/tahun belum dipilih.'); return; }
                        const k = `${v.bulan}-${v.tahun}`;
                        if (dup.has(k)) { e.preventDefault(); alert(`Duplikat bulan ${k}.`); return; }
                        dup.add(k);
                    }
                }
            }
        },
    };
}
</script>
@endpush
@endsection
