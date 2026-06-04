@php
    $karyawan = $slip->karyawan;
    $periode  = $slip->periode;
    $bulan    = (int) $periode->bulan;
    $tahun    = (int) $periode->tahun;

    // Hitung breakdown engine sama persis dgn halaman Slip Gaji show — supaya print konsisten.
    $breakdown = app(\App\Modules\SDM\Services\PayrollBreakdownService::class)->build($karyawan, $bulan, $tahun);
    $rows            = $breakdown['rows'];
    $hariKerja       = $breakdown['hariKerja'];
    $gajiPerHari     = $breakdown['gajiPerHari'];
    $lemburPerJam    = $breakdown['lemburPerJam'];
    $totalGajiHari   = $breakdown['totalGajiHari'];
    $totalLembur     = $breakdown['totalLembur'];
    $totalLemburJam  = $breakdown['totalLemburJam'];
    $kolomDinamis    = $breakdown['kolomDinamis'];
    $totalKolom      = $breakdown['totalKolom'];
    $summaryRows     = $breakdown['summaryRows'];
    $summaryValue    = $breakdown['summaryValue'];
    $summaryShow     = $breakdown['summaryShow'];
    $taxBpjs         = $breakdown['taxBpjs'];
    $payrollSetting  = $breakdown['payrollSetting'];
    $brutoSebelumPotongan = $breakdown['brutoSebelumPotongan'];
    $totalDibayarkan = $breakdown['totalDibayarkan'];

    $statusLabel = [
        'hadir'                => 'Hadir',
        'terlambat'            => 'Terlambat',
        'pulang_awal'          => 'Pulang Awal',
        'setengah_hari'        => 'Setengah Hari',
        'tidak_hadir'          => 'Tidak Masuk',
        'sakit'                => 'Sakit',
        'cuti'                 => 'Cuti',
        'libur'                => 'Libur',
        'lembur'               => 'Lembur',
        'lembur_setengah_hari' => 'Lembur ½ Hari',
    ];

    // Auto-load kasbon aktif kalau caller belum pass.
    $kasbonAktif = $kasbonAktif ?? \App\Modules\SDM\Models\Kasbon::where('karyawan_id', $karyawan->id)
        ->where('status', 'posted')
        ->where('sisa_terhutang', '>', 0)
        ->orderBy('tanggal_pengajuan')
        ->get();
@endphp

{{-- Header info karyawan --}}
<table class="w-full text-sm border border-gray-300">
    <tr class="bg-gray-100">
        <td colspan="4" class="text-center font-bold text-base py-2">SLIP GAJI {{ strtoupper($periode->label) }}</td>
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

{{-- Rincian kehadiran — engine-driven, kolom dinamis ikut --}}
<table class="w-full text-xs border border-gray-300 mt-3">
    <thead class="bg-gray-100 font-bold">
        <tr>
            <th rowspan="2" class="border px-2 py-1 text-left">Tanggal</th>
            <th colspan="2" class="border px-2 py-1 text-center">Reguler</th>
            <th rowspan="2" class="border px-2 py-1 text-left">Status</th>
            <th rowspan="2" class="border px-2 py-1 text-center">Lembur (jam)</th>
            <th rowspan="2" class="border px-2 py-1 text-right">Gaji/Hari</th>
            <th rowspan="2" class="border px-2 py-1 text-right">Lembur Rp</th>
            @foreach($kolomDinamis as $kol)
                <th rowspan="2" class="border px-2 py-1 text-right">{{ $kol->label }}</th>
            @endforeach
        </tr>
        <tr>
            <th class="border px-2 py-0.5 text-center font-normal text-[10px] text-gray-500">Datang</th>
            <th class="border px-2 py-0.5 text-center font-normal text-[10px] text-gray-500">Pulang</th>
        </tr>
    </thead>
    <tbody>
    @foreach($rows as $r)
        @php
            $rowBg = $r['is_holiday'] ? 'bg-red-50' : ($r['is_off'] ? 'bg-gray-50' : '');
            $lbl   = $r['status'] ? ($statusLabel[$r['status']] ?? ucwords(str_replace('_', ' ', $r['status']))) : '—';
        @endphp
        <tr class="{{ $rowBg }}">
            <td class="border px-2 py-0.5 whitespace-nowrap">{{ $r['date']->translatedFormat('D, d/m/y') }}</td>
            <td class="border px-2 py-0.5 text-center font-mono">{{ $r['reg_datang'] ?? '' }}</td>
            <td class="border px-2 py-0.5 text-center font-mono">{{ $r['reg_pulang'] ?? '' }}</td>
            <td class="border px-2 py-0.5">{{ $lbl }}</td>
            <td class="border px-2 py-0.5 text-center">{{ ($r['lembur_jam'] ?? 0) > 0 ? rtrim(rtrim(number_format($r['lembur_jam'], 1, ',', '.'), '0'), ',') : '' }}</td>
            <td class="border px-2 py-0.5 text-right">{{ ($r['gaji_pokok'] ?? 0) > 0 ? rupiah($r['gaji_pokok']) : '' }}</td>
            <td class="border px-2 py-0.5 text-right">{{ ($r['lembur_rp'] ?? 0) > 0 ? rupiah($r['lembur_rp']) : '' }}</td>
            @foreach($kolomDinamis as $kol)
                @php $v = $r['kolom_values'][$kol->key] ?? null; @endphp
                <td class="border px-2 py-0.5 text-right">
                    @if($kol->tipe === 'flag')
                        @if($v){{ is_string($v) ? $v : '✓' }}@endif
                    @elseif($kol->tipe === 'persen')
                        {{ is_numeric($v) && $v != 0 ? number_format($v, 1, ',', '.') . '%' : '' }}
                    @else
                        {{ is_numeric($v) && $v > 0 ? rupiah($v) : '' }}
                    @endif
                </td>
            @endforeach
        </tr>
    @endforeach
        <tr class="font-bold bg-gray-50">
            <td class="border px-2 py-1 text-center" colspan="4">Total</td>
            <td class="border px-2 py-1 text-center">{{ $totalLemburJam > 0 ? rtrim(rtrim(number_format($totalLemburJam, 1, ',', '.'), '0'), ',') : '' }}</td>
            <td class="border px-2 py-1 text-right">{{ rupiah($totalGajiHari) }}</td>
            <td class="border px-2 py-1 text-right">{{ rupiah($totalLembur) }}</td>
            @foreach($kolomDinamis as $kol)
                <td class="border px-2 py-1 text-right">
                    @if($kol->tipe === 'flag')—@else{{ ($totalKolom[$kol->key] ?? 0) > 0 ? rupiah($totalKolom[$kol->key]) : '' }}@endif
                </td>
            @endforeach
        </tr>
    </tbody>
</table>

{{-- Ringkasan komponen gaji (engine-driven, identik dgn halaman Slip Gaji show) --}}
<div class="mt-3 border border-gray-300 rounded overflow-hidden">
    @include('erp.sdm._partials.summary_box', ['editable' => false])
</div>

{{-- Komponen tambahan manual (kalau ada) --}}
@if($slip->komponen->isNotEmpty())
<table class="w-full text-sm border border-gray-300 mt-3">
    <tr class="bg-blue-50">
        <td colspan="2" class="border px-2 py-1 font-semibold text-blue-800 text-xs uppercase tracking-wider">Komponen Tambahan (Manual)</td>
    </tr>
    @foreach($slip->komponen as $k)
        <tr>
            <td class="border px-2 py-1 text-gray-700">
                {{ $k->label }}
                @if($k->metode === 'percentage')
                    <span class="text-xs text-gray-400">({{ rtrim(rtrim(number_format($k->nilai, 2, ',', '.'), '0'), ',') }}% dari {{ str_replace('_',' ', $k->basis) }})</span>
                @endif
            </td>
            <td class="border px-2 py-1 text-right">{{ rupiah($k->amount) }}</td>
        </tr>
    @endforeach
    <tr class="font-semibold bg-blue-50/50">
        <td class="border px-2 py-1">Total Komponen Tambahan</td>
        <td class="border px-2 py-1 text-right">{{ rupiah($slip->total_komponen_earning) }}</td>
    </tr>
</table>
@endif

{{-- Kasbon Aktif --}}
@if(isset($kasbonAktif) && $kasbonAktif->isNotEmpty())
    <div class="mt-3 bg-amber-50 border border-amber-200 rounded p-3 text-xs">
        <div class="font-semibold text-amber-800 mb-1">Kasbon Aktif Karyawan</div>
        <table class="w-full text-xs">
            <thead class="text-amber-700">
                <tr><th class="text-left">Kode</th><th class="text-right">Sisa Terhutang</th><th class="text-right">Cicilan/bln</th></tr>
            </thead>
            <tbody>
                @foreach($kasbonAktif as $k)
                    <tr>
                        <td class="font-mono">{{ $k->code }}</td>
                        <td class="text-right">{{ rupiah($k->sisa_terhutang) }}</td>
                        <td class="text-right">{{ rupiah($k->cicilan_per_bulan) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if($slip->notes)
    <div class="text-xs text-gray-600 mt-3"><strong>Catatan:</strong> {{ $slip->notes }}</div>
@endif
