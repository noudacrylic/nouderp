@extends('layouts.erp')

@php
    use App\Modules\Production\Services\PenilaianKinerjaService as PK;
    $fmt = fn ($v, $n = 2) => $v === null ? '—' : number_format($v, $n, ',', '.');
    $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 0, ',', '.') . '%';
    $tingkatUrut = collect($param['tingkat'])->values();
    $warnaTingkat = function ($t) use ($tingkatUrut) {
        $i = $t ? $tingkatUrut->search(fn ($x) => $x['label'] === $t['label']) : false;
        return ['bg-emerald-100 text-emerald-800', 'bg-blue-100 text-blue-800', 'bg-amber-100 text-amber-800', 'bg-red-100 text-red-800', 'bg-purple-100 text-purple-800', 'bg-gray-200 text-gray-800'][$i === false ? 5 : $i] ?? 'bg-gray-200 text-gray-800';
    };
    $chipPoin = ['emerald' => 'bg-emerald-50 border-emerald-200 text-emerald-800', 'gray' => 'bg-gray-50 border-gray-200 text-gray-700',
                 'red' => 'bg-red-50 border-red-200 text-red-800', 'amber' => 'bg-amber-50 border-amber-200 text-amber-800', 'slate' => 'bg-slate-50 border-slate-200 text-slate-600'];
    $n = $nilai['n'] ?? 0;
    $pitaPct = round($param['pita'] * 100);
@endphp

@section('content')
<div class="w-full max-w-6xl px-6 py-4 space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-800">Penilaian Karyawan — {{ $divisi->firstWhere('id', $deptId)?->name }}</h1>
        <p class="text-xs text-gray-500">Rapor satu orang per bulan. Fokus: nilainya benar dan bisa ditelusuri asalnya. Dampak ke gaji dibahas belakangan.</p>
    </div>

    {{-- ================= A. PILIH ORANG & RINGKASAN ================= --}}
    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap items-end gap-3 text-sm">
        <input type="hidden" name="divisi" value="{{ $deptId }}">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Karyawan</label>
            <select name="orang" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5 min-w-[14rem]">
                @foreach($semuaOrang as $o)
                    <option value="{{ $o->id }}" @selected($o->id === $orang->id)>{{ $o->name }}{{ $o->is_active ? '' : ' (nonaktif)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bulan</label>
            <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5">
        </div>
        <noscript><button class="px-4 py-1.5 rounded-xl border border-gray-300">Tampilkan</button></noscript>
    </form>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="text-lg font-bold text-gray-800">
                    {{ $orang->name }}
                    @if($percobaan)<span class="ml-1 align-middle text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 font-semibold">masa percobaan</span>@endif
                </div>
                <div class="text-sm text-gray-500">{{ $bulan->translatedFormat('F Y') }}
                    @if($berjalan)<span class="text-amber-700">· bulan berjalan, nilai masih bisa berubah</span>@endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">Nilai bulan ini</div>
                <div class="text-3xl font-black text-gray-800">{{ $fmt($nilai['rata'] ?? null) }}</div>
                @if($nilai['tingkat'] ?? null)
                    <span class="px-2 py-0.5 rounded-lg text-xs font-bold {{ $warnaTingkat($nilai['tingkat']) }}">{{ $nilai['tingkat']['label'] }}</span>
                @endif
                @if($n && $n < $param['min_op'])
                    <div class="text-[11px] text-amber-700 mt-1">Baru {{ $n }} OP (minimal {{ $param['min_op'] }}) — belum bisa disimpulkan</div>
                @endif
            </div>
        </div>

        {{-- Komponen nilai --}}
        <table class="w-full text-sm mt-4">
            <thead class="text-xs text-gray-400 border-b border-gray-100">
                <tr><th class="text-left font-normal py-1">Komponen</th><th class="text-left font-normal">Ukuran</th><th class="text-right font-normal">Nilai</th><th class="text-right font-normal">Bobot</th></tr>
            </thead>
            <tbody>
                <tr class="border-b border-gray-50">
                    <td class="py-2 font-semibold"><a href="#performa" class="hover:underline">B. Performa (kecepatan)</a></td>
                    <td class="text-gray-500 text-xs">Rata-rata poin {{ $n }} OP · efisiensi {{ $pct($nilai['efisiensi'] ?? null) }}</td>
                    <td class="text-right font-bold">{{ $fmt($nilai['rata'] ?? null) }}</td>
                    <td class="text-right text-gray-500">100%</td>
                </tr>
                <tr class="border-b border-gray-50 text-gray-400">
                    <td class="py-2 font-semibold">C. Hasil kerja (mutu)</td>
                    <td class="text-xs">QC / print rusak / perbaikan — belum diukur</td>
                    <td class="text-right">—</td><td class="text-right">—</td>
                </tr>
                <tr>
                    <td class="py-2 font-semibold"><a href="#keterampilan" class="hover:underline">D. Keterampilan</a></td>
                    <td class="text-gray-500 text-xs">
                        Dikuasai: {{ collect($keterampilan['kelompok'])->where('status', 'dikuasai')->pluck('label')->implode(', ') ?: 'belum ada' }}
                    </td>
                    <td class="text-right font-bold">{{ $keterampilan['dikuasai'] }}/{{ $keterampilan['total'] }}</td>
                    <td class="text-right text-gray-400 text-xs">belum masuk nilai</td>
                </tr>
            </tbody>
        </table>

        {{-- Tren --}}
        <div class="flex flex-wrap gap-2 mt-4 text-xs">
            @foreach($tren as $t)
                <div class="border border-gray-200 rounded-lg px-3 py-1.5 {{ $loop->last ? 'bg-gray-50' : '' }}">
                    <div class="text-gray-400">{{ $t['label'] }}</div>
                    <b>{{ $fmt($t['rata']) }}</b>
                    @if($t['tingkat'])<span class="ml-1 px-1 rounded {{ $warnaTingkat($t['tingkat']) }}">{{ $t['tingkat']['label'] }}</span>@endif
                    <span class="text-gray-400">· {{ $t['n'] }} OP</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ================= B. PERFORMA ================= --}}
    <div id="performa" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <h2 class="font-bold text-gray-800">B. Performa — kecepatan per pekerjaan</h2>
        <p class="text-xs text-gray-500 mt-1 max-w-4xl">
            Tiap OP punya <b>target</b> = Σ (pcs × waktu standar). Timer ≤ {{ 100 - $pitaPct }}% target = <b class="text-emerald-700">2 poin</b>,
            {{ 100 - $pitaPct }}–{{ 100 + $pitaPct }}% = <b>1 poin</b>, di atas {{ 100 + $pitaPct }}% = <b class="text-red-600">0 poin</b>.
            Waktu Pending beralasan tidak dihitung di timer.
        </p>

        @if($n)
            <div class="mt-3 p-3 rounded-xl bg-gray-50 border border-gray-100 text-sm">
                <b>Asal nilai {{ $fmt($nilai['rata']) }}:</b>
                @if($param['bobot'] === 'jatah')
                    rata-rata poin {{ $n }} OP ditimbang besar target (OP besar lebih berpengaruh).
                @else
                    ({{ $nilai['poin2'] }} × 2 + {{ $nilai['poin1'] }} × 1 + {{ $nilai['poin0'] }} × 0) ÷ {{ $n }} OP
                    = {{ $nilai['poin2'] * 2 + $nilai['poin1'] }} ÷ {{ $n }} = <b>{{ $fmt($nilai['rata']) }}</b>
                @endif
                <span class="text-gray-500">· total target {{ PK::teksMenit($nilai['jatah']) }} vs timer {{ PK::teksMenit($nilai['timer']) }}</span>
            </div>
        @else
            <p class="mt-3 text-sm text-gray-400">Belum ada OP yang dinilai untuk {{ $orang->name }} di bulan ini.</p>
        @endif

        @foreach($kelompok as $k => $g)
            @continue($g['op']->isEmpty())
            <div class="mt-4">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg border text-xs font-bold {{ $chipPoin[$g['warna']] }}">
                    {{ $g['judul'] }} <span class="font-normal">· {{ $g['op']->count() }} OP</span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-xs mt-1.5">
                    <thead class="text-gray-400">
                        <tr>
                            <th class="text-left font-normal py-1">OP</th>
                            <th class="text-left font-normal">Selesai</th>
                            <th class="text-left font-normal">Isi (pcs × standar mnt/pcs)</th>
                            <th class="text-right font-normal">Target</th>
                            <th class="text-right font-normal">Timer</th>
                            <th class="text-right font-normal">Timer/target</th>
                            <th class="text-left font-normal pl-3">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($g['op']->sortByDesc(fn ($x) => $x['selesai']?->timestamp ?? 0) as $o)
                        @php
                            $rekan = collect($o['pelaksana'])->where('id', '!=', $orang->id)->pluck('name');
                            $ket = [];
                            if ($o['putusan']) $ket[] = ['wajar' => 'Diputus owner: wajar', 'abaikan' => 'Diputus owner: diabaikan', 'curang' => 'Diputus owner: tidak wajar (0 poin)'][$o['putusan']] ?? $o['putusan'];
                            if ($k === 'cek') $ket[] = 'Perlu dicek: ' . ($o['alasan'] ?? 'timer janggal');
                            if (isset($dikoreksi[$o['step_id']])) $ket[] = '⏱ timer dikoreksi owner';
                            if ($o['merk']) $ket[] = '🧱 ' . collect($o['merk'])->map(fn ($m) => $m . (($o['mj'][$m] ?? null) ? ' ' . $o['mj'][$m] : ''))->implode(' + ')
                                . ($o['faktor'] != 1 ? ' (target ×' . $fmt($o['faktor']) . ')' : '');
                            if ($rekan->isNotEmpty()) $ket[] = '👥 bersama ' . $rekan->implode(', ');
                        @endphp
                        <tr class="border-t border-gray-100 align-top">
                            <td class="py-1.5 whitespace-nowrap"><a href="{{ route('production.orders.show', $o['op_id']) }}" class="text-blue-700 hover:underline">{{ $o['op'] }}</a></td>
                            <td class="whitespace-nowrap">{{ $o['selesai']?->format('d/m H:i') }}</td>
                            <td>{{ collect($o['rincian'])->map(fn ($x) => $x['sku'] . ' ' . rtrim(rtrim(number_format($x['qty'], 2, ',', ''), '0'), ',') . ' × ' . number_format($x['standar'], 2, ',', '.'))->implode(', ') }}</td>
                            <td class="text-right whitespace-nowrap">{{ $o['jatah'] !== null ? PK::teksMenit($o['jatah']) : '—' }}</td>
                            <td class="text-right whitespace-nowrap">{{ PK::teksMenit($o['menit']) }}</td>
                            <td class="text-right font-bold whitespace-nowrap">{{ $pct($o['rasio']) }}</td>
                            <td class="pl-3 text-gray-500">{{ implode(' · ', $ket) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endforeach

        @if($tanpa->isNotEmpty())
            <div class="mt-4">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg border text-xs font-bold bg-white border-gray-200 text-gray-500">
                    Tidak dinilai — produk belum punya waktu standar <span class="font-normal">· {{ $tanpa->count() }} OP</span>
                </div>
                <div class="mt-1.5 flex flex-wrap gap-1.5 text-xs">
                    @foreach($tanpa->sortByDesc(fn ($x) => $x['selesai']?->timestamp ?? 0) as $o)
                        <a href="{{ route('production.orders.show', $o['op_id']) }}" class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 hover:bg-gray-200">
                            {{ $o['op'] }} <span class="text-gray-400">({{ implode(', ', $o['kurang']) }})</span>
                        </a>
                    @endforeach
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Isi standarnya di tab Waktu Standar supaya OP ini ikut dinilai.</p>
            </div>
        @endif
    </div>

    {{-- ================= C. HASIL KERJA ================= --}}
    <div class="bg-white rounded-2xl border border-dashed border-gray-200 p-4 text-sm text-gray-400">
        <div class="font-bold text-gray-500">C. Hasil kerja (mutu)</div>
        Belum diukur. Rencana: hasil QC, print rusak & perbaikan yang berasal dari pekerjaan orang ini — supaya cepat tidak asal cepat.
    </div>

    {{-- ================= D. KETERAMPILAN ================= --}}
    <div id="keterampilan" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <h2 class="font-bold text-gray-800">D. Keterampilan — {{ $keterampilan['dikuasai'] }} dari {{ $keterampilan['total'] }} kelompok dikuasai</h2>
        <p class="text-xs text-gray-500 mt-1 max-w-4xl">
            <b>Dikuasai</b> = minimal {{ \App\Modules\Production\Services\KeterampilanService::MIN_OP_BAIK }} OP dengan waktu baik
            (timer ≤ {{ 100 + $pitaPct }}% target, 1–2 poin) <b>dan</b> minimal separuh dari semua OP di kelompok itu baik.
            Tidak hilang karena lama tidak dikerjakan, tapi kembali "sedang belajar" bila belakangan lebih banyak lambat.
            Dihitung dari seluruh riwayat sejak waktu standar berlaku sampai akhir {{ $bulan->translatedFormat('F Y') }}. Produk AF (cuma potong) tidak dihitung.
        </p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
            @foreach($keterampilan['kelompok'] as $kunci => $k)
                @php
                    $gaya = ['dikuasai' => 'border-emerald-200 bg-emerald-50', 'belajar' => 'border-amber-200 bg-amber-50', 'belum' => 'border-gray-200 bg-gray-50'][$k['status']];
                    $label = ['dikuasai' => '✓ Dikuasai', 'belajar' => 'Sedang belajar', 'belum' => 'Belum pernah'][$k['status']];
                    $warnaLabel = ['dikuasai' => 'text-emerald-700', 'belajar' => 'text-amber-700', 'belum' => 'text-gray-400'][$k['status']];
                @endphp
                <details class="rounded-xl border p-3 text-sm {{ $gaya }}">
                    <summary class="cursor-pointer list-none">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-bold text-gray-800">{{ $k['label'] }}</span>
                            <span class="text-xs font-bold {{ $warnaLabel }}">{{ $label }}</span>
                        </div>
                        <div class="text-xs text-gray-600 mt-1">
                            @if($k['n'])
                                {{ $k['baik'] }} dari {{ $k['n'] }} OP waktu baik ({{ $pct($k['baik'] / $k['n']) }})
                                @if($k['dikuasai_sejak']) · dikuasai sejak {{ $k['dikuasai_sejak']->format('d/m/Y') }} @endif
                                <div class="text-gray-400">Terakhir dikerjakan {{ $k['terakhir']?->format('d/m/Y') }}</div>
                                @if($k['status'] === 'belajar')
                                    @php $kurang = max(0, \App\Modules\Production\Services\KeterampilanService::MIN_OP_BAIK - $k['baik']); @endphp
                                    <div class="text-amber-700">{{ $kurang ? "Butuh $kurang OP baik lagi" : 'OP baik sudah cukup, porsinya masih di bawah separuh' }}</div>
                                @endif
                            @else
                                Belum pernah mengerjakan produk kelompok ini.
                            @endif
                        </div>
                    </summary>
                    @if($k['op'])
                        <table class="w-full text-xs mt-2 border-t border-black/5">
                            @foreach($k['op'] as $o)
                                <tr>
                                    <td class="py-0.5"><a href="{{ route('production.orders.show', $o['op_id']) }}" class="text-blue-700 hover:underline">{{ $o['op'] }}</a></td>
                                    <td class="text-gray-500">{{ $o['selesai']?->format('d/m') }}</td>
                                    <td class="text-gray-500 truncate max-w-[8rem]" title="{{ $o['isi'] }}">{{ $o['isi'] }}</td>
                                    <td class="text-right">{{ $pct($o['rasio']) }}</td>
                                    <td class="text-right font-bold {{ $o['poin'] >= 1 ? 'text-emerald-700' : 'text-red-600' }}">{{ $o['poin'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </details>
            @endforeach
        </div>
    </div>
</div>
@endsection
