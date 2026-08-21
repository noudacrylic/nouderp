@extends('layouts.erp')

@php
    use Carbon\Carbon;

    $win      = $data['window'];
    $winStart = $win['start'];
    $winEnd   = $win['end'];
    $winSec   = max(1, $winStart->diffInSeconds($winEnd));

    // Posisi horizontal sebuah waktu dalam jendela, dalam persen.
    $pos = fn (Carbon $t) => max(0, min(100, $winStart->diffInSeconds($t) / $winSec * 100));

    // Tengah malam penutup ditulis 24:00, bukan 00:00 — supaya tidak terbaca mundur ke pagi.
    $labelJam = fn (Carbon $t) => $t->format('H:i') === '00:00' && $t->gt($winStart) ? '24:00' : $t->format('H:i');

    $jam = function (int $sec) {
        if ($sec <= 0) return '0m';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        return $h > 0 ? ($m > 0 ? "{$h}j {$m}m" : "{$h}j") : "{$m}m";
    };

    // Warna blok dikunci ke nomor OP, jadi satu OP berwarna sama di semua baris & divisi.
    $palette = ['#2563eb','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#c026d3','#65a30d','#e11d48','#4f46e5'];
    $warna   = fn (?string $key) => $palette[abs(crc32((string) $key)) % count($palette)];

    $hours = [];
    for ($t = $winStart->copy(); $t->lt($winEnd); $t->addHour()) $hours[] = $t->copy();
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Kalender Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Satu hari penuh, per mesin dan per operator. Ruang kosong di dalam jam kerja = lubang kapasitas.
            </p>
        </div>

        <form method="GET" class="flex items-end gap-2">
            @php
                $prev = $data['date']->copy()->subDay()->toDateString();
                $next = $data['date']->copy()->addDay()->toDateString();
            @endphp
            <a href="{{ route('analisa.kalender.index', ['date' => $prev]) }}"
               class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-3 py-2 rounded-xl text-sm font-semibold">←</a>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Tanggal</label>
                <input type="date" name="date" value="{{ $data['date']->toDateString() }}"
                       onchange="this.form.submit()"
                       class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
            </div>
            <a href="{{ route('analisa.kalender.index', ['date' => $next]) }}"
               class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-3 py-2 rounded-xl text-sm font-semibold">→</a>
            @if($lastActive && !$lastActive->isSameDay($data['date']))
                <a href="{{ route('analisa.kalender.index', ['date' => $lastActive->toDateString()]) }}"
                   class="border border-blue-200 text-blue-700 hover:bg-blue-50 px-3 py-2 rounded-xl text-sm font-semibold">
                    Hari produksi terakhir
                </a>
            @endif
        </form>
    </div>

    {{-- Ringkasan hari --}}
    @php $tot = $data['totals']; @endphp
    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm px-5 py-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-sm font-bold text-gray-800">
                    {{ $data['date']->translatedFormat('l, d F Y') }}
                </div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    {{ $tot['slot_count'] }} slot kapasitas ·
                    jendela {{ $labelJam($winStart) }}–{{ $labelJam($winEnd) }}
                </div>
            </div>
            <div class="flex flex-wrap gap-6 text-right">
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Kapasitas</div>
                    <div class="text-lg font-black text-gray-800">{{ $jam($tot['capacity_seconds']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Terpakai</div>
                    <div class="text-lg font-black text-emerald-600">{{ $jam($tot['busy_seconds']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Lubang</div>
                    <div class="text-lg font-black text-amber-600">{{ $jam($tot['gap_seconds']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Henti mesin</div>
                    <div class="text-lg font-black text-slate-600">{{ $jam($tot['downtime_seconds']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Di luar jam</div>
                    <div class="text-lg font-black text-purple-600">{{ $jam($tot['outside_seconds']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-gray-400 uppercase">Utilisasi</div>
                    <div class="text-lg font-black {{ ($tot['utilization'] ?? 0) > 100 ? 'text-red-600' : 'text-blue-600' }}">
                        {{ $tot['utilization'] !== null ? number_format($tot['utilization'], 1, ',', '.') . '%' : '—' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($data['holiday'])
        <div class="bg-rose-50 border border-rose-200 text-rose-900 rounded-2xl px-5 py-3 mb-4 text-xs leading-relaxed">
            <strong>Libur nasional: {{ $data['holiday']->nama }}{{ $data['holiday']->is_cuti_bersama ? ' (cuti bersama)' : '' }}.</strong>
            Pabrik tidak dijadwalkan buka, jadi kapasitas hari ini nol dan tidak ada lubang yang
            dihitung. Kalau tetap ada yang berjalan, waktunya masuk kolom "di luar jam".
        </div>
    @endif

    @if($tot['orphan_steps'] > 0)
        <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-2xl px-5 py-3 mb-4 text-xs leading-relaxed">
            <strong>{{ $tot['orphan_steps'] }} langkah ({{ $jam($tot['orphan_seconds']) }}) tercatat atas nama operator saja, tanpa mesin.</strong>
            Waktunya tidak masuk hitungan kapasitas, jadi baris mesin bisa terlihat menganggur padahal
            kenyataannya jalan — mesinnya hanya lupa dicentang saat langkah dimulai. Lubang di hari ini
            belum tentu lubang beneran sebelum ini dibereskan.
        </div>
    @endif

    @if(!$data['has_data'])
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm px-6 py-10 text-center">
            <div class="text-sm font-bold text-gray-700">Tidak ada aktivitas produksi tercatat pada tanggal ini.</div>
            <p class="text-xs text-gray-500 mt-1">
                Bisa jadi hari libur, bisa jadi timer memang tidak dijalankan hari itu.
            </p>
        </div>
    @endif

    {{-- Legenda --}}
    <div class="flex flex-wrap items-center gap-4 text-[11px] text-gray-500 mb-3">
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm" style="background:#2563eb"></span> Timer berjalan
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm bg-gray-100 border border-gray-200"></span> Istirahat
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm" style="background:repeating-linear-gradient(45deg,#fde68a,#fde68a 3px,#fef3c7 3px,#fef3c7 6px)"></span> Lubang (&ge; 5 menit)
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm" style="background:repeating-linear-gradient(45deg,#cbd5e1,#cbd5e1 3px,#e2e8f0 3px,#e2e8f0 6px)"></span> Henti mesin
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm border-2 border-dashed border-purple-400"></span> Di luar jam kerja
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="w-4 h-3 rounded-sm border-2 border-red-500"></span> Timer belum ditutup
        </span>
        <span class="ml-auto">Arahkan kursor ke blok untuk melihat rinciannya.</span>
    </div>

    @foreach($data['departments'] as $dept)
        @continue(empty($dept['rows']))
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm mb-4 overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm font-black text-gray-700 uppercase tracking-wide">{{ $dept['name'] }}</div>
                <div class="text-[11px] text-gray-500">
                    {{ $dept['slot_count'] }} slot ·
                    kapasitas <strong>{{ $jam($dept['capacity_seconds']) }}</strong> ·
                    terpakai <strong class="text-emerald-600">{{ $jam($dept['busy_seconds']) }}</strong> ·
                    lubang <strong class="text-amber-600">{{ $jam($dept['gap_seconds']) }}</strong>
                    @if($dept['outside_seconds'] > 0)
                        · di luar jam <strong class="text-purple-600">{{ $jam($dept['outside_seconds']) }}</strong>
                    @endif
                    @if($dept['downtime_seconds'] > 0)
                        · henti mesin <strong class="text-slate-600">{{ $jam($dept['downtime_seconds']) }}</strong>
                    @endif
                    @if($dept['orphan_seconds'] > 0)
                        · <strong class="text-amber-700" title="Tercatat atas nama operator tanpa mesin — tidak masuk kapasitas">tanpa mesin {{ $jam($dept['orphan_seconds']) }}</strong>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto">
                <div style="min-width:900px">
                    {{-- Kepala jam --}}
                    <div class="flex border-b border-gray-100 bg-white sticky top-0 z-10">
                        <div class="w-44 shrink-0 px-4 py-2 text-[11px] font-bold text-gray-400 uppercase">Eksekutor</div>
                        <div class="flex-1 relative h-8">
                            @foreach($hours as $h)
                                <div class="absolute top-0 bottom-0 border-l border-gray-100" style="left:{{ $pos($h) }}%">
                                    <span class="absolute top-1.5 left-1 text-[10px] font-semibold text-gray-400">{{ $h->format('H:i') }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="w-56 shrink-0 px-3 py-2 text-[11px] font-bold text-gray-400 uppercase text-right">Terpakai / Lubang</div>
                    </div>

                    @foreach($dept['rows'] as $row)
                        @php $shift = $row['shift']; @endphp
                        <div class="flex border-b border-gray-50 last:border-b-0 hover:bg-blue-50/30">
                            <div class="w-44 shrink-0 px-4 py-2.5">
                                <div class="text-sm font-semibold text-gray-800 flex items-center gap-1.5">
                                    <span>{{ $row['is_machine'] ? '⚙' : '👤' }}</span>
                                    {{ $row['name'] }}
                                </div>
                                <div class="text-[10px] text-gray-400">
                                    @if(!$row['counts_as_slot'])
                                        tidak dihitung slot
                                    @elseif($shift['is_off'])
                                        libur
                                    @else
                                        {{ $shift['start']->format('H:i') }}–{{ $shift['end']->format('H:i') }}
                                    @endif
                                </div>
                                @if($row['absence'])
                                    <div class="mt-0.5 inline-block bg-rose-50 border border-rose-200 text-rose-700 rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                         @if($row['absence']['note']) title="{{ $row['absence']['note'] }}" @endif>
                                        {{ $row['absence']['label'] }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex-1 relative h-14 bg-gray-50/40">
                                {{-- garis jam --}}
                                @foreach($hours as $h)
                                    <div class="absolute top-0 bottom-0 border-l border-gray-100" style="left:{{ $pos($h) }}%"></div>
                                @endforeach

                                {{-- di luar shift: diarsir abu --}}
                                @if(!$shift['is_off'] && $shift['start'])
                                    @if($shift['start']->gt($winStart))
                                        <div class="absolute top-0 bottom-0 bg-gray-100/70"
                                             style="left:0;width:{{ $pos($shift['start']) }}%"></div>
                                    @endif
                                    @if($shift['end']->lt($winEnd))
                                        <div class="absolute top-0 bottom-0 bg-gray-100/70"
                                             style="left:{{ $pos($shift['end']) }}%;right:0"></div>
                                    @endif
                                    @if($shift['break_start'] && $shift['break_end'])
                                        <div class="absolute top-0 bottom-0 bg-gray-100/70 border-x border-gray-200"
                                             style="left:{{ $pos($shift['break_start']) }}%;width:{{ $pos($shift['break_end']) - $pos($shift['break_start']) }}%"
                                             title="Istirahat {{ $shift['break_start']->format('H:i') }}–{{ $shift['break_end']->format('H:i') }}"></div>
                                    @endif
                                @endif

                                {{-- lubang --}}
                                @foreach($row['gaps'] as $g)
                                    <div class="absolute top-1 bottom-1 rounded"
                                         style="left:{{ $pos($g['start']) }}%;width:{{ max(0.2, $pos($g['end']) - $pos($g['start'])) }}%;background:repeating-linear-gradient(45deg,#fde68a,#fde68a 3px,#fef3c7 3px,#fef3c7 6px)"
                                         title="Lubang {{ $g['start']->format('H:i') }}–{{ $g['end']->format('H:i') }} ({{ $jam($g['seconds']) }})"></div>
                                @endforeach

                                {{-- henti mesin: lubang yang sudah punya nama --}}
                                @foreach($row['downtimes'] ?? [] as $h)
                                    <div class="absolute top-1 bottom-1 rounded border border-slate-300 flex items-center px-1 overflow-hidden"
                                         style="left:{{ $pos($h['start']) }}%;width:{{ max(0.3, $pos($h['end']) - $pos($h['start'])) }}%;background:repeating-linear-gradient(45deg,#cbd5e1,#cbd5e1 3px,#e2e8f0 3px,#e2e8f0 6px)"
                                         title="{{ $h['label'] }} {{ $h['start']->format('H:i') }}–{{ $h['end']->format('H:i') }} ({{ $jam($h['seconds']) }}){{ $h['notes'] ? "
" . $h['notes'] : '' }}">
                                        <span class="truncate text-[10px] font-semibold text-slate-600">{{ $h['label'] }}</span>
                                    </div>
                                @endforeach

                                {{-- blok kerja --}}
                                @foreach($row['blocks'] as $b)
                                    @php
                                        $left  = $pos($b['start']);
                                        $width = max(0.25, $pos($b['end']) - $left);
                                        $luar  = !$shift['is_off'] && $shift['start']
                                                 && ($b['start']->lt($shift['start']) || $b['end']->gt($shift['end']));
                                        $judul = trim(($b['order_number'] ?? '') . ' · ' . ($b['product_name'] ?? $b['step_name']))
                                                 . "\n" . $b['start']->format('H:i') . '–' . $b['end']->format('H:i')
                                                 . ' (' . $jam($b['seconds']) . ')'
                                                 . "\nLangkah: " . $b['step_name']
                                                 . ($b['cycles'] > 0 ? "\nSiklus: " . rtrim(rtrim(number_format($b['cycles'], 2, ',', '.'), '0'), ',') : '')
                                                 . ($b['still_open'] ? "\n⚠ Timer belum ditutup — masih berjalan sampai sekarang" : '')
                                                 . ($b['closed_by'] === 'auto_paused' ? "\nDitutup otomatis (jam pulang / jeda)" : '')
                                                 . ($b['closed_by'] === 'completed' ? "\nDitutup operator: selesai" : '')
                                                 . ($b['closed_by'] === 'paused' ? "\nDijeda manual oleh operator" : '')
                                                 . ($b['from_yesterday'] ? "\n← lanjutan dari hari sebelumnya" : '')
                                                 . ($b['into_tomorrow'] ? "\n→ berlanjut ke hari berikutnya" : '');
                                    @endphp
                                    <a href="{{ route('production.orders.show', $b['order_id']) }}"
                                       class="absolute top-2 bottom-2 rounded-md px-1.5 flex items-center overflow-hidden text-white text-[10px] font-semibold leading-tight hover:brightness-110 transition
                                              {{ $b['still_open'] ? 'ring-2 ring-red-500' : '' }}
                                              {{ $luar ? 'border-2 border-dashed border-purple-400' : '' }}"
                                       style="left:{{ $left }}%;width:{{ $width }}%;background:{{ $warna($b['order_number']) }}"
                                       title="{{ $judul }}">
                                        <span class="truncate">{{ $b['product_name'] ?? $b['step_name'] }}</span>
                                    </a>
                                @endforeach
                            </div>

                            <div class="w-56 shrink-0 px-3 py-2.5 text-right">
                                <div class="text-sm font-bold {{ ($row['utilization'] ?? 0) > 100 ? 'text-red-600' : 'text-gray-800' }}">
                                    {{ $jam($row['busy_seconds']) }}
                                    @if($row['utilization'] !== null)
                                        <span class="text-[11px] font-semibold text-gray-400">
                                            ({{ number_format($row['utilization'], 0, ',', '.') }}%)
                                        </span>
                                    @endif
                                </div>
                                <div class="text-[10px] text-gray-400">
                                    lubang {{ $jam($row['gap_seconds']) }}
                                    @if($row['downtime_seconds'] > 0)
                                        · <span class="text-slate-600 font-semibold">henti {{ $jam($row['downtime_seconds']) }}</span>
                                    @endif
                                    @if($row['outside_seconds'] > 0)
                                        · luar jam <span class="text-purple-600 font-semibold">{{ $jam($row['outside_seconds']) }}</span>
                                    @endif
                                    @if($row['overlap_seconds'] > 0)
                                        · <span class="text-red-600 font-semibold" title="Dua langkah tercatat berjalan bersamaan di eksekutor yang sama">tumpang tindih {{ $jam($row['overlap_seconds']) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

    {{-- Rincian lubang --}}
    @php
        $semuaLubang = [];
        foreach ($data['departments'] as $d) {
            foreach ($d['rows'] as $r) {
                // Baris bukan-slot tidak punya kapasitas, jadi celahnya bukan lubang kapasitas.
                if (!$r['counts_as_slot']) continue;
                foreach ($r['gaps'] as $g) {
                    $semuaLubang[] = $g + ['dept' => $d['name'], 'row' => $r['name']];
                }
            }
        }
        usort($semuaLubang, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);
    @endphp

    @if(!empty($semuaLubang))
        <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <div class="text-sm font-bold text-gray-800">Rincian Lubang</div>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Rentang di dalam jam kerja yang tidak ada timer berjalan sama sekali, minimal 5 menit.
                    Celah lebih pendek dianggap jeda ganti benda kerja, bukan kapasitas hilang.
                </p>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="px-5 py-2 text-left font-bold">Divisi</th>
                        <th class="px-5 py-2 text-left font-bold">Eksekutor</th>
                        <th class="px-5 py-2 text-left font-bold">Mulai</th>
                        <th class="px-5 py-2 text-left font-bold">Sampai</th>
                        <th class="px-5 py-2 text-right font-bold">Lama</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($semuaLubang as $g)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-2 text-gray-500">{{ $g['dept'] }}</td>
                            <td class="px-5 py-2 font-semibold text-gray-800">{{ $g['row'] }}</td>
                            <td class="px-5 py-2 text-gray-600">{{ $g['start']->format('H:i') }}</td>
                            <td class="px-5 py-2 text-gray-600">{{ $g['end']->format('H:i') }}</td>
                            <td class="px-5 py-2 text-right font-bold text-amber-600">{{ $jam($g['seconds']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Henti mesin: beri nama pada lubangnya --}}
    @php
        $semuaHenti = [];
        foreach ($data['departments'] as $d) {
            foreach ($d['rows'] as $r) {
                foreach ($r['downtimes'] ?? [] as $h) {
                    $semuaHenti[] = $h + ['dept' => $d['name'], 'row' => $r['name']];
                }
            }
        }
    @endphp

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden mt-4">
        <div class="px-5 py-3 border-b border-gray-100">
            <div class="text-sm font-bold text-gray-800">Henti Mesin</div>
            <p class="text-[11px] text-gray-500 mt-0.5">
                Perawatan, kerusakan, mati listrik — waktu yang memang tidak produktif. Yang dicatat di sini
                berhenti dihitung sebagai lubang, karena lubangnya sudah punya nama. Kapasitasnya tetap
                dihitung: mesin yang sedang dirawat tetap menanggung sewa dan penyusutan.
            </p>
        </div>

        <form method="POST" action="{{ route('analisa.kalender.downtime.store') }}"
              class="px-5 py-3 border-b border-gray-100 bg-gray-50/60 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="tanggal" value="{{ $data['date']->toDateString() }}">
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Mesin / Eksekutor</label>
                <select name="executor_id" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                    @foreach($executors as $e)
                        <option value="{{ $e->id }}">{{ $e->department->name ?? '—' }} · {{ $e->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Dari</label>
                <input type="time" name="jam_mulai" value="08:00" required
                       class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Sampai</label>
                <input type="time" name="jam_selesai" value="11:30" required
                       class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Sebab</label>
                <select name="reason" class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                    @foreach($reasons as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-48">
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Keterangan (opsional)</label>
                <input type="text" name="notes" maxlength="255" placeholder="mis. ganti lensa, servis rutin"
                       class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
            </div>
            <button class="border border-slate-300 text-slate-700 hover:bg-slate-100 px-4 py-2 rounded-xl text-sm font-semibold">
                Catat
            </button>
        </form>

        @if(empty($semuaHenti))
            <div class="px-5 py-4 text-xs text-gray-400">Belum ada henti mesin dicatat pada tanggal ini.</div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase text-gray-500">
                    <tr>
                        <th class="px-5 py-2 text-left font-bold">Eksekutor</th>
                        <th class="px-5 py-2 text-left font-bold">Sebab</th>
                        <th class="px-5 py-2 text-left font-bold">Keterangan</th>
                        <th class="px-5 py-2 text-left font-bold">Jam</th>
                        <th class="px-5 py-2 text-right font-bold">Lama</th>
                        <th class="px-5 py-2 text-right font-bold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($semuaHenti as $h)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-2 font-semibold text-gray-800">{{ $h['row'] }}
                                <span class="text-gray-400 font-normal">· {{ $h['dept'] }}</span></td>
                            <td class="px-5 py-2 text-gray-700">{{ $h['label'] }}</td>
                            <td class="px-5 py-2 text-gray-500">{{ $h['notes'] ?: '—' }}</td>
                            <td class="px-5 py-2 text-gray-600">{{ $h['start']->format('H:i') }}–{{ $h['end']->format('H:i') }}</td>
                            <td class="px-5 py-2 text-right font-bold text-slate-600">{{ $jam($h['seconds']) }}</td>
                            <td class="px-5 py-2 text-right">
                                <form method="POST" action="{{ route('analisa.kalender.downtime.destroy', $h['id']) }}"
                                      onsubmit="return confirm('Hapus catatan henti mesin ini?');">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="text-[11px] text-gray-400 mt-4 leading-relaxed">
        Barisnya hanya pelaku sebenarnya: di CNC yang bekerja mesinnya, jadi operator penaung
        tidak punya baris dan jamnya tidak dihitung dua kali. Langkah lama yang terlanjur tercatat
        atas nama operator jatuh ke baris "Tanpa eksekutor" — bukan dihapus, supaya ketahuan.
    </p>
</div>
@endsection
