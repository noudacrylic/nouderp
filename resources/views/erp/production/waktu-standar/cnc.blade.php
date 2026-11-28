@extends('layouts.erp')

@php
    use App\Modules\Production\Services\PenilaianKinerjaService as PK;
    $pct = fn ($a, $b) => $b ? number_format($a / $b * 100, 0, ',', '.') . '%' : '—';
    $dtk = function ($d) {
        if ($d === null) return '—';
        $m = intdiv($d, 60); $s = $d % 60;
        return $m >= 60 ? PK::teksMenit($m) : ($m ? "$m mnt " . ($s ? "$s dtk" : '') : "$s dtk");
    };
    $labelKeadaan = ['potong' => ['Memotong', 'bg-emerald-100 text-emerald-800'], 'diam' => ['Diam', 'bg-amber-100 text-amber-800'],
                     'gerak' => ['Bergerak (tanpa potong)', 'bg-blue-100 text-blue-800'], 'putus' => ['Tak terjangkau', 'bg-gray-200 text-gray-600']];
@endphp

@section('content')
<div class="w-full max-w-6xl px-6 py-4 space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-800">Penilaian Operator CNC</h1>
        <p class="text-xs text-gray-500">Dari timer ERP dan sinyal mesin (dibaca server lewat LAN). Informasi untuk owner, belum masuk nilai.</p>
    </div>

    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Operator</label>
            <select name="orang" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5 min-w-[14rem]">
                @foreach($semua as $o)
                    <option value="{{ $o->id }}" @selected($operator && $o->id === $operator->id)>{{ $o->name }}{{ $o->is_active ? '' : ' (nonaktif)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bulan</label>
            <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5">
        </div>
        <noscript><button class="px-4 py-1.5 rounded-xl border border-gray-300">Tampilkan</button></noscript>
    </form>

    {{-- Alarm "mesin selesai" di perangkat ini (otomatis nyala di perangkat stasiun Intercom CNC) --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap items-center gap-3 text-sm">
        <div class="font-semibold text-gray-700">🔔 Alarm mesin selesai di perangkat ini:</div>
        <span id="alarmStatus" class="px-2 py-0.5 rounded font-bold text-xs bg-gray-100 text-gray-600">…</span>
        <button type="button" onclick="CncAlarm.atur(true); alarmTampil()" class="px-3 py-1 rounded-lg border border-emerald-300 text-emerald-700 text-xs font-semibold">Nyalakan</button>
        <button type="button" onclick="CncAlarm.atur(false); alarmTampil()" class="px-3 py-1 rounded-lg border border-gray-300 text-gray-600 text-xs font-semibold">Matikan</button>
        <button type="button" onclick="CncAlarm.tes()" class="px-3 py-1 rounded-lg border border-gray-300 text-gray-600 text-xs font-semibold">Tes bunyi</button>
        <span class="text-xs text-gray-400">Popup besar + bunyi saat mesin selesai memotong dan belum disentuh operator; hilang sendiri saat mesin mulai lagi.</span>
    </div>
    <script>
        function alarmTampil() {
            const el = document.getElementById('alarmStatus');
            if (!el || !window.CncAlarm) return;
            const on = CncAlarm.aktif();
            el.textContent = on ? 'NYALA' : 'MATI';
            el.className = 'px-2 py-0.5 rounded font-bold text-xs ' + (on ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600');
        }
        document.addEventListener('DOMContentLoaded', () => setTimeout(alarmTampil, 50));
    </script>

    {{-- ================= RAPOR OPERATOR (disetujui owner 28 Nov 2026) ================= --}}
    @if($nilai && $operator)
        @php
            $nl = $nilai['nilai'];
            $ct = $nilai['pencatatan'];
            $fmt = fn ($v, $n = 2) => $v === null ? '—' : number_format($v, $n, ',', '.');
            $tingkatUrut = collect($param['tingkat'])->values();
            $warnaTingkat = function ($t) use ($tingkatUrut) {
                $i = $t ? $tingkatUrut->search(fn ($x) => $x['label'] === $t['label']) : false;
                return ['bg-emerald-100 text-emerald-800', 'bg-blue-100 text-blue-800', 'bg-amber-100 text-amber-800', 'bg-red-100 text-red-800'][$i === false ? 9 : $i] ?? 'bg-gray-200 text-gray-800';
            };
            $kelompokK = [
                ['judul' => '2 poin — tanggap ≤ 3 menit', 'chip' => 'bg-emerald-50 border-emerald-200 text-emerald-800', 'k' => $nilai['kejadian']->where('status', 'dinilai')->where('poin', 2)],
                ['judul' => '1 poin — 3 sampai 8 menit', 'chip' => 'bg-gray-50 border-gray-200 text-gray-700', 'k' => $nilai['kejadian']->where('status', 'dinilai')->where('poin', 1)],
                ['judul' => '0 poin — lebih dari 8 menit', 'chip' => 'bg-red-50 border-red-200 text-red-800', 'k' => $nilai['kejadian']->where('status', 'dinilai')->where('poin', 0)],
                ['judul' => 'Ditahan — lebih dari 30 menit, menunggu putusan owner (Meja Owner)', 'chip' => 'bg-amber-50 border-amber-200 text-amber-800', 'k' => $nilai['kejadian']->where('status', 'cek')],
                ['judul' => 'Diabaikan owner — tidak ikut nilai', 'chip' => 'bg-slate-50 border-slate-200 text-slate-600', 'k' => $nilai['kejadian']->where('status', 'diabaikan')],
            ];
        @endphp
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-lg font-bold text-gray-800">Rapor {{ $operator->name }}</div>
                    <div class="text-sm text-gray-500">{{ $bulan->translatedFormat('F Y') }}
                        @if($berjalan)<span class="text-amber-700">· bulan berjalan, nilai masih bisa berubah</span>@endif
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500">Nilai bulan ini</div>
                    <div class="text-3xl font-black text-gray-800">{{ $fmt($nl['akhir']) }}</div>
                    @if($nl['tingkat'])<span class="px-2 py-0.5 rounded-lg text-xs font-bold {{ $warnaTingkat($nl['tingkat']) }}">{{ $nl['tingkat']['label'] }}</span>@endif
                </div>
            </div>
            <table class="w-full text-sm mt-4">
                <thead class="text-xs text-gray-400 border-b border-gray-100">
                    <tr><th class="text-left font-normal py-1">Komponen</th><th class="text-left font-normal">Ukuran</th><th class="text-right font-normal">Nilai</th></tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-semibold"><a href="#tanggap" class="hover:underline">B. Tanggap</a></td>
                        <td class="text-xs text-gray-500">Rata-rata poin {{ $nl['n'] }} kejadian · tengah {{ $dtk($nl['median']) }}
                            @if($nl['ditahan']) · <span class="text-amber-700">{{ $nl['ditahan'] }} ditahan</span>@endif</td>
                        <td class="text-right font-bold">{{ $fmt($nl['rata']) }}</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-semibold"><a href="#pencatatan" class="hover:underline">C. Pencatatan</a> <span class="text-xs font-normal text-gray-400">(syarat)</span></td>
                        <td class="text-xs text-gray-500">{{ $ct['persen'] === null ? '—' : number_format($ct['persen'] * 100, 1, ',', '.') . '%' }} waktu memotong tercatat di OP berjalan (syarat ≥ 95%)</td>
                        <td class="text-right font-bold {{ $ct['faktor'] < 1 ? 'text-red-600' : 'text-emerald-700' }}">{{ $ct['faktor'] < 1 ? '× ' . $fmt($ct['faktor']) : 'memenuhi' }}</td>
                    </tr>
                    <tr class="border-b border-gray-50 text-gray-400">
                        <td class="py-2 font-semibold">D. Mutu</td>
                        <td class="text-xs">
                            @php $mk = $mutuKomponen ?? []; @endphp
                            Laporan Assembling: kurang dari CNC {{ $mk['belum_dipotong'] ?? 0 }} · salah potong {{ $mk['salah_potong'] ?? 0 }}
                            <span class="text-gray-300">· rusak saat dirakit {{ $mk['rusak_rakit'] ?? 0 }} · bahan beli {{ $mk['bahan_beli'] ?? 0 }}</span>
                            — dikumpulkan dulu, belum dinilai
                        </td>
                        <td class="text-right">—</td>
                    </tr>
                    <tr class="text-gray-400">
                        <td class="py-2 font-semibold">E. Keterampilan</td><td class="text-xs">Mesin & merk yang dikuasai — informasi, belum dinilai</td><td class="text-right">—</td>
                    </tr>
                </tbody>
            </table>
            @if($nl['n'])
                <div class="mt-3 p-3 rounded-xl bg-gray-50 border border-gray-100 text-sm">
                    <b>Asal nilai {{ $fmt($nl['akhir']) }}:</b>
                    ({{ $nl['poin2'] }} × 2 + {{ $nl['poin1'] }} × 1 + {{ $nl['poin0'] }} × 0) ÷ {{ $nl['n'] }} kejadian = {{ $fmt($nl['rata']) }}
                    @if($ct['faktor'] < 1) × pencatatan {{ $fmt($ct['faktor']) }} = <b>{{ $fmt($nl['akhir']) }}</b>@endif
                </div>
            @endif
        </div>

        <div id="tanggap" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <h2 class="font-bold text-gray-800">B. Tanggap — mesin selesai memotong sampai memotong lagi</h2>
            <p class="text-xs text-gray-500 mt-1 max-w-4xl">
                ≤ 3 mnt = <b class="text-emerald-700">2 poin</b>, 3–8 mnt = <b>1 poin</b>, &gt; 8 mnt = <b class="text-red-600">0 poin</b>; &gt; 30 mnt ditahan untuk diputus owner.
                Hanya jam kerja, selama ada pekerjaan, tanpa Pending beralasan. <b>Aturan antre:</b> bila operator sedang melayani mesin lain yang selesai lebih dulu,
                waktu baru dihitung sejak mesin itu jalan lagi.
            </p>
            @forelse(collect($kelompokK)->filter(fn ($g) => $g['k']->isNotEmpty()) as $g)
                <details class="mt-3" @if($loop->first) open @endif>
                    <summary class="cursor-pointer list-none inline-flex items-center gap-2 px-2.5 py-1 rounded-lg border text-xs font-bold {{ $g['chip'] }}">
                        {{ $g['judul'] }} <span class="font-normal">· {{ $g['k']->count() }} kali</span>
                    </summary>
                    <div class="overflow-x-auto">
                    <table class="w-full text-xs mt-1.5">
                        <thead class="text-gray-400"><tr>
                            <th class="text-left font-normal py-1">Mesin</th><th class="text-left font-normal">Selesai memotong</th><th class="text-left font-normal">Memotong lagi</th>
                            <th class="text-right font-normal">Dihitung</th><th class="text-left font-normal pl-3">Keterangan</th>
                        </tr></thead>
                        <tbody>
                        @foreach($g['k'] as $k)
                            <tr class="border-t border-gray-100">
                                <td class="py-1 font-semibold">{{ $k['mesin'] }}</td>
                                <td class="whitespace-nowrap">{{ $k['selesai']->translatedFormat('D d/m H:i:s') }}</td>
                                <td class="whitespace-nowrap">{{ $k['mulai']->translatedFormat($k['mulai']->isSameDay($k['selesai']) ? 'H:i:s' : 'D d/m H:i:s') }}</td>
                                <td class="text-right font-bold">{{ $dtk($k['detik']) }}</td>
                                <td class="pl-3 text-gray-500">
                                    @if($k['antre'] > 0) antre {{ $dtk($k['antre']) }} (melayani mesin lain) @endif
                                    @if($k['putusan']) · diputus owner: {{ \App\Modules\Production\Services\PenilaianKinerjaService::PUTUSAN[$k['putusan']] ?? $k['putusan'] }} @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                </details>
            @empty
                <p class="text-sm text-gray-400 mt-2">Belum ada kejadian bersinyal di bulan ini.</p>
            @endforelse
        </div>

        <div id="pencatatan" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <h2 class="font-bold text-gray-800">C. Pencatatan — mesin memotong harus tercatat di langkah OP yang berjalan</h2>
            <p class="text-xs text-gray-500 mt-1">Bolong = mesin memotong tanpa langkah OP berjalan di mesin itu (lupa Mulai / Selesai ditekan terlalu cepat). Data ini dipakai HPP & waktu standar.</p>
            <table class="text-sm mt-2">
                @foreach($ct['mesin'] as $c)
                    <tr>
                        <td class="pr-6 py-0.5 font-semibold">{{ $c['nama'] }}</td>
                        <td class="pr-6">memotong {{ PK::teksMenit($c['potong'] / 60) }}</td>
                        <td class="pr-6">tercatat {{ $c['potong'] ? number_format($c['tercatat'] / $c['potong'] * 100, 1, ',', '.') . '%' : '—' }}</td>
                        <td class="{{ $c['potong'] - $c['tercatat'] > 600 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">bolong {{ PK::teksMenit(($c['potong'] - $c['tercatat']) / 60) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>

        <div class="text-xs font-bold text-gray-400 uppercase tracking-wide pt-2">Informasi (tidak dinilai)</div>
    @endif

    @if(!$hasil)
        <div class="bg-white rounded-2xl border border-gray-100 p-4 text-sm text-gray-500">Belum ada operator yang menaungi mesin (atur di Divisi → pelaksana induk).</div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="text-lg font-bold text-gray-800">{{ $operator->name }}</div>
                <div class="text-sm text-gray-500">{{ $bulan->translatedFormat('F Y') }}
                    @if($berjalan)<span class="text-amber-700">· bulan berjalan, s/d {{ $hasil['sampai']->format('d/m H:i') }}</span>@endif
                    · jam kerja tercatat {{ PK::teksMenit($hasil['jam_kerja']) }}
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2 max-w-4xl">
                <b>Diam saat ada antrean</b> = mesin tidak punya langkah berjalan (sesudah Selesai, belum Mulai berikutnya), di jam kerja
                {{ $operator->name }} (sudah scan masuk, bukan istirahat, lembur tidak dihitung), padahal ada OP CNC yang siap dikerjakan.
                Jeda di bawah {{ $hasil['ambang'] }} menit tidak didaftar. Mesin selesai memotong tapi hasil belum diambil (di dalam satu langkah)
                terlihat di <b>waktu tanggap</b> per mesin di bawah — dari sinyal mesin, hanya mesin yang IP-nya terpasang.
            </p>

            <div class="overflow-x-auto">
            <table class="w-full text-sm mt-3">
                <thead class="text-xs text-gray-400 border-b border-gray-100">
                    <tr>
                        <th class="text-left font-normal py-1">Mesin</th>
                        <th class="text-right font-normal">Bekerja</th>
                        <th class="text-right font-normal">Diam saat ada antrean</th>
                        <th class="text-right font-normal">Diam, antrean kosong</th>
                        <th class="text-right font-normal">Langkah selesai</th>
                        <th class="text-right font-normal pl-3">Tanggap (sinyal mesin)</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($hasil['mesin'] as $m)
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-semibold"><a href="#mesin-{{ $m['id'] }}" class="hover:underline">{{ $m['nama'] }}</a></td>
                        <td class="text-right">{{ PK::teksMenit($m['jalan']) }} <span class="text-xs text-gray-400">{{ $pct($m['jalan'], $hasil['jam_kerja']) }}</span></td>
                        <td class="text-right font-bold {{ $m['diam_antrean'] ? 'text-red-600' : 'text-gray-400' }}">
                            {{ $m['diam_antrean'] ? PK::teksMenit($m['diam_antrean']) : '—' }}
                            <span class="text-xs font-normal text-gray-400">{{ $pct($m['diam_antrean'], $hasil['jam_kerja']) }}</span>
                        </td>
                        <td class="text-right text-gray-500">{{ $m['diam_kosong'] ? PK::teksMenit($m['diam_kosong']) : '—' }}</td>
                        <td class="text-right">{{ $m['langkah'] }}</td>
                        <td class="text-right pl-3 text-xs">
                            @if($m['sinyal']['n'] ?? 0)
                                tengah <b>{{ $dtk($m['sinyal']['median']) }}</b> · {{ $m['sinyal']['n'] }}×
                            @elseif($m['sinyal'])
                                <span class="text-gray-400">belum ada</span>
                            @else
                                <span class="text-gray-300">tanpa sinyal</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>

        @foreach($hasil['mesin'] as $m)
            <div id="mesin-{{ $m['id'] }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-bold text-gray-800">{{ $m['nama'] }}</h2>
                    @if($t = $m['sinyal']['terakhir'] ?? null)
                        @php [$lbl, $warna] = $labelKeadaan[$t['keadaan']] ?? [$t['keadaan'], 'bg-gray-100']; @endphp
                        <span class="text-xs">
                            @if($t['berjalan'] && $t['dilihat']->gt(now()->subMinutes(3)))
                                <span class="px-2 py-0.5 rounded font-bold {{ $warna }}">{{ $lbl }}</span> sejak {{ $t['sejak']->format('d/m H:i') }}
                            @else
                                <span class="px-2 py-0.5 rounded bg-gray-200 text-gray-600 font-bold">Sinyal terakhir {{ $t['dilihat']->format('d/m H:i') }}</span>
                            @endif
                        </span>
                    @endif
                </div>

                {{-- Sinyal mesin: waktu tanggap tiap selesai memotong --}}
                @if($s = $m['sinyal'])
                    <div class="mt-2 p-3 rounded-xl bg-gray-50 border border-gray-100 text-xs">
                        @if($s['tercatat'])
                            <div class="text-sm">
                                <b>Waktu tanggap</b> (mesin selesai memotong → mulai memotong lagi, di jam kerja selama ada pekerjaan):
                                @if($s['n'])
                                    tengah <b>{{ $dtk($s['median']) }}</b>, rata-rata {{ $dtk($s['rata']) }}, dari {{ $s['n'] }} kali
                                    · memotong {{ PK::teksMenit($s['potong']) }} dari {{ PK::teksMenit($s['jam_tercatat']) }} jam kerja tercatat
                                @else
                                    belum ada selesai-mulai yang tercatat.
                                @endif
                            </div>
                            <div class="text-gray-400 mt-0.5">Sinyal tercatat sejak {{ $s['sejak']->format('d/m/Y H:i') }}.</div>
                            @if($s['tanggap'])
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-blue-700">Rincian {{ count($s['tanggap']) }} kali (terbaru di atas)</summary>
                                    <table class="w-full mt-1">
                                        <thead class="text-gray-400"><tr><th class="text-left font-normal">Selesai memotong</th><th class="text-left font-normal">Mulai lagi</th><th class="text-right font-normal">Tanggap</th><th></th></tr></thead>
                                        <tbody>
                                        @foreach(array_slice($s['tanggap'], 0, 100) as $g)
                                            <tr class="border-t border-gray-100">
                                                <td class="py-0.5">{{ $g['selesai']->translatedFormat('D d/m H:i:s') }}</td>
                                                <td>{{ $g['mulai']->translatedFormat($g['mulai']->isSameDay($g['selesai']) ? 'H:i:s' : 'D d/m H:i:s') }}</td>
                                                <td class="text-right font-bold {{ $g['detik'] > 600 ? 'text-red-600' : '' }}">{{ $dtk($g['detik']) }}</td>
                                                <td class="pl-2 text-gray-400">{{ $g['tanpa_data'] ? 'ada selang tanpa sinyal — tidak dihitung' : '' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </details>
                            @endif
                        @else
                            Belum ada sinyal di bulan ini.
                        @endif
                    </div>
                @endif

                <h3 class="font-semibold text-gray-700 mt-3 text-sm">Diam antar-OP saat ada antrean — {{ count($m['jeda']) }} kali</h3>
                @if(!$m['jeda'])
                    <p class="text-sm text-gray-400 mt-1">Tidak ada jeda ≥ {{ $hasil['ambang'] }} menit.</p>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-xs mt-2">
                        <thead class="text-gray-400">
                            <tr>
                                <th class="text-left font-normal py-1">Diam sejak</th>
                                <th class="text-left font-normal">Sampai</th>
                                <th class="text-right font-normal">Diam saat antrean</th>
                                <th class="text-left font-normal pl-3">Sesudah OP</th>
                                <th class="text-left font-normal">Lanjut OP</th>
                                <th class="text-left font-normal">OP menunggu saat itu</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($m['jeda'] as $j)
                            <tr class="border-t border-gray-100 align-top">
                                <td class="py-1.5 whitespace-nowrap">{{ $j['dari']->translatedFormat('D d/m H:i') }}</td>
                                <td class="whitespace-nowrap">{{ $j['sampai'] ? $j['sampai']->translatedFormat($j['sampai']->isSameDay($j['dari']) ? 'H:i' : 'D d/m H:i') : 'masih diam' }}</td>
                                <td class="text-right font-bold whitespace-nowrap">{{ PK::teksMenit($j['menit']) }}</td>
                                <td class="pl-3 whitespace-nowrap">
                                    @if($j['sebelum'])<a href="{{ route('production.orders.show', $j['sebelum']['op_id']) }}" class="text-blue-700 hover:underline">{{ $j['sebelum']['op'] }}</a>@else — @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if($j['sesudah'])<a href="{{ route('production.orders.show', $j['sesudah']['op_id']) }}" class="text-blue-700 hover:underline">{{ $j['sesudah']['op'] }}</a>@else — @endif
                                </td>
                                <td class="text-gray-500">
                                    @foreach($j['menunggu'] as $w)
                                        <a href="{{ route('production.orders.show', $w['op_id']) }}" class="hover:underline">{{ $w['op'] }}</a>@if(!$loop->last), @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
@endsection
