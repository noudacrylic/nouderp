@extends('layouts.erp')

@section('content')
<div class="max-w-5xl mx-auto">

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Slip Gaji <span class="font-mono text-sm text-gray-500">{{ $slip->code }}</span></h1>
    <div class="flex gap-2">
        @if(! $slip->isFinalized())
            <form method="POST" action="{{ route('sdm.slip-gaji.regenerate', $slip->id) }}"
                  onsubmit="return confirm('Hitung ulang slip dari attendance?')">
                @csrf
                <button class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-3 py-1.5 rounded text-sm">Hitung Ulang</button>
            </form>
        @endif
        <a href="{{ route('sdm.slip-gaji.print', $slip->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50">Print</a>
        <a href="{{ route('sdm.slip-gaji.print', ['id' => $slip->id, 'pdf' => 1]) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700 hover:bg-gray-50">PDF</a>
        <a href="{{ route('sdm.periode-gaji.show', $slip->periode_id) }}" class="text-gray-600 px-3 py-1.5 text-sm">Kembali</a>
    </div>
</div>

@if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

{{-- Header info karyawan --}}
<div class="bg-white rounded shadow p-5 mb-4">
    <table class="w-full text-sm border border-gray-300">
        <tr class="bg-gray-100">
            <td colspan="4" class="text-center font-bold text-base py-2">SLIP GAJI {{ strtoupper($slip->periode->label) }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 w-32 text-gray-600">Nama</td>
            <td class="border px-2 py-1 font-semibold">{{ $karyawan->name }}</td>
            <td class="border px-2 py-1 w-40 text-gray-600">Gaji Pokok</td>
            <td class="border px-2 py-1 text-right">{{ rupiah($karyawan->gaji_pokok) }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 text-gray-600">Bagian</td>
            <td class="border px-2 py-1">{{ $karyawan->department->name ?? '-' }}</td>
            <td class="border px-2 py-1 text-gray-600">Hari Kerja</td>
            <td class="border px-2 py-1 text-right">{{ $hariKerja }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 text-gray-600">Staf Code</td>
            <td class="border px-2 py-1 font-mono">{{ $karyawan->staf_code }}</td>
            <td class="border px-2 py-1 text-gray-600">Gaji / Hari</td>
            <td class="border px-2 py-1 text-right">{{ rupiah($gajiPerHari) }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 text-gray-600">User ID</td>
            <td class="border px-2 py-1 font-mono">{{ $karyawan->user_id_fingerprint ?? '-' }}</td>
            <td class="border px-2 py-1 text-gray-600">Lembur / Jam</td>
            <td class="border px-2 py-1 text-right">{{ rupiah($lemburPerJam) }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 text-gray-600">Sanksi</td>
            <td class="border px-2 py-1">{{ $karyawan->sanksi }}</td>
            <td class="border px-2 py-1 text-gray-600">Sisa Hak Cuti</td>
            <td class="border px-2 py-1 text-right">{{ $karyawan->hak_cuti }}</td>
        </tr>
        <tr>
            <td class="border px-2 py-1 text-gray-600">Mulai Kerja</td>
            <td class="border px-2 py-1" colspan="3">{{ optional($karyawan->mulai_kerja)->translatedFormat('M Y') ?? '-' }}</td>
        </tr>
    </table>
</div>

{{-- Tabel rincian kehadiran (sama dengan Daftar Absensi minus kolom Lembur Datang/Pulang) --}}
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

<div class="bg-white rounded shadow overflow-x-auto mb-4">
    <div class="px-5 pt-4 pb-2 text-xs font-semibold text-gray-600 uppercase tracking-wider">Rincian Kehadiran</div>
    <table class="w-full text-xs">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th rowspan="2" class="px-3 py-2 text-left w-32 border-r">Tanggal</th>
                <th colspan="2" class="px-3 py-2 text-center border-r">Reguler</th>
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
                                $partner  = $isPaired ? $thisDate : $pairedDate;
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
                        @endif
                    </td>
                    {{-- Reguler Pulang --}}
                    <td class="px-2 py-1.5 text-center font-mono border-r">
                        @if($r['reg_pulang'])
                            <span class="text-gray-800">{{ $r['reg_pulang'] }}</span>
                        @endif
                    </td>

                    <td class="px-3 py-1.5 border-r">
                        <span class="text-xs px-2 py-0.5 rounded {{ $opt['color'] }}">{{ $opt['label'] }}</span>
                    </td>
                    <td class="px-3 py-1.5 text-center border-r">{{ ($r['lembur_jam'] ?? 0) > 0 ? rtrim(rtrim(number_format($r['lembur_jam'], 1, ',', '.'), '0'), ',') : '' }}</td>
                    <td class="px-3 py-1.5 text-right font-mono border-r">{{ ($r['gaji_pokok'] ?? 0) > 0 ? rupiah($r['gaji_pokok']) : '' }}</td>
                    <td class="px-3 py-1.5 text-right font-mono {{ count($kolomDinamis) ? 'border-r' : '' }}">{{ ($r['lembur_rp'] ?? 0) > 0 ? rupiah($r['lembur_rp']) : '' }}</td>
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
                <td colspan="4" class="px-3 py-2 text-right border-r">Total</td>
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

{{-- Summary box (sama dengan absensi dashboard) --}}
<div class="bg-white rounded shadow overflow-hidden mb-4">
    @include('erp.sdm._partials.summary_box', ['editable' => ! $slip->isFinalized()])
</div>

{{-- Status pembayaran --}}
@php
    $payBadge = [
        'unpaid'  => 'bg-gray-200 text-gray-700',
        'partial' => 'bg-yellow-100 text-yellow-700',
        'paid'    => 'bg-green-100 text-green-700',
    ][$slip->payment_status] ?? 'bg-gray-200 text-gray-700';
@endphp
<div class="bg-white rounded shadow p-4 mb-4 flex items-center justify-between">
    <div class="text-sm text-gray-700">Status Pembayaran</div>
    <div>
        <span class="text-xs px-2 py-0.5 rounded {{ $payBadge }}">{{ strtoupper($slip->payment_status) }}</span>
        @if((float)$slip->total_dibayar > 0)
            <span class="text-xs text-gray-500 ml-2">({{ rupiah($slip->total_dibayar) }} dari {{ rupiah($slip->total_gaji) }})</span>
        @endif
    </div>
</div>

{{-- Komponen tambahan manual --}}
@if($slip->komponen->isNotEmpty())
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="text-xs font-semibold text-blue-800 mb-2 uppercase tracking-wider">Komponen Tambahan (Manual)</div>
    <table class="w-full text-sm">
        @foreach($slip->komponen as $k)
            <tr class="border-b">
                <td class="py-1 text-gray-700">
                    {{ $k->label }}
                    @if($k->metode === 'percentage')
                        <span class="text-xs text-gray-400">({{ rtrim(rtrim(number_format($k->nilai, 2, ',', '.'), '0'), ',') }}% dari {{ str_replace('_',' ', $k->basis) }})</span>
                    @endif
                </td>
                <td class="py-1 text-right font-mono">{{ rupiah($k->amount) }}</td>
            </tr>
        @endforeach
        <tr class="font-semibold bg-blue-50">
            <td class="py-1.5 px-2">Total Komponen Tambahan</td>
            <td class="py-1.5 px-2 text-right font-mono">{{ rupiah($slip->total_komponen_earning) }}</td>
        </tr>
    </table>
</div>
@endif

{{-- Kasbon Aktif --}}
@if(isset($kasbonAktif) && $kasbonAktif->isNotEmpty())
<div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs mb-4">
    <div class="font-semibold text-amber-800 mb-1">Kasbon Aktif Karyawan</div>
    <table class="w-full text-xs">
        <thead class="text-amber-700">
            <tr><th class="text-left">Kode</th><th class="text-right">Sisa Terhutang</th><th class="text-right">Cicilan/bln</th></tr>
        </thead>
        <tbody>
            @foreach($kasbonAktif as $k)
                <tr>
                    <td><a href="{{ route('sdm.kasbon.show', $k->id) }}" class="text-blue-600 font-mono">{{ $k->code }}</a></td>
                    <td class="text-right">{{ rupiah($k->sisa_terhutang) }}</td>
                    <td class="text-right">{{ rupiah($k->cicilan_per_bulan) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if($slip->notes)
    <div class="bg-white rounded shadow p-3 text-xs text-gray-600 mb-4"><strong>Catatan:</strong> {{ $slip->notes }}</div>
@endif

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Auto-save inline nominal di Summary box on blur jika berubah
    document.querySelectorAll('.summary-val-form input[name="nominal"]').forEach(function (inp) {
        inp.addEventListener('blur', function () {
            const original = inp.dataset.original;
            if (String(inp.value || '0') === String(original)) return;
            inp.form.submit();
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                inp.blur();
            }
        });
    });
});
</script>
@endsection
