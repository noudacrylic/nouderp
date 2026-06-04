<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\KaryawanSchedule;
use Carbon\Carbon;

class ExecutorScheduleResolver
{
    public function forKaryawan(int $karyawanId, Carbon $date): ?KaryawanSchedule
    {
        return KaryawanSchedule::where('karyawan_id', $karyawanId)
            ->where('day_of_week', (int) $date->dayOfWeek)
            ->first();
    }

    public function forExecutor(DepartmentExecutor $exec, Carbon $date): ?KaryawanSchedule
    {
        $karyawanId = $exec->effectiveKaryawanId();
        if (!$karyawanId) return null;
        return $this->forKaryawan($karyawanId, $date);
    }

    public function hasCheckedIn(int $karyawanId, Carbon $date): ?Carbon
    {
        $scan = FingerprintLog::where('karyawan_id', $karyawanId)
            ->where('verify_type', 'check_in')
            ->whereDate('scan_at', $date->toDateString())
            ->orderBy('scan_at')
            ->first();
        return $scan ? Carbon::parse($scan->scan_at) : null;
    }

    public function hasCheckedOut(int $karyawanId, Carbon $date): ?Carbon
    {
        $scan = FingerprintLog::where('karyawan_id', $karyawanId)
            ->where('verify_type', 'check_out')
            ->whereDate('scan_at', $date->toDateString())
            ->orderByDesc('scan_at')
            ->first();
        return $scan ? Carbon::parse($scan->scan_at) : null;
    }

    public function hasOvertimeIn(int $karyawanId, Carbon $date): ?Carbon
    {
        $scan = FingerprintLog::where('karyawan_id', $karyawanId)
            ->where('verify_type', 'overtime_in')
            ->whereDate('scan_at', $date->toDateString())
            ->orderBy('scan_at')
            ->first();
        return $scan ? Carbon::parse($scan->scan_at) : null;
    }

    /**
     * Tentukan status efektif eksekutor pada $now berdasarkan schedule + scan overtime.
     *
     * Return salah satu:
     *   off_day          → karyawan libur
     *   no_schedule      → tidak ada jadwal hari ini
     *   pre_work         → sebelum jam_masuk
     *   in_work          → dalam jam kerja regular (di luar istirahat)
     *   in_break         → sedang istirahat
     *   after_work       → setelah jam_pulang, sebelum lembur
     *   in_overtime      → dalam window lembur (has_lembur + overtimeIn scan tercatat)
     *   after_overtime   → setelah jam_pulang_lembur
     */
    public function currentStatus(?KaryawanSchedule $sched, Carbon $now, ?Carbon $overtimeIn): string
    {
        if (!$sched) return 'no_schedule';
        if ($sched->is_off) return 'off_day';

        $t = $now->format('H:i:s');
        $jamMasuk  = $this->norm($sched->jam_masuk);
        $jamPulang = $this->norm($sched->jam_pulang);

        if (!$jamMasuk || !$jamPulang) return 'no_schedule';

        $istStart = $this->norm($sched->jam_istirahat_start);
        $istEnd   = $this->norm($sched->jam_istirahat_end);

        $inBreak = $istStart && $istEnd && $t >= $istStart && $t < $istEnd;

        if ($t < $jamMasuk) return 'pre_work';

        if ($t >= $jamMasuk && $t < $jamPulang) {
            return $inBreak ? 'in_break' : 'in_work';
        }

        // Setelah jam_pulang → cek lembur
        if ($sched->has_lembur && $overtimeIn) {
            $jamPulangLembur = $this->norm($sched->jam_pulang_lembur);
            if ($jamPulangLembur && $t < $jamPulangLembur) {
                $istLemburStart = $this->norm($sched->jam_istirahat_lembur_start);
                $istLemburEnd   = $this->norm($sched->jam_istirahat_lembur_end);
                $inLemburBreak  = $istLemburStart && $istLemburEnd
                    && $t >= $istLemburStart && $t < $istLemburEnd;
                return $inLemburBreak ? 'in_break' : 'in_overtime';
            }
            return 'after_overtime';
        }

        return 'after_work';
    }

    /**
     * Hitung waktu efektif mulai timer berdasarkan scan check_in vs jam_masuk.
     * Scan sebelum jam_masuk → mulai dihitung dari jam_masuk.
     * Scan setelah jam_masuk → mulai dihitung dari scan_at.
     */
    public function effectiveStartTime(?KaryawanSchedule $sched, Carbon $checkInScan): Carbon
    {
        if (!$sched || !$sched->jam_masuk) return $checkInScan->copy();

        $jamMasukDt = Carbon::parse($checkInScan->toDateString() . ' ' . $this->norm($sched->jam_masuk));
        return $checkInScan->lt($jamMasukDt) ? $jamMasukDt : $checkInScan->copy();
    }

    /**
     * Normalize TIME field: "08:00" → "08:00:00", null → null, Carbon → "HH:MM:SS".
     */
    private function norm($val): ?string
    {
        if ($val === null || $val === '') return null;
        if ($val instanceof \DateTimeInterface) return $val->format('H:i:s');
        $s = (string) $val;
        // Already HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) return $s;
        // HH:MM
        if (preg_match('/^\d{2}:\d{2}$/', $s)) return $s . ':00';
        return $s;
    }
}
