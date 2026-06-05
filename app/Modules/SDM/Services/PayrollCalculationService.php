<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\Kasbon;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\PeriodePenggajian;
use App\Modules\SDM\Models\SlipGaji;
use App\Modules\SDM\Models\SlipGajiKomponen;
use Illuminate\Support\Facades\DB;

class PayrollCalculationService
{
    public function __construct(protected KebijakanRuleEngine $engine) {}

    public function generateAllSlips(PeriodePenggajian $periode): void
    {
        $karyawanIds = Attendance::where('periode_id', $periode->id)
            ->distinct()->pluck('karyawan_id');

        foreach ($karyawanIds as $kid) {
            $this->ensureSlip($periode, (int) $kid);
        }
    }

    public function ensureSlip(PeriodePenggajian $periode, int $karyawanId): SlipGaji
    {
        $slip = SlipGaji::firstOrNew([
            'periode_id'  => $periode->id,
            'karyawan_id' => $karyawanId,
        ]);

        if (! $slip->exists) {
            $karyawan = Karyawan::findOrFail($karyawanId);
            $slip->code = sprintf('%s-%s', $periode->code, $karyawan->staf_code);
            $slip->status = 'draft';
        }

        return $this->recalculate($slip);
    }

    public function regenerateSlip(SlipGaji $slip): SlipGaji
    {
        if ($slip->isFinalized()) {
            return $slip;
        }
        return $this->recalculate($slip);
    }

    /**
     * Recompute komponen manual baris-baris di slip (label, metode %, nominal, basis).
     * Dipanggil saat slip update / regenerate.
     */
    public function recalculateKomponen(SlipGaji $slip): float
    {
        $komponen = $slip->komponen()->orderBy('urutan')->get();
        $total = 0;
        foreach ($komponen as $row) {
            $amount = $this->resolveKomponenAmount($row, $slip);
            $row->amount = round($amount, 2);
            $row->save();
            $total += (float) $row->amount;
        }
        return round($total, 2);
    }

    public function resolveKomponenAmount(SlipGajiKomponen $row, SlipGaji $slip): float
    {
        if ($row->metode === 'nominal') {
            return (float) $row->nilai;
        }
        $basisVal = match ($row->basis) {
            'gaji_pokok'         => (float) $slip->gaji_pokok_bulan,
            'gaji_pokok_dibayar' => (float) $slip->gaji_pokok_dibayar,
            'subtotal'           => (float) $slip->subtotal,
            default              => 0.0,
        };
        return $basisVal * ((float) $row->nilai / 100);
    }

    protected function recalculate(SlipGaji $slip): SlipGaji
    {
        $karyawan = Karyawan::findOrFail($slip->karyawan_id);
        $agg = $this->aggregateAttendance($slip->periode_id, $karyawan->id);

        $hariKerjaPeriode = $slip->exists && (int) $slip->hari_kerja_periode > 0
            ? (int) $slip->hari_kerja_periode
            : 25;

        $manual = [
            'bonus'              => (float) ($slip->bonus ?? 0),
            'thr_amount'         => (float) ($slip->thr_amount ?? 0),
            'cuti_unused_amount' => (float) ($slip->cuti_unused_amount ?? 0),
            'hari_kerja_periode' => $hariKerjaPeriode,
            'notes'              => $slip->notes,
        ];

        $amounts = $this->computeAmounts($karyawan, $agg, $manual);

        // BPJS/Pajak fix dari master karyawan (bisa di-override per slip nanti via edit form)
        $bpjsKes = $slip->exists ? (float) $slip->bpjs_kesehatan_amount : 0.0;
        $bpjsTk  = $slip->exists ? (float) $slip->bpjs_tk_amount : 0.0;
        $pph21   = $slip->exists ? (float) $slip->pph21_amount : 0.0;
        if (! $slip->exists) {
            $bpjsKes = (float) ($karyawan->bpjs_kesehatan_amount ?? 0);
            $bpjsTk  = (float) ($karyawan->bpjs_tk_amount ?? 0);
            $pph21   = (float) ($karyawan->pph21_amount ?? 0);
        }

        // Kasbon: auto-suggest cicilan dari kasbon aktif kalau belum di-set
        $kasbonPayment = $slip->exists ? (float) $slip->kasbon_payment : 0.0;
        if (! $slip->exists) {
            $kasbonPayment = $this->suggestKasbonCicilan($karyawan->id);
        }

        $slip->fill(array_merge([
            'gaji_pokok_bulan'      => $karyawan->gaji_pokok,
            'hari_hadir_penuh'      => $agg['hari_full'],
            'hari_setengah'         => $agg['hari_half'],
            'hari_terlambat'        => $agg['hari_terlambat'],
            'hari_pulang_awal'      => $agg['hari_pulang_awal'],
            'hari_tidak_hadir'      => $agg['hari_tidak_hadir'],
            'hari_libur'            => $agg['hari_libur'],
            'hari_bonus_absen'      => $agg['hari_bonus_absen'],
            'total_jam_lembur'      => $agg['total_jam_lembur'],
            'bonus'                 => $manual['bonus'],
            'thr_amount'            => $manual['thr_amount'],
            'cuti_unused_amount'    => $manual['cuti_unused_amount'],
            'bpjs_kesehatan_amount' => $bpjsKes,
            'bpjs_tk_amount'        => $bpjsTk,
            'pph21_amount'          => $pph21,
            'kasbon_payment'        => $kasbonPayment,
            'notes'                 => $manual['notes'],
        ], $amounts));

        $slip->save();

        // Komponen manual: recompute amounts (kalau ada baris dengan metode persentase yg basis pakai subtotal/gaji_pokok)
        $totalKomponen = $this->recalculateKomponen($slip);
        $totalPotongan = $bpjsKes + $bpjsTk + $pph21 + (float) $slip->kasbon_payment;

        $slip->total_komponen_earning = round($totalKomponen, 2);
        $slip->total_potongan         = round($totalPotongan, 2);
        $slip->total_gaji             = round((float) $slip->subtotal + $totalKomponen - $totalPotongan, 2);
        $slip->sisa_dibayar           = round((float) $slip->total_gaji - (float) $slip->total_dibayar, 2);
        $slip->save();

        return $slip;
    }

    protected function suggestKasbonCicilan(int $karyawanId): float
    {
        $total = 0;
        Kasbon::where('karyawan_id', $karyawanId)
            ->where('status', 'posted')
            ->where('sisa_terhutang', '>', 0)
            ->get()
            ->each(function ($k) use (&$total) {
                $cicilan = min((float) $k->cicilan_per_bulan, (float) $k->sisa_terhutang);
                $total += $cicilan;
            });
        return round($total, 2);
    }

    protected function aggregateAttendance(int $periodeId, int $karyawanId): array
    {
        $rows = Attendance::where('periode_id', $periodeId)
            ->where('karyawan_id', $karyawanId)
            ->get();

        return [
            'hari_full'             => (int) $rows->where('get_full_pay', true)->count(),
            'hari_half'             => (int) $rows->where('get_half_pay', true)->count(),
            'hari_dapat_tunjangan'  => (int) $rows->where('get_tunjangan', true)->count(),
            'hari_bonus_absen'      => (int) $rows->where('get_bonus_absen', true)->count(),
            'hari_terlambat'        => (int) $rows->where('status', 'terlambat')->count(),
            'hari_pulang_awal'      => (int) $rows->where('status', 'pulang_awal')->count(),
            'hari_tidak_hadir'      => (int) $rows->where('status', 'tidak_hadir')->count(),
            'hari_libur'            => (int) $rows->where('status', 'libur')->count(),
            'total_jam_lembur'      => (float) $rows->sum('overtime_hours'),
        ];
    }

    protected function computeAmounts(Karyawan $k, array $agg, array $manual): array
    {
        $hariKerja = max(1, (int) $manual['hari_kerja_periode']);
        $gajiPerHari = (float) $k->gaji_pokok / $hariKerja;
        // Pembagi lembur = 7 (aturan: lembur 2x = Rp29.829/jam pada UMR), konsisten dgn
        // PayrollBreakdownService, SummaryPreviewService, AttendanceController. Sebelumnya
        // keliru 8 → slip DB undervalue lembur ~12,5% & beda dgn tampilan slip/dashboard.
        $jamPerHari = 7;

        $gajiPokokDibayar = ($agg['hari_full'] * $gajiPerHari)
                          + ($agg['hari_half'] * 0.5 * $gajiPerHari);

        $lemburAmount = $agg['total_jam_lembur'] * ($gajiPerHari / $jamPerHari) * 2;

        $tunjHarianPerHari = $this->resolveKolomNominal($k, 'tunjangan_harian', $gajiPerHari);
        $tunjHarianAmount  = $agg['hari_dapat_tunjangan'] * $tunjHarianPerHari;

        $bonusHarianPerHari = $this->resolveKolomNominal($k, 'bonus_harian', $gajiPerHari);
        $bonusAbsenAmount   = $agg['hari_bonus_absen'] * $bonusHarianPerHari;

        $summaryAuto       = $this->engine->applySummaryAuto($k, $gajiPerHari);
        $tunjBulananAmount = (float) ($summaryAuto['tunjangan_bulanan'] ?? 0);

        $subtotal = $gajiPokokDibayar + $lemburAmount + $tunjHarianAmount + $tunjBulananAmount
                  + $bonusAbsenAmount
                  + $manual['bonus'] + $manual['thr_amount'] + $manual['cuti_unused_amount'];

        return [
            'hari_kerja_periode'       => $hariKerja,
            'gaji_per_hari'            => round($gajiPerHari, 2),
            'gaji_pokok_dibayar'       => round($gajiPokokDibayar, 2),
            'lembur_amount'            => round($lemburAmount, 2),
            'tunjangan_harian_amount'  => round($tunjHarianAmount, 2),
            'tunjangan_bulanan_amount' => round($tunjBulananAmount, 2),
            'bonus_absen_amount'       => round($bonusAbsenAmount, 2),
            'subtotal'                 => round($subtotal, 2),
        ];
    }

    protected function resolveKolomNominal(Karyawan $k, string $kolomKey, float $gajiPerHari): float
    {
        $sampleRow = [
            'status'       => 'hadir',
            'reg_datang'   => '07:00',
            'reg_pulang'   => '16:00',
            'lembur_jam'   => 0,
            'is_holiday'   => false,
            'is_off'       => false,
        ];
        $result = $this->engine->applyRow($sampleRow, $k, $gajiPerHari);
        $v = $result[$kolomKey] ?? 0;
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public function finalizePeriode(PeriodePenggajian $periode): void
    {
        DB::transaction(function () use ($periode) {
            SlipGaji::where('periode_id', $periode->id)->update([
                'status'       => 'finalized',
                'finalized_at' => now(),
            ]);
            $periode->update(['status' => 'finalized', 'finalized_at' => now()]);
        });
    }
}
