@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

@php
    $statusOptions = [
        'hadir'                => ['label' => 'Hadir',          'color' => 'bg-green-100 text-green-700'],
        'terlambat'            => ['label' => 'Terlambat',      'color' => 'bg-yellow-100 text-yellow-700'],
        'pulang_awal'          => ['label' => 'Pulang Awal',    'color' => 'bg-yellow-100 text-yellow-700'],
        'setengah_hari'        => ['label' => 'Setengah Hari',  'color' => 'bg-orange-100 text-orange-700'],
        'tidak_hadir'          => ['label' => 'Tidak Masuk',    'color' => 'bg-red-100 text-red-700'],
        'sakit'                => ['label' => 'Sakit',          'color' => 'bg-purple-100 text-purple-700'],
        'cuti'                 => ['label' => 'Cuti',           'color' => 'bg-blue-100 text-blue-700'],
        'libur'                => ['label' => 'Libur',          'color' => 'bg-red-50 text-red-600'],
        'lembur'               => ['label' => 'Lembur',         'color' => 'bg-indigo-100 text-indigo-700'],
        'lembur_setengah_hari' => ['label' => 'Lembur ½ Hari',  'color' => 'bg-indigo-50 text-indigo-600'],
    ];
@endphp

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

{{-- Form upload Excel (di-trigger oleh tombol di filter bar; ditaruh di sini agar tidak nested di dalam form GET filter) --}}
<form method="POST" action="{{ route('sdm.absensi.upload-excel') }}" enctype="multipart/form-data" id="upload-excel-form" class="hidden">
    @csrf
    <input type="hidden" name="bulan" value="{{ $bulan }}">
    <input type="hidden" name="tahun" value="{{ $tahun }}">
    <input type="hidden" name="karyawan_id" value="{{ $karyawan?->id }}">
    <input type="file" name="file" accept=".xlsx,.xls" id="upload-excel-file"
           onchange="if(this.files.length){ document.getElementById('upload-excel-form').submit(); }">
</form>

<style>
    /* Samakan tinggi TomSelect dgn input/select native di filter bar */
    #karyawan-select-wrap .ts-wrapper { font-size: 0.875rem; }
    #karyawan-select-wrap .ts-control {
        min-height: 34px;
        padding: 4px 10px;
        border: 1px solid #d1d5db;
        border-radius: 0.25rem;
        box-shadow: none;
    }
    #karyawan-select-wrap .ts-control:focus-within { border-color: #93c5fd; box-shadow: 0 0 0 1px #bfdbfe; }
    .filter-control { height: 34px; }
</style>
<form method="GET" action="{{ route('sdm.absensi.index') }}" class="bg-white rounded shadow p-3 mb-4">
    <div class="flex flex-wrap items-end gap-3">
        <div class="w-40">
            <label class="block text-xs text-gray-500 mb-1">Bulan</label>
            <select onchange="submitBulan(this)" class="filter-control border rounded px-2 w-full text-sm">
                @foreach($bulanOptions as $opt)
                    <option value="{{ $opt['bulan'] }}-{{ $opt['tahun'] }}" @selected($opt['bulan'] == $bulan && $opt['tahun'] == $tahun)>{{ $opt['label'] }}</option>
                @endforeach
            </select>
            <input type="hidden" name="bulan" value="{{ $bulan }}">
            <input type="hidden" name="tahun" value="{{ $tahun }}">
        </div>
        <div class="flex-1 min-w-[220px] max-w-sm" id="karyawan-select-wrap">
            <label class="block text-xs text-gray-500 mb-1">Nama Karyawan</label>
            <select name="karyawan_id" id="karyawan-select" onchange="this.form.submit()" class="filter-control border rounded px-2 w-full text-sm">
                @foreach($karyawans as $k)
                    <option value="{{ $k->id }}" @selected($karyawan && $k->id === $karyawan->id)>{{ $k->name }} ({{ $k->staf_code }})</option>
                @endforeach
            </select>
        </div>
        <div class="w-20">
            <label class="block text-xs text-gray-500 mb-1" title="Libur Nasional dari menu Hari Libur">Hari Kerja</label>
            <div class="filter-control border rounded w-full bg-gray-50 text-sm font-semibold flex items-center justify-center">{{ $hariKerja }}</div>
        </div>
        <div class="flex items-end justify-end gap-1.5 ml-auto">
            @if($karyawan)
                <a href="{{ route('sdm.karyawan.edit', $karyawan->id) }}#jadwal" title="Pengaturan Jam Kerja" class="filter-control text-sm border border-gray-300 hover:bg-gray-50 px-3 rounded inline-flex items-center">⚙ Jam</a>
            @endif
            <button type="button" onclick="document.getElementById('upload-excel-file').click()"
                    class="filter-control text-sm border border-blue-300 text-blue-600 hover:bg-blue-50 px-3 rounded inline-flex items-center gap-1">
                ⬆ Upload Excel
            </button>
            @if($slip)
                <a href="{{ route('sdm.slip-gaji.show', $slip->id) }}" class="filter-control text-sm border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 rounded inline-flex items-center">Slip Gaji</a>
            @endif
            @if($karyawan)
                @if($existingPayment)
                    <a href="{{ route('finance.cash-bank.salary-payments.show', $existingPayment->id) }}"
                       class="filter-control text-sm border border-emerald-300 text-emerald-700 bg-emerald-50 px-3 rounded inline-flex items-center gap-1"
                       title="{{ $existingPayment->number }} ({{ $existingPayment->status }})">
                        ✓ Sudah Dibayar
                    </a>
                @else
                    <button type="button" onclick="document.dispatchEvent(new CustomEvent('open-quick-bayar'))"
                            class="filter-control text-sm bg-emerald-600 hover:bg-emerald-700 text-white px-4 rounded font-semibold inline-flex items-center gap-1">
                        💰 Bayar
                    </button>
                @endif
            @endif
        </div>
    </div>
</form>

{{-- Modal Quick Bayar Gaji --}}
@if($karyawan && ! $existingPayment)
<div x-data="{
        open: false,
        adminFee: 0,
        get adminFeeNum() { return Number(String(this.adminFee).replace(/[^\d]/g, '')) || 0; },
        get totalKas() { return ({{ (int) ($totalDibayarkan ?? 0) }}) + this.adminFeeNum; },
        fmt(n) { return 'Rp ' + (Number(n)||0).toLocaleString('id-ID'); }
     }"
     x-init="document.addEventListener('open-quick-bayar', () => { open = true })"
     x-show="open" x-cloak
     class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"
     @click.self="open = false" @keydown.escape.window="open = false">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <form method="POST" action="{{ route('finance.cash-bank.salary-payments.quick-pay') }}">
            @csrf
            <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
            <input type="hidden" name="periode_bulan" value="{{ $bulan }}">
            <input type="hidden" name="periode_tahun" value="{{ $tahun }}">
            <input type="hidden" name="redirect_to" value="{{ url()->full() }}">

            <div class="flex items-center justify-between px-5 py-3 border-b">
                <div>
                    <h3 class="text-base font-semibold">Bayar Gaji — {{ $karyawan->name }}</h3>
                    <div class="text-xs text-gray-500">Periode {{ \Carbon\Carbon::create($tahun, $bulan, 1)->translatedFormat('F Y') }}</div>
                </div>
                <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">×</button>
            </div>

            <div class="px-5 py-4 space-y-3 text-sm">
                <div class="bg-emerald-50 border border-emerald-100 rounded px-3 py-2 flex justify-between items-center">
                    <span class="text-emerald-700 text-xs">Nett Dibayar ke Karyawan</span>
                    <span class="font-bold text-emerald-700">{{ rupiah($totalDibayarkan ?? 0) }}</span>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tanggal</label>
                    <input type="date" name="date" value="{{ now()->toDateString() }}" required class="border rounded px-3 py-2 w-full text-sm">
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Akun Kas/Bank <span class="text-red-500">*</span></label>
                    <select name="cash_account_id" required class="border rounded px-3 py-2 w-full text-sm">
                        <option value="">— Pilih akun —</option>
                        @foreach($cashAccounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Biaya Admin <span class="text-gray-400">(transfer beda bank)</span></label>
                        <input type="text" name="admin_fee" x-model="adminFee" inputmode="numeric"
                               placeholder="0" class="border rounded px-3 py-2 w-full text-sm text-right">
                    </div>
                    <div x-show="adminFeeNum > 0" x-cloak>
                        <label class="block text-xs text-gray-500 mb-1">Akun Beban Admin <span class="text-red-500">*</span></label>
                        <select name="admin_fee_account_id" class="border rounded px-3 py-2 w-full text-sm" :required="adminFeeNum > 0">
                            <option value="">— Pilih akun —</option>
                            @foreach($expenseAccounts as $acc)
                                <option value="{{ $acc->id }}" @selected($defaultAdminFeeAccountId && $acc->id == $defaultAdminFeeAccountId)>{{ $acc->code }} — {{ $acc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div x-show="adminFeeNum > 0" x-cloak class="flex justify-between text-xs text-gray-600 border-t pt-2">
                    <span>Total Kas Keluar (nett + admin fee)</span>
                    <span class="font-semibold" x-text="fmt(totalKas)"></span>
                </div>
            </div>

            <div class="flex justify-end gap-2 px-5 py-3 border-t bg-gray-50">
                <button type="button" @click="open = false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Batal</button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm font-semibold">Bayar & Post</button>
            </div>
        </form>
    </div>
</div>
@endif

@if($karyawan)
<div class="bg-white rounded shadow p-4 mb-4 grid grid-cols-3 gap-4 text-sm">
    <div>
        <div class="text-xs text-gray-500">Gaji Pokok</div>
        <div class="font-bold text-base">{{ rupiah($karyawan->gaji_pokok) }}</div>
    </div>
    <div>
        <div class="text-xs text-gray-500">Gaji / Hari</div>
        <div class="font-bold text-base">{{ rupiah($gajiPerHari) }}</div>
        <div class="text-[11px] text-gray-400">{{ rupiah($karyawan->gaji_pokok) }} ÷ {{ $hariKerja }}</div>
    </div>
    <div>
        <div class="text-xs text-gray-500">Lembur / Jam</div>
        <div class="font-bold text-base">{{ rupiah($lemburPerJam) }}</div>
        <div class="text-[11px] text-gray-400">Gaji/hari ÷ 7 × 2</div>
    </div>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-xs">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th rowspan="2" class="px-3 py-2 text-left w-32 border-r">Tanggal</th>
                <th colspan="2" class="px-3 py-2 text-center border-r">Reguler</th>
                <th colspan="2" class="px-3 py-2 text-center border-r">Lembur</th>
                <th rowspan="2" class="px-3 py-2 text-left w-36 border-r">Status</th>
                <th rowspan="2" class="px-3 py-2 text-center w-20 border-r">Lembur (Jam)</th>
                <th rowspan="2" class="px-3 py-2 text-right w-28 border-r">Gaji/Hari</th>
                <th rowspan="2" class="px-3 py-2 text-right w-28 {{ count($kolomDinamis) ? 'border-r' : '' }}">Lembur Rp</th>
                @foreach($kolomDinamis as $i => $kol)
                    <th rowspan="2" class="px-3 py-2 text-right w-28 {{ $i < count($kolomDinamis) - 1 ? 'border-r' : '' }}">{{ $kol->label }}</th>
                @endforeach
            </tr>
            <tr>
                <th class="px-2 py-1 text-center w-20 font-normal text-[11px] text-gray-500">Datang</th>
                <th class="px-2 py-1 text-center w-20 font-normal text-[11px] text-gray-500 border-r">Pulang</th>
                <th class="px-2 py-1 text-center w-20 font-normal text-[11px] text-gray-500">Datang</th>
                <th class="px-2 py-1 text-center w-20 font-normal text-[11px] text-gray-500 border-r">Pulang</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                @php
                    $opt = $r['status'] ? ($statusOptions[$r['status']] ?? ['label' => $r['status'], 'color' => 'bg-gray-100']) : ['label' => '—', 'color' => 'bg-gray-50 text-gray-400'];
                    $rowBg = $r['is_holiday'] ? 'bg-red-50' : ($r['is_off'] ? 'bg-gray-50' : '');

                    $badges = [];
                    if ($r['is_off']) {
                        $badges[] = ['label' => 'Libur Mingguan', 'color' => 'bg-gray-200 text-gray-600'];
                    }
                    if ($r['is_holiday']) {
                        $badges[] = ['label' => 'Libur Nasional', 'color' => 'bg-red-200 text-red-700'];
                    }
                    foreach ($r['overrides'] ?? [] as $ov) {
                        $isPaired = $ov->paired_date && $ov->tanggal->toDateString() !== $r['date']->toDateString();
                        $pairedDate = $ov->paired_date ? $ov->paired_date->translatedFormat('d/m/y') : null;
                        $thisDate   = $ov->tanggal->translatedFormat('d/m/y');
                        switch ($ov->type) {
                            case 'cuti':
                                $badges[] = ['label' => 'Cuti', 'color' => 'bg-blue-100 text-blue-700']; break;
                            case 'sakit':
                                $badges[] = ['label' => 'Sakit', 'color' => 'bg-purple-100 text-purple-700']; break;
                            case 'izin_pagi':
                                $badges[] = ['label' => 'Izin Pagi', 'color' => 'bg-orange-100 text-orange-700']; break;
                            case 'izin_sore':
                                $badges[] = ['label' => 'Izin Sore', 'color' => 'bg-orange-100 text-orange-700']; break;
                            case 'tukar_hari':
                                $partner = $isPaired ? $thisDate : $pairedDate;
                                $badges[] = ['label' => 'Tukar Hari ↔ ' . $partner, 'color' => 'bg-teal-100 text-teal-700']; break;
                            case 'tukar_setengah_hari':
                                $partner = $isPaired ? $thisDate : $pairedDate;
                                $sesiHere = $isPaired ? $ov->paired_sesi : $ov->sesi;
                                $sesiTag  = $sesiHere ? ' (' . $sesiHere . ')' : '';
                                $badges[] = ['label' => 'Tukar ½ Hari' . $sesiTag . ' ↔ ' . $partner, 'color' => 'bg-teal-100 text-teal-700']; break;
                            case 'penyesuaian_jam':
                                $jm = $ov->jam_masuk_override ? substr($ov->jam_masuk_override, 0, 5) : '--:--';
                                $jp = $ov->jam_pulang_override ? substr($ov->jam_pulang_override, 0, 5) : '--:--';
                                $badges[] = ['label' => 'Penyesuaian ' . $jm . '→' . $jp, 'color' => 'bg-amber-100 text-amber-700']; break;
                            case 'lembur':
                                $badges[] = ['label' => 'Jadwal Lembur', 'color' => 'bg-green-100 text-green-700']; break;
                        }
                    }
                @endphp
                <tr class="border-b {{ $rowBg }}">
                    <td class="px-3 py-1.5 border-r whitespace-nowrap">
                        <div>{{ $r['date']->translatedFormat('D, d/m/y') }}</div>
                        @if(! empty($badges))
                            <div class="flex flex-wrap gap-1 mt-1">
                                @foreach($badges as $b)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded {{ $b['color'] }}">{{ $b['label'] }}</span>
                                @endforeach
                            </div>
                        @endif
                    </td>

                    {{-- Reguler Datang --}}
                    <td class="px-2 py-1.5 text-center font-mono">
                        @if($r['reg_datang'])
                            <span class="text-gray-800">{{ $r['reg_datang'] }}</span>
                        @elseif($r['sched_reg_datang'] && ! $r['is_off'] && ! $r['is_holiday'])
                            <span class="text-gray-300">{{ $r['sched_reg_datang'] }}</span>
                        @endif
                    </td>
                    {{-- Reguler Pulang --}}
                    <td class="px-2 py-1.5 text-center font-mono border-r">
                        @if($r['reg_pulang'])
                            <span class="text-gray-800">{{ $r['reg_pulang'] }}</span>
                        @elseif($r['sched_reg_pulang'] && ! $r['is_off'] && ! $r['is_holiday'])
                            <span class="text-gray-300">{{ $r['sched_reg_pulang'] }}</span>
                        @endif
                    </td>

                    {{-- Lembur Datang --}}
                    <td class="px-2 py-1.5 text-center font-mono">
                        @if($r['lembur_datang'])
                            <span class="text-gray-800">{{ $r['lembur_datang'] }}</span>
                        @elseif($r['sched_lembur_datang'] && ! $r['is_off'] && ! $r['is_holiday'])
                            <span class="text-gray-300">{{ $r['sched_lembur_datang'] }}</span>
                        @endif
                    </td>
                    {{-- Lembur Pulang --}}
                    <td class="px-2 py-1.5 text-center font-mono border-r">
                        @if($r['lembur_pulang'])
                            <span class="text-gray-800">{{ $r['lembur_pulang'] }}</span>
                        @elseif($r['sched_lembur_pulang'] && ! $r['is_off'] && ! $r['is_holiday'])
                            <span class="text-gray-300">{{ $r['sched_lembur_pulang'] }}</span>
                        @endif
                    </td>

                    <td class="px-3 py-1.5 border-r">
                        <form method="POST" action="{{ route('sdm.absensi.update-status') }}" class="inline">
                            @csrf
                            <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
                            <input type="hidden" name="tanggal" value="{{ $r['date']->toDateString() }}">
                            <select name="status" onchange="this.form.submit()" class="text-xs px-2 py-0.5 rounded border-0 cursor-pointer w-full {{ $opt['color'] }}">
                                <option value="" @selected(! $r['status'])>—</option>
                                @foreach($statusOptions as $val => $info)
                                    <option value="{{ $val }}" @selected($r['status'] === $val)>{{ $info['label'] }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td class="px-3 py-1.5 text-center border-r">{{ $r['lembur_jam'] > 0 ? rtrim(rtrim(number_format($r['lembur_jam'], 1, ',', '.'), '0'), ',') : '' }}</td>
                    <td class="px-3 py-1.5 text-right font-mono border-r">{{ $r['gaji_pokok'] > 0 ? rupiah($r['gaji_pokok']) : '' }}</td>
                    <td class="px-3 py-1.5 text-right font-mono {{ count($kolomDinamis) ? 'border-r' : '' }}">{{ $r['lembur_rp'] > 0 ? rupiah($r['lembur_rp']) : '' }}</td>
                    @foreach($kolomDinamis as $i => $kol)
                        @php $v = $r['kolom_values'][$kol->key] ?? null; @endphp
                        <td class="px-3 py-1.5 text-right font-mono {{ $i < count($kolomDinamis) - 1 ? 'border-r' : '' }}">
                            @if($kol->tipe === 'flag')
                                @if($v) <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">{{ is_string($v) ? $v : '✓' }}</span> @endif
                            @elseif($kol->tipe === 'persen')
                                {{ is_numeric($v) && $v != 0 ? number_format($v, 1, ',', '.') . '%' : '' }}
                            @else
                                {{ is_numeric($v) && $v > 0 ? rupiah($v) : '' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot class="bg-gray-50 border-t font-semibold">
            <tr>
                <td colspan="6" class="px-3 py-2 text-right border-r">Total</td>
                <td class="px-3 py-2 text-center border-r">{{ $totalLemburJam > 0 ? rtrim(rtrim(number_format($totalLemburJam, 1, ',', '.'), '0'), ',') : '' }}</td>
                <td class="px-3 py-2 text-right font-mono text-sm border-r">{{ rupiah($totalGajiHari) }}</td>
                <td class="px-3 py-2 text-right font-mono text-sm {{ count($kolomDinamis) ? 'border-r' : '' }}">{{ rupiah($totalLembur) }}</td>
                @foreach($kolomDinamis as $i => $kol)
                    <td class="px-3 py-2 text-right font-mono text-sm {{ $i < count($kolomDinamis) - 1 ? 'border-r' : '' }}">
                        @if($kol->tipe === 'flag')
                            —
                        @else
                            {{ ($totalKolom[$kol->key] ?? 0) > 0 ? rupiah($totalKolom[$kol->key]) : '' }}
                        @endif
                    </td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</div>

<div class="grid grid-cols-3 gap-4 mt-4">
    <div class="col-span-1 bg-white rounded shadow p-4 flex flex-col">
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs text-gray-500 font-semibold uppercase">Catatan</div>
            <span id="notes-status" class="text-[10px] text-gray-400"></span>
        </div>
        @if($slip && ! $slip->isFinalized())
            <form id="notes-form" method="POST" action="{{ route('sdm.absensi.save-notes') }}" class="flex-1 flex flex-col">
                @csrf
                <input type="hidden" name="karyawan_id" value="{{ $karyawan->id }}">
                <input type="hidden" name="bulan" value="{{ $bulan }}">
                <input type="hidden" name="tahun" value="{{ $tahun }}">
                <textarea name="notes" id="notes-input"
                          class="border rounded px-3 py-2 text-sm text-gray-700 resize-none flex-1 min-h-[120px] focus:outline-none focus:border-blue-400"
                          placeholder="Tulis catatan untuk slip gaji ini…"
                          data-original="{{ $slipNotes ?? '' }}">{{ $slipNotes }}</textarea>
                <div class="text-[10px] text-gray-400 mt-1">Tersimpan otomatis saat klik di luar / tekan Ctrl+Enter.</div>
            </form>
        @elseif($slip && $slip->isFinalized())
            <div class="text-sm text-gray-700 whitespace-pre-wrap min-h-[120px] bg-gray-50 rounded p-3">{{ $slipNotes ?: '—' }}</div>
            <div class="text-[10px] text-gray-400 mt-1">Slip sudah finalized — catatan tidak bisa diubah.</div>
        @else
            <div class="text-sm text-gray-400 italic min-h-[120px] bg-gray-50 rounded p-3 flex items-center justify-center text-center">
                Upload data absensi dulu untuk membuat slip & mengaktifkan catatan.
            </div>
        @endif
    </div>

    <div class="col-span-2 bg-white rounded shadow overflow-hidden">
        @include('erp.sdm._partials.summary_box', ['editable' => true])
    </div>
</div>
@else
<div class="bg-white rounded shadow p-6 text-center text-gray-400 text-sm">
    Belum ada karyawan aktif. Tambah karyawan di menu <a href="{{ route('sdm.karyawan.index') }}" class="text-blue-600 hover:underline">Karyawan</a>.
</div>
@endif

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak] { display: none !important; }</style>
<script>
function submitBulan(sel) {
    const [b, t] = sel.value.split('-');
    sel.form.querySelector('input[name="bulan"]').value = b;
    sel.form.querySelector('input[name="tahun"]').value = t;
    sel.form.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('karyawan-select');
    if (el && window.TomSelect) {
        new TomSelect(el, {
            create: false,
            allowEmptyOption: false,
            maxOptions: 500,
            placeholder: 'Cari nama / staf code...',
        });
    }

    // Auto-save inline nominal di Summary box on blur jika berubah
    document.querySelectorAll('.summary-val-form input[name="nominal"]').forEach(function (inp) {
        inp.addEventListener('blur', function () {
            const original = inp.dataset.original;
            if (String(inp.value || '0') === String(original)) return; // no change
            inp.form.submit();
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                inp.blur();
            }
        });
    });

    // Auto-save Catatan textarea (AJAX) — blur atau Ctrl+Enter
    const notesInp = document.getElementById('notes-input');
    const notesStatus = document.getElementById('notes-status');
    if (notesInp) {
        const setStatus = (txt, color) => {
            if (!notesStatus) return;
            notesStatus.textContent = txt;
            notesStatus.style.color = color || '#9ca3af';
        };
        const saveNotes = async () => {
            const original = notesInp.dataset.original || '';
            const current  = notesInp.value || '';
            if (current === original) return;
            setStatus('Menyimpan…');
            try {
                const form = document.getElementById('notes-form');
                const fd = new FormData(form);
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                notesInp.dataset.original = current;
                setStatus('✓ Tersimpan', '#10b981');
                setTimeout(() => setStatus(''), 2000);
            } catch (e) {
                setStatus('Gagal menyimpan', '#ef4444');
            }
        };
        notesInp.addEventListener('blur', saveNotes);
        notesInp.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                saveNotes();
            }
        });
    }
});
</script>
@endsection
