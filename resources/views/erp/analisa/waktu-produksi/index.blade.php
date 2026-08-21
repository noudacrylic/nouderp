@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Rata-rata Waktu Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Waktu tiap divisi dibagi jumlah siklus dulu, baru dirata-rata antar OP.
                Waktu per unit = rata-rata per siklus ÷ hasil per siklus (dari BOM).
            </p>
        </div>
        <a href="{{ route('analisa.waktu-produksi.export', request()->query()) }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            Export Excel
        </a>
    </div>

    @if($mergedCount > 0)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 mb-4 text-xs">
            <strong>{{ $mergedCount }} OP gabungan dikecualikan otomatis.</strong>
            Saat task digabung, jumlah siklus induk ditambah siklus anak padahal langkah yang sudah
            selesai sebelum penggabungan hanya memuat kerja untuk siklusnya sendiri — jadi waktu per
            siklusnya tidak bisa dipercaya. Centang "Sertakan OP gabungan" bila tetap ingin melihatnya.
        </div>
    @endif

    {{-- Filter --}}
    <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Dari Tanggal</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Sampai Tanggal</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Divisi</label>
            <select name="department_id" class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                <option value="">— Semua Divisi —</option>
                @foreach($deptOptions as $dept)
                    <option value="{{ $dept->id }}" @selected(request('department_id') == $dept->id)>{{ $dept->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Cari Produk</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama / SKU…"
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Tipe OP</label>
            <div class="flex flex-wrap gap-3 py-2">
                @foreach($typeOptions as $val => $label)
                    <label class="inline-flex items-center gap-1 text-xs text-gray-700">
                        <input type="checkbox" name="types[]" value="{{ $val }}"
                               @checked(in_array($val, $filters['types'], true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <label class="inline-flex items-center gap-1 text-xs text-gray-700 py-2">
                <input type="checkbox" name="include_merged" value="1" @checked($filters['include_merged'])>
                Sertakan OP gabungan <span class="text-gray-400">(tidak disarankan)</span>
            </label>
        </div>
        @include('erp._partials.per-page-select')
        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition">
            Tampilkan
        </button>
        <a href="{{ route('analisa.waktu-produksi.index') }}"
           class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">Reset</a>
    </form>

    <form method="POST" action="{{ route('analisa.waktu-produksi.assumptions.save') }}"
          class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        @csrf
        <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-sm font-bold text-gray-800">Waktu Terukur &amp; Asumsi</div>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Kolom <span class="text-indigo-700 font-semibold">asumsi</span> diisi dalam <strong>menit per unit</strong> —
                    untuk menambal produk yang sampelnya belum layak, atau mengandaikan perubahan cara kerja
                    ("kalau assembling dipercepat jadi 2 jam"). Angka terukur tidak pernah ditimpa.
                </p>
            </div>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">
                Simpan Asumsi Waktu
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:{{ 720 + count($departments) * 300 }}px">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Produk</th>
                        <th class="px-3 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Sampel</th>
                        <th class="px-3 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Hasil/Siklus</th>
                        @foreach($departments as $dept)
                            <th colspan="3" class="px-3 py-3 text-center font-black text-gray-400 text-[10px] uppercase tracking-widest whitespace-nowrap border-l border-gray-200">
                                {{ $dept['name'] }}
                                @if($dept['type'] === 'non_produksi')
                                    <span class="block text-xs font-semibold text-gray-500 normal-case tracking-normal">non-produksi</span>
                                @endif
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right font-black text-gray-500 text-[10px] uppercase tracking-widest bg-gray-100 border-l border-gray-200">Total</th>
                        <th class="px-3 py-3 text-right font-black text-gray-500 text-[10px] uppercase tracking-widest bg-emerald-50">Kapasitas<br>/hari</th>
                        <th class="px-4 py-3 text-right font-black text-gray-500 text-[10px] uppercase tracking-widest bg-emerald-50">Kapasitas<br>/bulan</th>
                    </tr>
                    <tr class="bg-gray-50 border-b border-gray-100 text-[10px]">
                        <th colspan="3"></th>
                        @foreach($departments as $dept)
                            <th class="px-2 py-1 text-right font-semibold text-gray-400 border-l border-gray-200">terukur</th>
                            <th class="px-2 py-1 text-right font-semibold text-indigo-500 bg-indigo-50/60">asumsi<br>(mnt/unit)</th>
                            <th class="px-2 py-1 text-center font-semibold text-indigo-500 bg-indigo-50/60">pakai</th>
                        @endforeach
                        <th class="border-l border-gray-200"></th>
                        <th colspan="2" class="px-3 py-1 text-right font-semibold text-gray-400 bg-emerald-50">penentu</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($rows as $row)
                        @php $detailUrl = route('analisa.waktu-produksi.show', array_merge([$row['product']['id']], request()->query())); @endphp
                        <tr class="hover:bg-blue-50/40 cursor-pointer {{ $row['has_assumption'] ? 'bg-indigo-50/30' : '' }}"
                            onclick="window.location='{{ $detailUrl }}'">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-gray-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                            </td>
                            <td class="px-3 py-3 text-right text-gray-700">
                                {{ $row['included_count'] }}
                                @if($row['excluded_count'] > 0)
                                    <span class="block text-xs font-semibold text-red-600">{{ $row['excluded_count'] }} dikecualikan</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right">
                                @if($row['qty_per_cycle'] !== null)
                                    <span class="font-semibold text-gray-800">{{ rtrim(rtrim(number_format($row['qty_per_cycle'], 2, ',', '.'), '0'), ',') }}</span>
                                    <span class="block text-xs font-mono font-semibold text-blue-600">{{ $row['qty_per_cycle_source']['bom_number'] ?? '' }}</span>
                                    @if(!empty($row['qty_per_cycle_conflict']))
                                        <span class="block text-xs font-semibold text-red-600">⚠ BOM berbeda</span>
                                    @endif
                                @else
                                    <span class="text-gray-300">—</span>
                                    <span class="block text-xs font-semibold text-red-600">tanpa BOM</span>
                                @endif
                            </td>
                            @foreach($departments as $dept)
                                @php
                                    $cell = $row['per_division'][$dept['id']] ?? null;
                                    $pid  = $row['product']['id'];
                                @endphp
                                <td class="px-3 py-3 text-right whitespace-nowrap border-l border-gray-100">
                                    @if($cell && $cell['sec_per_unit'] !== null)
                                        <span class="font-semibold text-gray-800">{{ dur_hms($cell['sec_per_cycle']) }}</span>
                                        <span class="block text-xs font-semibold text-blue-600">{{ dur_hms($cell['sec_per_unit']) }}/unit</span>
                                        @if($cell['capacity_per_day'] !== null)
                                            <span class="block text-[10px] {{ $cell['is_bottleneck'] ? 'font-bold text-amber-700' : 'text-gray-400' }}">
                                                {{ number_format($cell['capacity_per_day'], 1, ',', '.') }} pcs/hari
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-2 py-3 text-right bg-indigo-50/40" onclick="event.stopPropagation()">
                                    <input type="number" step="0.01" min="0"
                                           name="a[{{ $pid }}][{{ $dept['id'] }}][minutes]"
                                           value="{{ $cell && $cell['assumed'] !== null ? round($cell['assumed'] / 60, 2) : '' }}"
                                           placeholder="{{ $cell && $cell['sec_per_unit'] !== null ? number_format($cell['sec_per_unit'] / 60, 1, ',', '.') : '—' }}"
                                           class="w-24 border border-gray-200 rounded-lg px-2 py-1 text-sm text-right">
                                </td>
                                <td class="px-2 py-3 text-center bg-indigo-50/40" onclick="event.stopPropagation()">
                                    <input type="hidden" name="a[{{ $pid }}][{{ $dept['id'] }}][use]" value="0">
                                    <input type="checkbox" name="a[{{ $pid }}][{{ $dept['id'] }}][use]" value="1"
                                           @checked($cell && $cell['use_assumption']) class="w-4 h-4">
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right bg-gray-50 whitespace-nowrap border-l border-gray-200">
                                <span class="font-black text-gray-800">{{ dur_hms($row['total']['sec_per_cycle']) }}</span>
                                <span class="block text-xs font-bold text-blue-700">{{ dur_hms($row['total']['sec_per_unit']) }}/unit</span>
                                @if($row['has_assumption'])
                                    <span class="block text-[10px] font-bold text-indigo-600">efektif {{ dur_hms($row['sec_per_unit_effective']) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right bg-emerald-50/60 whitespace-nowrap">
                                <span class="font-black text-gray-800">{{ $row['capacity_per_day'] !== null ? number_format($row['capacity_per_day'], 1, ',', '.') : '—' }}</span>
                                @if($row['bottleneck_name'])
                                    <span class="block text-[10px] font-semibold text-amber-700">{{ $row['bottleneck_name'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right bg-emerald-50/60 font-bold text-emerald-700">
                                {{ $row['capacity_per_month'] !== null ? number_format($row['capacity_per_month'], 0, ',', '.') : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 6 + count($departments) * 3 }}" class="px-5 py-12 text-center text-gray-400">
                                Belum ada OP dengan data timer yang cocok dengan filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>

    <div class="mt-3 flex items-center justify-between">
        <div class="text-xs text-gray-500">
            Angka <span class="font-semibold text-gray-700">hitam</span> = waktu per siklus,
            <span class="font-semibold text-blue-600">biru</span> = waktu per unit,
            <span class="font-semibold text-emerald-700">hijau</span> = kapasitas sehari kalau seluruh divisi
            diarahkan ke produk itu (divisi terlambat yang menentukan).
            Klik baris untuk sampel &amp; rincian per mesin.
        </div>
        {{ $rows->links() }}
    </div>

</div>
@endsection
