{{--
    Repeater baris isi beranda (keunggulan / kartu jalur pembeli / FAQ).

    Baris dikelola Alpine di browser: nama input diberi indeks ulang tiap render
    (`nama[0][judul]`, `nama[1][judul]`, …). Tanpa penomoran ulang itu, menghapus
    baris tengah meninggalkan indeks berlubang, dan PHP membaca sisanya sebagai
    objek — bukan larik — sehingga urutannya kacau di etalase.

    Parameter: $name, $rows, $max, $label, $fields[{key,label,width,placeholder,textarea}]
--}}
@php
    // Pastikan setiap baris punya semua kunci; baris lama dari versi sebelumnya
    // bisa saja belum mengenal kolom yang baru ditambahkan.
    $keys      = collect($fields)->pluck('key')->all();
    $blank     = array_fill_keys($keys, '');
    $normalRows = collect($rows)->map(fn ($r) => array_merge($blank, array_intersect_key((array) $r, $blank)))->values()->all();
@endphp

<div x-data="{
        rows: {{ json_encode($normalRows ?: [$blank]) }},
        blank: {{ json_encode($blank) }},
        add() { if (this.rows.length < {{ $max }}) this.rows.push({ ...this.blank }); },
     }"
     class="space-y-3">

    <template x-for="(row, i) in rows" :key="i">
        <div class="bg-white rounded shadow p-4">
            <div class="flex items-start justify-between mb-2">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                    {{ $label }} <span x-text="i + 1"></span>
                </span>
                <button type="button" x-on:click="rows.splice(i, 1)"
                        class="text-red-500 hover:text-red-700 text-xs">Hapus</button>
            </div>

            <div class="flex flex-col md:flex-row gap-3">
                @foreach($fields as $f)
                    <div class="{{ $f['width'] ?? 'flex-1' }}">
                        <label class="block text-xs text-gray-500 mb-1">{{ $f['label'] }}</label>
                        @if($f['textarea'] ?? false)
                            <textarea rows="3"
                                      x-bind:name="`{{ $name }}[${i}][{{ $f['key'] }}]`"
                                      x-model="rows[i]['{{ $f['key'] }}']"
                                      placeholder="{{ $f['placeholder'] ?? '' }}"
                                      class="border rounded px-3 py-2 w-full text-sm"></textarea>
                        @else
                            <input type="text"
                                   x-bind:name="`{{ $name }}[${i}][{{ $f['key'] }}]`"
                                   x-model="rows[i]['{{ $f['key'] }}']"
                                   placeholder="{{ $f['placeholder'] ?? '' }}"
                                   class="border rounded px-3 py-2 w-full text-sm">
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </template>

    <button type="button" x-on:click="add()"
            x-bind:disabled="rows.length >= {{ $max }}"
            class="text-xs border border-emerald-600 text-emerald-700 hover:bg-emerald-50 disabled:opacity-40 disabled:cursor-not-allowed rounded px-3 py-1.5">
        + Tambah {{ strtolower($label) }}
    </button>
    <span class="text-xs text-gray-400 ml-2">Maksimal {{ $max }}. Baris yang dikosongkan semua akan terhapus saat disimpan.</span>
</div>
