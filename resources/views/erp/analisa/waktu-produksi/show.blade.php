@extends('layouts.erp')

@section('content')
@php
    $p        = $data['product'];
    $qpc      = $data['qty_per_cycle'];
    $source   = $data['qty_per_cycle_source'];
    $conflict = $data['qty_per_cycle_conflict'];
@endphp
<div class="w-full px-6 py-4">

    @include('erp.analisa._hitung-ulang')

    <div class="flex justify-between items-start mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ $p['name'] }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">
                <span class="font-mono font-semibold text-blue-600">{{ $p['sku'] }}</span>
                · rata-rata waktu produksi dari {{ $data['included_count'] }} sampel OP
            </p>
        </div>
        <a href="{{ list_url('analisa.waktu-produksi.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            ← Kembali
        </a>
    </div>

    {{-- Sumber hasil per siklus --}}
    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-4">
        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Hasil per Siklus</div>
        @if($qpc !== null)
            <div class="text-lg font-bold text-gray-800">
                {{ rtrim(rtrim(number_format($qpc, 4, ',', '.'), '0'), ',') }}
                <span class="text-sm font-normal text-gray-500">{{ $p['base_unit'] }} / siklus</span>
            </div>
            <div class="text-sm text-gray-600 mt-0.5">
                Sumber:
                <a href="{{ route('production.boms.edit', $source['bom_id']) }}" class="text-blue-600 font-mono font-semibold hover:underline">{{ $source['bom_number'] }}</a>
                {{ $source['bom_name'] ? '· ' . $source['bom_name'] : '' }}
                <span class="text-gray-500">(dipakai {{ $source['votes'] }} dari {{ $source['total_voters'] }} sampel ber-BOM)</span>
            </div>
        @else
            <div class="text-sm text-amber-700">
                Tidak diketahui — tidak ada sampel yang terhubung ke BOM. Waktu per unit tidak bisa dihitung;
                angka per siklus di bawah tetap berlaku. Tautkan BOM ke OP produk ini agar per-unit muncul.
            </div>
        @endif
    </div>

    @if(!empty($conflict))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 mb-4 text-xs">
            <strong>Sampel memakai BOM dengan hasil/siklus berbeda.</strong>
            Dipakai {{ rtrim(rtrim(number_format($qpc, 4, ',', '.'), '0'), ',') }} dari {{ $source['bom_number'] }}.
            Nilai lain:
            @foreach($conflict as $c)
                <span class="font-mono">{{ $c['bom_number'] ?? ('BOM #' . $c['bom_id']) }}</span> =
                {{ rtrim(rtrim(number_format($c['qty_per_cycle'], 4, ',', '.'), '0'), ',') }} ({{ $c['votes'] }} sampel){{ !$loop->last ? ';' : '' }}
            @endforeach
        </div>
    @endif

    {{-- Hasil analisa --}}
    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-sm font-bold text-gray-800">Hasil Analisa</h2>
            <span class="text-xs text-gray-600">{{ $data['included_count'] }} sampel dipakai · {{ $data['excluded_count'] }} dikecualikan · {{ $data['ineligible_count'] }} tidak layak</span>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-5 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Divisi</th>
                    <th class="px-4 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Waktu / Siklus</th>
                    <th class="px-4 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Waktu / Unit</th>
                    <th class="px-4 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Detik / Unit</th>
                    <th class="px-4 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Sampel (n)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($departments as $dept)
                    @php $cell = $data['per_division'][$dept['id']] ?? null; @endphp
                    @if($cell)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-800">
                                {{ $dept['name'] }}
                                @if($dept['type'] === 'non_produksi')
                                    <span class="text-xs font-semibold border rounded px-1.5 py-0.5 bg-gray-100 text-gray-600 border-gray-200">non-produksi</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ dur_hms($cell['sec_per_cycle']) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-blue-600">{{ dur_hms($cell['sec_per_unit']) }}</td>
                            <td class="px-4 py-3 text-right text-gray-600 text-sm">{{ $cell['sec_per_unit'] === null ? '—' : number_format($cell['sec_per_unit'], 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-gray-600 text-sm">{{ $cell['n'] }}</td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400 text-xs">Belum ada sampel yang dipakai.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="bg-gray-50 border-t-2 border-gray-200">
                    <td class="px-5 py-3 font-black text-gray-600 text-xs uppercase">Total</td>
                    <td class="px-4 py-3 text-right font-black text-gray-800">{{ dur_hms($data['total']['sec_per_cycle']) }}</td>
                    <td class="px-4 py-3 text-right font-black text-blue-700">{{ dur_hms($data['total']['sec_per_unit']) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-600 text-sm">{{ $data['total']['sec_per_unit'] === null ? '—' : number_format($data['total']['sec_per_unit'], 2, ',', '.') }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <div class="px-5 py-2.5 text-xs text-gray-500 border-t border-gray-100">
            TOTAL = jumlah rata-rata tiap divisi. Kolom Sampel (n) bisa berbeda antar divisi karena tidak
            semua OP melewati semua divisi.
        </div>
    </div>

    {{-- Kapasitas & asumsi --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

        <form method="POST" action="{{ route('analisa.waktu-produksi.assumptions.save') }}"
              class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            @csrf
            <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-bold text-gray-800">Asumsi Waktu</h2>
                    <p class="text-[11px] text-gray-500 mt-0.5">
                        Diisi dalam menit per unit. Angka terukur tidak pernah ditimpa &mdash; matikan centangnya,
                        angka nyatanya kembali.
                    </p>
                </div>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Simpan</button>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-[10px] uppercase text-gray-500">
                    <tr>
                        <th class="px-5 py-2 text-left font-bold">Divisi</th>
                        <th class="px-3 py-2 text-right font-bold">Terukur<br>(mnt/unit)</th>
                        <th class="px-3 py-2 text-right font-bold bg-indigo-50/60">Asumsi<br>(mnt/unit)</th>
                        <th class="px-3 py-2 text-center font-bold bg-indigo-50/60">Pakai</th>
                        <th class="px-5 py-2 text-right font-bold">pcs/hari</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($departments as $dept)
                        @php $cell = $data['per_division'][$dept['id']] ?? null; @endphp
                        <tr class="{{ $cell && $cell['use_assumption'] ? 'bg-indigo-50/40' : '' }}">
                            <td class="px-5 py-2 font-semibold text-gray-800">{{ $dept['name'] }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">
                                {{ $cell && $cell['sec_per_unit'] !== null ? number_format($cell['sec_per_unit'] / 60, 1, ',', '.') : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right bg-indigo-50/40">
                                <input type="number" step="0.01" min="0"
                                       name="a[{{ $p['id'] }}][{{ $dept['id'] }}][minutes]"
                                       value="{{ $cell && $cell['assumed'] !== null ? round($cell['assumed'] / 60, 2) : '' }}"
                                       placeholder="{{ $cell && $cell['sec_per_unit'] !== null ? number_format($cell['sec_per_unit'] / 60, 1, ',', '.') : '—' }}"
                                       class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-sm text-right">
                            </td>
                            <td class="px-3 py-2 text-center bg-indigo-50/40">
                                <input type="hidden" name="a[{{ $p['id'] }}][{{ $dept['id'] }}][use]" value="0">
                                <input type="checkbox" name="a[{{ $p['id'] }}][{{ $dept['id'] }}][use]" value="1"
                                       @checked($cell && $cell['use_assumption']) class="w-4 h-4">
                            </td>
                            <td class="px-5 py-2 text-right whitespace-nowrap {{ $cell && $cell['is_bottleneck'] ? 'font-bold text-amber-700' : 'text-gray-700' }}">
                                {{ $cell && $cell['capacity_per_day'] !== null ? number_format($cell['capacity_per_day'], 1, ',', '.') : '—' }}
                                @if($cell && ($cell['slot_count'] ?? 0) > 0)
                                    <span class="block text-[10px] text-gray-400">{{ $cell['slot_count'] }} slot</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                    <tr class="font-bold text-gray-800">
                        <td class="px-5 py-2.5">Kapasitas produk</td>
                        <td colspan="3" class="px-3 py-2.5 text-right text-[11px] font-normal text-gray-500">
                            penentu: {{ $data['bottleneck_name'] ?? '—' }}
                        </td>
                        <td class="px-5 py-2.5 text-right">
                            {{ $data['capacity_per_day'] !== null ? number_format($data['capacity_per_day'], 1, ',', '.') : '—' }}
                            <span class="block text-[10px] font-semibold text-emerald-700">
                                {{ $data['capacity_per_month'] !== null ? number_format($data['capacity_per_month'], 0, ',', '.') : '—' }} /bulan
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </form>

        {{-- Rincian per mesin --}}
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="text-sm font-bold text-gray-800">Rincian per Mesin / Orang</h2>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Dari sampel yang sama dengan rata-rata di atas. Tanpa rincian ini, selisih antar mesin
                    tenggelam di dalam rata-rata &mdash; dan HPP produk diam-diam bergantung pada mesin mana
                    yang kebetulan kosong.
                </p>
            </div>
            @if(empty($perExecutor))
                <div class="px-5 py-6 text-xs text-gray-400">
                    Sampelnya belum mencatat siapa yang mengerjakan.
                </div>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-[10px] uppercase text-gray-500">
                        <tr>
                            <th class="px-5 py-2 text-left font-bold">Pelaku</th>
                            <th class="px-3 py-2 text-right font-bold">Sampel</th>
                            <th class="px-3 py-2 text-right font-bold">Rata<br>(mnt/unit)</th>
                            <th class="px-3 py-2 text-right font-bold">Median</th>
                            <th class="px-5 py-2 text-right font-bold">Rentang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($perExecutor as $e)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-2">
                                    <span class="font-semibold text-gray-800">{{ $e['executor'] }}</span>
                                    <span class="block text-[11px] text-gray-400">{{ $e['department'] }}</span>
                                </td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ $e['samples'] }}</td>
                                <td class="px-3 py-2 text-right font-bold text-gray-800">{{ number_format($e['sec_per_unit'] / 60, 1, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ number_format($e['median'] / 60, 1, ',', '.') }}</td>
                                <td class="px-5 py-2 text-right text-[11px] text-gray-500 whitespace-nowrap">
                                    {{ number_format($e['min'] / 60, 1, ',', '.') }} – {{ number_format($e['max'] / 60, 1, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-2.5 text-[11px] text-gray-400 border-t border-gray-100">
                    Rentang yang lebar dengan sampel sedikit berarti belum bisa dipakai membandingkan mesin.
                    Satu langkah yang dikerjakan dua pelaku dihitung penuh di keduanya &mdash; waktu mesinnya
                    memang selama itu, bukan separuhnya.
                </div>
            @endif
        </div>
    </div>

    {{-- Pemilih sampel --}}
    <form method="POST" action="{{ route('analisa.waktu-produksi.exclusions.save', $p['id']) }}"
          class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden mb-6">
        @csrf
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h2 class="text-sm font-bold text-gray-800">Sampel</h2>
                <p class="text-xs text-gray-500">Hilangkan centang OP yang datanya tidak wajar, lalu simpan. Pilihan ini tersimpan dan ikut dipakai perhitungan berikutnya.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="toggleAllSamples(true)" class="text-xs text-blue-600 hover:underline">Pilih semua</button>
                <span class="text-gray-300">|</span>
                <button type="button" onclick="toggleAllSamples(false)" class="text-xs text-blue-600 hover:underline">Batal pilih</button>
                <input type="text" name="reason" placeholder="Alasan (opsional)" maxlength="255"
                       class="border border-gray-200 rounded-xl px-3 py-1.5 text-xs w-48">
                <button type="submit"
                        class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded-xl text-xs font-semibold transition">
                    Simpan Pengecualian
                </button>
            </div>
        </div>

        @include('erp.analisa.waktu-produksi._sample-table', [
            'samples'     => $data['samples'],
            'departments' => $departments,
            'selectable'  => true,
        ])
    </form>

    {{-- OP tidak dipakai --}}
    @if(!empty($data['ineligible_samples']))
        <details class="bg-gray-50 border border-gray-200 rounded-2xl overflow-hidden mb-6">
            <summary class="px-5 py-3 cursor-pointer text-sm font-semibold text-gray-600">
                OP tidak dipakai ({{ count($data['ineligible_samples']) }}) — datanya tidak memenuhi syarat
            </summary>
            <div class="bg-white border-t border-gray-200">
                @include('erp.analisa.waktu-produksi._sample-table', [
                    'samples'     => $data['ineligible_samples'],
                    'departments' => $departments,
                    'selectable'  => false,
                ])
            </div>
        </details>
    @endif

</div>

<script>
function toggleAllSamples(state) {
    document.querySelectorAll('.sample-check').forEach(el => el.checked = state);
}
</script>
@endsection
