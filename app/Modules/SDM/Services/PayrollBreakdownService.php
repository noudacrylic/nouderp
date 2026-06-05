<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\KebijakanKolom;
use App\Modules\SDM\Models\KebijakanSummary;
use App\Modules\SDM\Models\KebijakanSummaryValue;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\SDM\Models\PayrollSetting;
use App\Modules\SDM\Models\PeriodePenggajian;
use Carbon\Carbon;

/**
 * Hitung breakdown gaji bulanan (totals + kolom dinamis + summary rows + BPJS/PPh)
 * untuk seorang karyawan di bulan/tahun tertentu.
 *
 * Dipakai oleh:
 *  - AttendanceController::dashboard (Daftar Absensi)
 *  - SlipGajiController::show (Slip Gaji)
 * supaya angkanya konsisten.
 */
class PayrollBreakdownService
{
    public function __construct(protected KebijakanRuleEngine $engine) {}

    /**
     * @return array{
     *   rows: array,
     *   hariKerja: int,
     *   gajiPerHari: float,
     *   lemburPerJam: float,
     *   totalGajiHari: float,
     *   totalLembur: float,
     *   totalLemburJam: float,
     *   kolomDinamis: \Illuminate\Support\Collection,
     *   totalKolom: array,
     *   summaryRows: \Illuminate\Support\Collection,
     *   summaryValue: array,
     *   summaryShow: array,
     *   brutoSebelumPotongan: float,
     *   taxBpjs: ?array,
     *   payrollSetting: PayrollSetting,
     *   totalDibayarkan: float,
     *   periode: ?PeriodePenggajian,
     * }
     */
    public function build(Karyawan $karyawan, int $bulan, int $tahun): array
    {
        $startDate = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $endDate   = $startDate->copy()->endOfMonth();

        $periode = PeriodePenggajian::where('bulan', $bulan)->where('tahun', $tahun)->first();

        $holidayDates = NationalHoliday::whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('tanggal')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $kolomDinamis = KebijakanKolom::aktif()->ordered()->get();
        $totalKolom   = [];
        foreach ($kolomDinamis as $kol) $totalKolom[$kol->key] = 0.0;

        $schedule = $karyawan->schedules->keyBy('day_of_week');

        $attendances = Attendance::when($periode, fn ($q) => $q->where('periode_id', $periode->id))
            ->where('karyawan_id', $karyawan->id)
            ->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->keyBy(fn ($a) => Carbon::parse($a->tanggal)->toDateString());

        $overridesRaw = AttendanceOverride::where('karyawan_id', $karyawan->id)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
                  ->orWhereBetween('paired_date', [$startDate->toDateString(), $endDate->toDateString()]);
            })->get();

        $overridesByDate = [];
        foreach ($overridesRaw as $o) {
            $tStr = Carbon::parse($o->tanggal)->toDateString();
            $overridesByDate[$tStr][] = $o;
            if ($o->paired_date) {
                $pStr = Carbon::parse($o->paired_date)->toDateString();
                $overridesByDate[$pStr][] = $o;
            }
        }

        $rows      = [];
        $hariKerja = 0;
        $cursor    = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dateStr   = $cursor->toDateString();
            $dow       = (int) $cursor->dayOfWeek;
            $sched     = $schedule[$dow] ?? null;
            $isOff     = $sched ? (bool) $sched->is_off : ($dow === 0);
            $isHoli    = in_array($dateStr, $holidayDates, true);
            $isWorkDay = ! $isOff && ! $isHoli;
            if ($isWorkDay) $hariKerja++;

            $rows[] = [
                'date'        => $cursor->copy(),
                'is_off'      => $isOff,
                'is_holiday'  => $isHoli,
                'is_work_day' => $isWorkDay,
                'schedule'    => $sched,
                'attendance'  => $attendances[$dateStr] ?? null,
                'overrides'   => collect($overridesByDate[$dateStr] ?? []),
            ];
            $cursor->addDay();
        }

        $gajiPerHari  = $hariKerja > 0 ? round((float) $karyawan->gaji_pokok / $hariKerja) : 0;
        $lemburPerJam = round($gajiPerHari / 7 * 2);

        $totalGajiHari  = 0;
        $totalLembur    = 0;
        $totalLemburJam = 0;

        foreach ($rows as &$row) {
            $att   = $row['attendance'];
            $sched = $row['schedule'];

            $regIn  = $att?->on_work1 ? substr($att->on_work1, 0, 5) : null;
            $regOut = $att?->off_work1 ? substr($att->off_work1, 0, 5) : null;
            $otIn   = $att?->on_work2 ? substr($att->on_work2, 0, 5) : null;
            $otOut  = $att?->off_work2 ? substr($att->off_work2, 0, 5) : null;

            $status  = static::resolveStatus($row, $regIn, $regOut);
            $lemburJ = static::resolveLemburJam($row, $status, $att, $otOut, $regOut);

            $row['reg_datang']    = $regIn;
            $row['reg_pulang']    = $regOut;
            $row['lembur_datang'] = $otIn;
            $row['lembur_pulang'] = $otOut;
            $row['status']        = $status;
            $row['lembur_jam']    = $lemburJ;
            $row['gaji_pokok']    = static::paysFullDay($status)
                ? $gajiPerHari
                : (static::paysHalfDay($status) ? (int) round($gajiPerHari / 2) : 0);
            $row['lembur_rp']     = $lemburJ * $lemburPerJam;

            $row['kolom_values'] = $this->engine->applyRow($row, $karyawan, (float) $gajiPerHari);

            foreach ($kolomDinamis as $kol) {
                $v = $row['kolom_values'][$kol->key] ?? null;
                if ($kol->tipe === 'flag') continue;
                if (is_numeric($v)) $totalKolom[$kol->key] += (float) $v;
            }

            $totalGajiHari  += $row['gaji_pokok'];
            $totalLembur    += $row['lembur_rp'];
            $totalLemburJam += $lemburJ;
        }
        unset($row);

        // === Summary rows ===
        $summaryRows  = KebijakanSummary::aktif()->ordered()->get();
        $summaryValue = [];
        $summaryShow  = [];

        $autoValues = $this->engine->applySummaryAuto($karyawan, (float) $gajiPerHari);
        foreach ($summaryRows as $s) {
            if ($s->key === KebijakanSummary::KEY_TOTAL) continue;
            if ($s->mode === 'auto') {
                $summaryValue[$s->key] = (float) ($autoValues[$s->key] ?? 0);
                $summaryShow[$s->key]  = true;
                continue;
            }

            $scope = $s->scope ?? 'all';
            $recur = $s->recurrence ?? 'monthly';

            if ($scope === 'all' && $recur === 'monthly') {
                $summaryValue[$s->key] = (float) $s->nominal_manual;
                $summaryShow[$s->key]  = true;
            } else {
                $q = KebijakanSummaryValue::where('summary_id', $s->id);
                $scope === 'per_karyawan'
                    ? $q->where('karyawan_id', $karyawan->id)
                    : $q->whereNull('karyawan_id');
                if ($recur === 'one_time') {
                    $q->where('bulan', $bulan)->where('tahun', $tahun);
                } else {
                    $q->whereNull('bulan')->whereNull('tahun');
                }
                $row = $q->first();
                $summaryValue[$s->key] = $row ? (float) $row->nominal : 0.0;
                $summaryShow[$s->key]  = $recur === 'one_time' ? (bool) $row : true;
            }
        }

        // === Bruto + potongan BPJS/PPh 21 ===
        $totalSemuaKolom   = array_sum(array_filter($totalKolom, 'is_numeric'));
        $totalSummaryPlus  = 0;
        $totalSummaryMinus = 0;
        foreach ($summaryRows as $s) {
            if ($s->key === KebijakanSummary::KEY_TOTAL) continue;
            $v = (float) ($summaryValue[$s->key] ?? 0);
            if ($s->arah === 'plus') $totalSummaryPlus += $v;
            else                     $totalSummaryMinus += $v;
        }
        $brutoSebelumPotongan = $totalGajiHari + $totalLembur + $totalSemuaKolom + $totalSummaryPlus - $totalSummaryMinus;

        $payrollSetting = PayrollSetting::singleton();
        $calc           = new PayrollTaxBpjsCalculator($payrollSetting);
        $taxBpjs        = $calc->compute($karyawan, $brutoSebelumPotongan);

        $totalDibayarkan = $brutoSebelumPotongan - (float) ($taxBpjs['total_potongan'] ?? 0);
        $summaryValue[KebijakanSummary::KEY_TOTAL] = $totalDibayarkan;

        return compact(
            'rows', 'hariKerja', 'gajiPerHari', 'lemburPerJam',
            'totalGajiHari', 'totalLembur', 'totalLemburJam',
            'kolomDinamis', 'totalKolom',
            'summaryRows', 'summaryValue', 'summaryShow',
            'brutoSebelumPotongan', 'taxBpjs', 'payrollSetting', 'totalDibayarkan',
            'periode',
        );
    }

    // ===== Static helpers (duplikat dari AttendanceController supaya bisa dipakai standalone) =====

    public static function resolveStatus(array $row, ?string $on1, ?string $off1): ?string
    {
        $att        = $row['attendance'];
        $sched      = $row['schedule'];
        $isFullSwap = static::hasFullDaySwap($row);
        $isHalfSwap = static::hasHalfDaySwap($row);

        if ($att && $att->edited_manually && $att->status) return $att->status;

        if ($isHalfSwap) {
            if (! $row['is_off'] && ! $row['is_holiday']) return 'hadir';
            $sides = ($on1 ? 1 : 0) + ($off1 ? 1 : 0);
            return $sides >= 2 ? 'lembur_setengah_hari' : 'libur';
        }

        if ($isFullSwap && ! $row['is_off'] && ! $row['is_holiday']) return 'libur';

        if (($row['is_holiday'] || $row['is_off']) && ! $isFullSwap) {
            if (! $on1 && ! $off1) return 'libur';
            $sides = ($on1 ? 1 : 0) + ($off1 ? 1 : 0);
            return $sides === 1 ? 'lembur_setengah_hari' : 'lembur';
        }

        if (! $on1 && ! $off1) return null;
        if (! $on1 || ! $off1) return 'setengah_hari';

        $overrides = $row['overrides'] ?? collect();
        $penyJam   = $overrides->firstWhere('type', 'penyesuaian_jam');

        $jamMasuk  = $penyJam?->jam_masuk_override
            ? substr($penyJam->jam_masuk_override, 0, 5)
            : ($sched?->jam_masuk ? substr($sched->jam_masuk, 0, 5) : '08:00');
        $jamPulang = $penyJam?->jam_pulang_override
            ? substr($penyJam->jam_pulang_override, 0, 5)
            : ($sched?->jam_pulang ? substr($sched->jam_pulang, 0, 5) : '16:00');
        $lateTol   = (int) ($sched?->late_in_minutes ?? 10);
        $earlyTol  = (int) ($sched?->early_out_minutes ?? 0);

        $masukMin  = static::toMinutes($jamMasuk);
        $pulangMin = static::toMinutes($jamPulang);
        $on1Min    = static::toMinutes($on1);
        $off1Min   = static::toMinutes($off1);

        if ($on1Min - $masukMin > 150)   return 'setengah_hari';
        if ($pulangMin - $off1Min > 120) return 'setengah_hari';

        $isLate  = ($on1Min - $masukMin) > $lateTol;
        $isEarly = ($pulangMin - $off1Min) > $earlyTol;

        if ($isLate && $isEarly) return 'pulang_awal';
        if ($isLate)             return 'terlambat';
        if ($isEarly)            return 'pulang_awal';

        return 'hadir';
    }

    public static function resolveLemburJam(array $row, ?string $status, ?Attendance $att, ?string $otOut, ?string $regOut): float
    {
        if ($att && $att->edited_manually && (float) $att->overtime_hours > 0) {
            return (float) $att->overtime_hours;
        }
        if ($status === 'libur') return 0.0;
        if ($status === 'lembur_setengah_hari') return 3.5;

        $isSwapped = static::hasFullDaySwap($row);
        if (! $isSwapped && ($status === 'lembur' || $row['is_off'] || $row['is_holiday'])) {
            return ($att && ($att->on_work1 || $att->off_work1)) ? 7.0 : 0.0;
        }

        // Lembur HARI KERJA: durasi NYATA dari jam_masuk_lembur (setting jadwal) s/d
        // scan keluar lembur (off_work2), DIKURANGI istirahat lembur yang beririsan.
        // Hanya bila lembur dijadwalkan (has_lembur) & ada scan keluar lembur.
        $sched = $row['schedule'] ?? null;
        if (! $sched || ! $sched->has_lembur) return 0.0;
        if (! $otOut) return 0.0;

        $startStr = $sched->jam_masuk_lembur
            ? substr($sched->jam_masuk_lembur, 0, 5)
            : ($sched->jam_pulang ? substr($sched->jam_pulang, 0, 5) : '16:00');

        $startMin = static::toMinutes($startStr);
        $outMin   = static::toMinutes($otOut);
        if ($outMin <= $startMin) return 0.0;

        $mins = $outMin - $startMin;

        if ($sched->jam_istirahat_lembur_start && $sched->jam_istirahat_lembur_end) {
            $bStart = static::toMinutes(substr($sched->jam_istirahat_lembur_start, 0, 5));
            $bEnd   = static::toMinutes(substr($sched->jam_istirahat_lembur_end, 0, 5));
            $overlap = max(0, min($outMin, $bEnd) - max($startMin, $bStart));
            $mins -= $overlap;
        }

        return round(max(0, $mins) / 60, 2);
    }

    public static function paysFullDay(?string $status): bool
    {
        return in_array($status, ['hadir', 'terlambat', 'pulang_awal', 'cuti', 'sakit'], true);
    }

    public static function paysHalfDay(?string $status): bool
    {
        return $status === 'setengah_hari';
    }

    public static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, 0);
        return ((int) $h) * 60 + (int) $m;
    }

    public static function hasFullDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn ($o) => $o->type === 'tukar_hari');
    }

    public static function hasHalfDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn ($o) => $o->type === 'tukar_setengah_hari');
    }
}
