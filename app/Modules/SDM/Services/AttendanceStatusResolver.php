<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\NationalHoliday;
use App\Modules\SDM\Models\Karyawan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Sumber tunggal penentuan status absensi yang DITAMPILKAN (live recompute).
 *
 * Status yang tersimpan di kolom sdm_attendance.status berasal dari
 * AttendanceImportService::determineStatus() yang memakai ambang GLOBAL
 * (Kebijakan jam_kerja_selesai default 16:00, tanpa toleransi pulang-cepat
 * per-jadwal) dan tidak tahu soal tukar hari / penyesuaian jam. Itu membuat
 * nilai tersimpan bisa berbeda dengan apa yang dilihat HRD di dashboard.
 *
 * Dashboard admin (AttendanceController) dan PWA karyawan (Me\AbsensiController)
 * WAJIB memakai resolver ini agar status yang dilihat keduanya identik.
 */
class AttendanceStatusResolver
{
    /**
     * Hitung status tampilan untuk satu baris.
     *
     * $row butuh kunci: attendance (?Attendance), schedule (?KaryawanSchedule),
     * is_off (bool), is_holiday (bool), overrides (Collection<AttendanceOverride>).
     */
    public function resolve(array $row, ?string $on1, ?string $off1, bool $respectManual = true): ?string
    {
        $att   = $row['attendance'] ?? null;
        $sched = $row['schedule'] ?? null;
        $isFullSwap = $this->hasFullDaySwap($row);
        $isHalfSwap = $this->hasHalfDaySwap($row);

        // Manual override (HRD pilih dari dropdown) — paling prioritas.
        // Saat menghitung status OTOMATIS utk disimpan ($respectManual=false),
        // lewati cabang ini supaya yang diukur murni scan + jadwal + override jam.
        if ($respectManual && $att && $att->edited_manually && $att->status) {
            return $att->status;
        }

        // Tukar ½ Hari: hari kerja → 'hadir' (kredit penuh di sini).
        // Hari libur → 1 sisi scan consumed swap = 'libur'; 2 sisi = sisanya 'lembur_setengah_hari'.
        if ($isHalfSwap) {
            if (! ($row['is_off'] ?? false) && ! ($row['is_holiday'] ?? false)) {
                return 'hadir';
            }
            $sides = ($on1 ? 1 : 0) + ($off1 ? 1 : 0);
            return $sides >= 2 ? 'lembur_setengah_hari' : 'libur';
        }

        // Tukar Hari (full): hari kerja → 'libur'; hari libur → fall-through ke scan biasa
        if ($isFullSwap && ! ($row['is_off'] ?? false) && ! ($row['is_holiday'] ?? false)) {
            return 'libur';
        }

        // Hari libur (off jadwal atau libur nasional) — kecuali ditukar via tukar_hari
        if ((($row['is_holiday'] ?? false) || ($row['is_off'] ?? false)) && ! $isFullSwap) {
            if (! $on1 && ! $off1) return 'libur';
            $sides = ($on1 ? 1 : 0) + ($off1 ? 1 : 0);
            return $sides === 1 ? 'lembur_setengah_hari' : 'lembur';
        }

        // Status MURNI dari scan — izin/tukar/cuti tidak mengubah status di sini, hanya tampil sebagai badge audit
        if (! $on1 && ! $off1) {
            return null;
        }

        if (! $on1 || ! $off1) {
            return 'setengah_hari';
        }

        // Penyesuaian Jam — kalau ada override jam, pakai sebagai patokan Terlambat/Pulang Awal hari itu
        $overrides = $row['overrides'] ?? collect();
        $penyJam = $overrides->firstWhere('type', 'penyesuaian_jam');

        $jamMasuk  = $penyJam?->jam_masuk_override
            ? substr($penyJam->jam_masuk_override, 0, 5)
            : ($sched?->jam_masuk ? substr($sched->jam_masuk, 0, 5) : '08:00');
        $jamPulang = $penyJam?->jam_pulang_override
            ? substr($penyJam->jam_pulang_override, 0, 5)
            : ($sched?->jam_pulang ? substr($sched->jam_pulang, 0, 5) : '16:00');
        $lateTol   = (int) ($sched?->late_in_minutes ?? 10);
        $earlyTol  = (int) ($sched?->early_out_minutes ?? 0);

        $masukMin  = $this->toMinutes($jamMasuk);
        $pulangMin = $this->toMinutes($jamPulang);
        $on1Min    = $this->toMinutes($on1);
        $off1Min   = $this->toMinutes($off1);

        // Telat lebih dari 2.5 jam (150 menit) = setengah hari
        if ($on1Min - $masukMin > 150)    return 'setengah_hari';
        if ($pulangMin - $off1Min > 120)  return 'setengah_hari';

        $isLate  = ($on1Min - $masukMin) > $lateTol;
        $isEarly = ($pulangMin - $off1Min) > $earlyTol;

        if ($isLate && $isEarly) return 'pulang_awal';
        if ($isLate)             return 'terlambat';
        if ($isEarly)            return 'pulang_awal';

        return 'hadir';
    }

    public function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, 0);
        return ((int) $h) * 60 + (int) $m;
    }

    public function hasFullDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn($o) => $o->type === 'tukar_hari');
    }

    public function hasHalfDaySwap(array $row): bool
    {
        $overrides = $row['overrides'] ?? collect();
        return $overrides->contains(fn($o) => $o->type === 'tukar_setengah_hari');
    }

    /**
     * Status OTOMATIS untuk DISIMPAN ke kolom sdm_attendance.status, dihitung dari
     * jadwal per-karyawan + override jam hari itu (BUKAN ambang global). Dipanggil
     * dari semua jalur tulis absensi (import Excel, ADMS fingerprint, edit manual,
     * command cleanup) supaya kolom tersimpan = apa yang dilihat HRD & PWA.
     *
     * Mengabaikan override manual (respectManual=false): ini SUMBER nilai auto-nya.
     * Null (hari kerja tanpa scan sama sekali) → 'tidak_hadir' agar setara perilaku
     * lama determineStatus().
     */
    public function autoStatus(Karyawan $karyawan, Carbon $tanggal, ?string $on1, ?string $off1): string
    {
        $row = $this->buildRow($karyawan, $tanggal, null);

        $status = $this->resolve(
            $row,
            $on1 ? substr($on1, 0, 5) : null,
            $off1 ? substr($off1, 0, 5) : null,
            false,
        );

        return $status ?? 'tidak_hadir';
    }

    /**
     * Bangun konteks satu tanggal (jadwal, libur, override) untuk seorang karyawan.
     */
    private function buildRow(Karyawan $karyawan, Carbon $tanggal, ?Attendance $att): array
    {
        $dow   = (int) $tanggal->dayOfWeek;
        $sched = $karyawan->schedules->keyBy('day_of_week')[$dow] ?? null;
        $isOff = $sched ? (bool) $sched->is_off : ($dow === 0);

        $isHoli = NationalHoliday::whereDate('tanggal', $tanggal->toDateString())->exists();

        $dateStr = $tanggal->toDateString();
        $overrides = AttendanceOverride::where('karyawan_id', $karyawan->id)
            ->where(function ($q) use ($dateStr) {
                $q->whereDate('tanggal', $dateStr)
                  ->orWhereDate('paired_date', $dateStr);
            })
            ->get();

        return [
            'attendance' => $att,
            'schedule'   => $sched,
            'is_off'     => $isOff,
            'is_holiday' => $isHoli,
            'overrides'  => $overrides,
        ];
    }

    /**
     * Bangun status tampilan untuk semua baris absensi seorang karyawan dalam
     * rentang tanggal, di-key per tanggal (Y-m-d). Dipakai PWA yang hanya
     * pegang koleksi Attendance mentah dan butuh status yang sama dgn dashboard.
     *
     * @return array<string,?string>  tanggal => status tampilan
     */
    public function resolveForRange(Karyawan $karyawan, Carbon $startDate, Carbon $endDate, Collection $attendances): array
    {
        $schedule = $karyawan->schedules->keyBy('day_of_week');

        $holidayDates = NationalHoliday::whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
            ->pluck('tanggal')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->all();

        $overridesRaw = AttendanceOverride::where('karyawan_id', $karyawan->id)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('tanggal', [$startDate->toDateString(), $endDate->toDateString()])
                  ->orWhereBetween('paired_date', [$startDate->toDateString(), $endDate->toDateString()]);
            })
            ->get();

        $overridesByDate = [];
        foreach ($overridesRaw as $o) {
            $tStr = Carbon::parse($o->tanggal)->toDateString();
            $overridesByDate[$tStr][] = $o;
            if ($o->paired_date) {
                $pStr = Carbon::parse($o->paired_date)->toDateString();
                $overridesByDate[$pStr][] = $o;
            }
        }

        $attByDate = $attendances->keyBy(fn($a) => Carbon::parse($a->tanggal)->toDateString());

        $out = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dateStr = $cursor->toDateString();
            $dow     = (int) $cursor->dayOfWeek;
            $sched   = $schedule[$dow] ?? null;
            $isOff   = $sched ? (bool) $sched->is_off : ($dow === 0);
            $isHoli  = in_array($dateStr, $holidayDates, true);
            $att     = $attByDate[$dateStr] ?? null;

            $row = [
                'attendance' => $att,
                'schedule'   => $sched,
                'is_off'     => $isOff,
                'is_holiday' => $isHoli,
                'overrides'  => collect($overridesByDate[$dateStr] ?? []),
            ];

            $on1 = $att?->on_work1 ? substr($att->on_work1, 0, 5) : null;
            $off1 = $att?->off_work1 ? substr($att->off_work1, 0, 5) : null;

            $out[$dateStr] = $this->resolve($row, $on1, $off1);
            $cursor->addDay();
        }

        return $out;
    }
}
