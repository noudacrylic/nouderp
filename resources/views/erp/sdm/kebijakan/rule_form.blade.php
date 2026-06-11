@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')
@include('erp.sdm.kebijakan._subtabs')

@php
    use App\Modules\SDM\Models\KebijakanRule;
    use App\Modules\SDM\Models\Kebijakan;
    use App\Modules\SDM\Models\KebijakanKolom;

    $isEdit = $rule->exists;

    $existingConditions = old('conditions', collect($rule->conditions ?? [])->map(function ($c) {
        if (($c['op'] ?? null) === 'in' && is_array($c['value'] ?? null)) {
            $c['value'] = implode(', ', $c['value']);
        }
        return $c;
    })->all());
    $existingEffects    = old('effects', $rule->effects ?? []);

    $statusOpts = Kebijakan::STATUS_LABELS;
    $spOpts     = Kebijakan::SP_LEVELS;
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $isEdit ? 'Edit' : 'Buat' }} Rule Kebijakan</h1>
    <a href="{{ route('sdm.kebijakan.rule.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Kembali</a>
</div>


<form method="POST" action="{{ $isEdit ? route('sdm.kebijakan.rule.update', $rule->id) : route('sdm.kebijakan.rule.store') }}" class="space-y-5 max-w-5xl"
      x-data="ruleBuilder({
          fields:    {{ json_encode(KebijakanRule::FIELDS) }},
          operators: {{ json_encode(KebijakanRule::OPERATORS) }},
          kinds:     {{ json_encode(KebijakanRule::EFFECT_KINDS) }},
          conditions: {{ json_encode($existingConditions) }},
          effects:    {{ json_encode($existingEffects) }},
          statusOpts: {{ json_encode($statusOpts) }},
          spOpts:     {{ json_encode($spOpts) }}
      })">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="bg-white rounded shadow p-5 space-y-3">
        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Nama Rule</label>
                <input type="text" name="nama" value="{{ old('nama', $rule->nama) }}" required class="border rounded px-3 py-2 w-full text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Prioritas (kecil = menang)</label>
                <input type="number" name="priority" value="{{ old('priority', $rule->priority ?? 100) }}" required min="0" class="border rounded px-3 py-2 w-full text-sm">
            </div>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Deskripsi (opsional)</label>
            <textarea name="deskripsi" rows="2" class="border rounded px-3 py-2 w-full text-sm">{{ old('deskripsi', $rule->deskripsi) }}</textarea>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))>
            Aktifkan rule
        </label>
    </div>

    {{-- SEBAB (Conditions) --}}
    <div class="bg-white rounded shadow p-5">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="font-semibold text-gray-800">Sebab (Kondisi)</h2>
                <p class="text-xs text-gray-500">Semua kondisi harus terpenuhi (AND). Kosong = selalu berlaku.</p>
            </div>
            <button type="button" @click="addCondition" class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded">+ Tambah Kondisi</button>
        </div>
        <template x-if="conditions.length === 0">
            <div class="text-xs text-gray-400 italic py-3">Belum ada kondisi.</div>
        </template>
        <template x-for="(c, idx) in conditions" :key="idx">
            <div class="grid grid-cols-12 gap-2 items-start mb-2">
                <div class="col-span-4">
                    <select :name="`conditions[${idx}][field]`" x-model="c.field" class="border rounded px-2 py-1.5 text-sm w-full">
                        <template x-for="(meta, key) in fields" :key="key">
                            <option :value="key" x-text="meta.label"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-3">
                    <select :name="`conditions[${idx}][op]`" x-model="c.op" class="border rounded px-2 py-1.5 text-sm w-full">
                        <template x-for="(lab, op) in operators" :key="op">
                            <option :value="op" x-text="lab"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-4">
                    {{-- value input — kontekstual berdasarkan field & op --}}
                    <template x-if="fieldTipe(c.field) === 'enum_status' && c.op !== 'in'">
                        <select :name="`conditions[${idx}][value]`" x-model="c.value" class="border rounded px-2 py-1.5 text-sm w-full">
                            <template x-for="(lab, k) in statusOpts" :key="k">
                                <option :value="k" x-text="lab"></option>
                            </template>
                        </select>
                    </template>
                    <template x-if="fieldTipe(c.field) === 'enum_sp' && c.op !== 'in'">
                        <select :name="`conditions[${idx}][value]`" x-model="c.value" class="border rounded px-2 py-1.5 text-sm w-full">
                            <template x-for="sp in spOpts" :key="sp">
                                <option :value="sp" x-text="sp"></option>
                            </template>
                        </select>
                    </template>
                    <template x-if="fieldTipe(c.field) === 'time'">
                        <input type="time" :name="`conditions[${idx}][value]`" x-model="c.value" class="border rounded px-2 py-1.5 text-sm w-full font-mono">
                    </template>
                    <template x-if="fieldTipe(c.field) === 'number'">
                        <input type="number" step="0.1" :name="`conditions[${idx}][value]`" x-model="c.value" class="border rounded px-2 py-1.5 text-sm w-full">
                    </template>
                    <template x-if="fieldTipe(c.field) === 'bool'">
                        <select :name="`conditions[${idx}][value]`" x-model="c.value" class="border rounded px-2 py-1.5 text-sm w-full">
                            <option value="1">Ya / True</option>
                            <option value="0">Tidak / False</option>
                        </select>
                    </template>
                    <template x-if="c.op === 'in' && ['enum_status','enum_sp'].includes(fieldTipe(c.field))">
                        <div x-data="{ open: false, search: '' }" @click.away="open = false" class="relative">
                            <input type="hidden" :name="`conditions[${idx}][value]`" :value="c.value">
                            <button type="button" @click="open = !open"
                                    class="border rounded px-2 py-1.5 text-sm w-full text-left bg-white flex items-center justify-between gap-2">
                                <span class="truncate" :class="selectedLabels(c).length ? 'text-gray-800' : 'text-gray-400'"
                                      x-text="selectedLabels(c).length ? selectedLabels(c).join(', ') : '— pilih nilai —'"></span>
                                <span class="text-gray-400 text-xs">▾</span>
                            </button>
                            <div x-show="open" x-cloak
                                 class="absolute z-20 mt-1 w-full bg-white border rounded shadow-lg max-h-64 overflow-y-auto">
                                <div class="p-2 border-b sticky top-0 bg-white">
                                    <input type="text" x-model="search" placeholder="Cari..." @click.stop
                                           class="border rounded px-2 py-1 text-sm w-full">
                                </div>
                                <template x-for="(lab, k) in filteredOpts(c, search)" :key="k">
                                    <label class="flex items-center gap-2 px-2 py-1.5 text-sm cursor-pointer hover:bg-gray-50">
                                        <input type="checkbox" :value="k" :checked="isSelected(c, k)" @change="toggleValue(c, k)">
                                        <span x-text="lab"></span>
                                    </label>
                                </template>
                                <template x-if="Object.keys(filteredOpts(c, search)).length === 0">
                                    <div class="px-3 py-2 text-xs text-gray-400 italic">Tidak ada hasil</div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="col-span-1 text-right">
                    <button type="button" @click="removeCondition(idx)" class="text-xs bg-red-600 text-white px-2 py-1.5 rounded">×</button>
                </div>
            </div>
        </template>
    </div>

    {{-- AKIBAT (Effects) --}}
    <div class="bg-white rounded shadow p-5">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="font-semibold text-gray-800">Akibat (Set Kolom)</h2>
                <p class="text-xs text-gray-500">Set nilai kolom akibat. Beberapa akibat boleh — masing-masing untuk kolom berbeda.</p>
            </div>
            <button type="button" @click="addEffect" class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded">+ Tambah Akibat</button>
        </div>
        <template x-if="effects.length === 0">
            <div class="text-xs text-gray-400 italic py-3">Belum ada akibat. Rule tanpa akibat tidak melakukan apa-apa.</div>
        </template>
        <template x-for="(e, idx) in effects" :key="idx">
            <div class="grid grid-cols-12 gap-2 items-start mb-2">
                <div class="col-span-4">
                    <select :name="`effects[${idx}][kolom]`" x-model="e.kolom" class="border rounded px-2 py-1.5 text-sm w-full">
                        <option value="">— pilih target —</option>
                        @php $grouped = collect($targets)->groupBy('group'); @endphp
                        @foreach($grouped as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach($items as $t)
                                    <option value="{{ $t['key'] }}">{{ $t['label'] }} <span class="text-gray-400">({{ $t['tipe'] }})</span></option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-4">
                    <select :name="`effects[${idx}][kind]`" x-model="e.kind" class="border rounded px-2 py-1.5 text-sm w-full">
                        <template x-for="(lab, k) in kinds" :key="k">
                            <option :value="k" x-text="lab"></option>
                        </template>
                    </select>
                </div>
                <div class="col-span-3">
                    <template x-if="kindNeedsValue(e.kind)">
                        <input type="text" :name="`effects[${idx}][value]`" x-model="e.value"
                               :placeholder="kindPlaceholder(e.kind)"
                               class="border rounded px-2 py-1.5 text-sm w-full font-mono">
                    </template>
                </div>
                <div class="col-span-1 text-right">
                    <button type="button" @click="removeEffect(idx)" class="text-xs bg-red-600 text-white px-2 py-1.5 rounded">×</button>
                </div>
            </div>
        </template>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded text-sm font-semibold">{{ $isEdit ? 'Simpan Perubahan' : 'Simpan Rule' }}</button>
        <a href="{{ route('sdm.kebijakan.rule.index') }}" class="text-sm text-gray-600 px-4 py-2">Batal</a>
    </div>
</form>

<style>[x-cloak] { display: none !important; }</style>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function ruleBuilder(initial) {
    return {
        fields: initial.fields,
        operators: initial.operators,
        kinds: initial.kinds,
        statusOpts: initial.statusOpts,
        spOpts: initial.spOpts,
        conditions: initial.conditions || [],
        effects: initial.effects || [],

        fieldTipe(field) {
            return this.fields[field]?.tipe || 'text';
        },
        addCondition() {
            this.conditions.push({field: 'status', op: 'eq', value: 'hadir'});
        },
        removeCondition(i) { this.conditions.splice(i, 1); },
        addEffect() {
            this.effects.push({kolom: '', kind: 'nominal', value: ''});
        },
        removeEffect(i) { this.effects.splice(i, 1); },
        kindNeedsValue(kind) {
            return ['nominal', 'persen_gaji_hari', 'flag'].includes(kind);
        },
        kindPlaceholder(kind) {
            if (kind === 'nominal') return 'mis. 11000';
            if (kind === 'persen_gaji_hari') return 'mis. 50 (= 50%)';
            if (kind === 'flag') return 'kosong = true, atau label flag';
            return '';
        },

        optsFor(c) {
            const tipe = this.fieldTipe(c.field);
            if (tipe === 'enum_status') return this.statusOpts;
            if (tipe === 'enum_sp') {
                return Object.fromEntries((this.spOpts || []).map(s => [s, s]));
            }
            return {};
        },
        selectedValues(c) {
            if (!c.value) return [];
            if (Array.isArray(c.value)) return c.value.map(String);
            return String(c.value).split(',').map(s => s.trim()).filter(Boolean);
        },
        selectedLabels(c) {
            const opts = this.optsFor(c);
            return this.selectedValues(c).map(v => opts[v] || v);
        },
        isSelected(c, k) {
            return this.selectedValues(c).includes(String(k));
        },
        toggleValue(c, k) {
            const cur = this.selectedValues(c);
            const i = cur.indexOf(String(k));
            if (i >= 0) cur.splice(i, 1);
            else cur.push(String(k));
            c.value = cur.join(', ');
        },
        filteredOpts(c, search) {
            const opts = this.optsFor(c);
            if (!search) return opts;
            const s = search.toLowerCase();
            return Object.fromEntries(
                Object.entries(opts).filter(([k, lab]) =>
                    String(k).toLowerCase().includes(s) || String(lab).toLowerCase().includes(s)
                )
            );
        },
    };
}
</script>
@endsection
