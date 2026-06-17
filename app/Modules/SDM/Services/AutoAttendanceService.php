<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\AttendanceOverride;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\PeriodePenggajian;
use Carbon\Carbon;

/**
 * ⚠️ TIDAK LAGI TERJADWAL sejak 2026-06-17 (lihat routes/console.php).
 * Jam absensi WAJIB 100% dari log fingerprint asli. Memfabrikasi jam masuk/pulang/lembur
 * membuat absensi tidak sesuai log (mis. "pulang 16:00" di hari libur tanpa scan).
 * Kelas dipertahankan utk referensi/utilitas manual; JANGAN dijadwalkan ulang.
 *
 * Auto-inject scan untuk semua karyawan aktif berdasarkan jadwal:
 *  - Batch CHECK_IN  (08:00) → jalan jam 08:30 oleh scheduler
 *  - Batch CHECK_OUT (16:00) → jalan jam 16:30
 *  - Batch LEMBUR    (20:00) → jalan jam 20:30, hanya untuk karyawan yang dijadwalkan lembur
 *
 * Karyawan yang punya AttendanceOverride untuk tanggal tsb akan di-skip / di-handle khusus:
 *  - cuti     → semua batch skip, attendance row di-create dengan status cuti
 *  - sakit    → semua batch skip, attendance row di-create dengan status sakit
 *  - izin_pagi → batch CHECK_IN skip
 *  - izin_sore → batch CHECK_OUT skip
 *  - lembur   → batch LEMBUR di-enable (default: tidak inject lembur)
 */
class AutoAttendanceService
{
    public function __construct(protected AttendanceImportService $importer) {}

    public const SLOTS = [
        'check_in'  => ['time' => '08:00', 'label' => 'Check In Pagi'],
        'check_out' => ['time' => '16:00', 'label' => 'Check Out Sore'],
        'lembur'    => ['time' => '20:00', 'label' => 'Check Out Lembur'],
    ];

    /**
     * Run batch untuk slot tertentu di hari ini.
     *
     * @return array{slot:string, date:string, injected:int, skipped_izin:int, skipped_no_periode:bool, skipped_sunday:bool, sakit_cuti:int}
     */
    public function runBatch(string $slot, ?Carbon $date = null): array
    {
        $date = $date ?? today();
        $result = [
            'slot'                => $slot,
            'date'                => $date->toDateString(),
            'injected'            => 0,
            'skipped_izin'        => 0,
            'sakit_cuti'          => 0,
            'skipped_no_periode'  => false,
            'skipped_sunday'      => false,
        ];

        if (! isset(self::SLOTS[$slot])) {
            throw new \InvalidArgumentException("Unknown slot: {$slot}");
        }

        // Hari Minggu = libur, skip
        if ($date->isSunday()) {
            $result['skipped_sunday'] = true;
            return $result;
        }

        // Periode aktif untuk tanggal ini
        $periode = PeriodePenggajian::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if (! $periode) {
            $result['skipped_no_periode'] = true;
            return $result;
        }

        $time = self::SLOTS[$slot]['time'];
        $karyawans = Karyawan::where('is_active', true)->get();

        foreach ($karyawans as $k) {
            $action = $this->determineAction($slot, $k->id, $date);

            if ($action === 'skip') {
                $result['skipped_izin']++;
                continue;
            }

            if ($action === 'sakit' || $action === 'cuti') {
                $this->createOrUpdateAbsentRow($periode, $k, $date, $action);
                $result['sakit_cuti']++;
                continue;
            }

            // action = 'inject'
            $this->injectScan($periode, $k, $date, $time, $slot);
            $result['injected']++;
        }

        return $result;
    }

    /**
     * Tentukan action untuk karyawan tertentu di slot ini.
     * Return: 'inject' | 'skip' | 'sakit' | 'cuti'
     */
    protected function determineAction(string $slot, int $karyawanId, Carbon $date): string
    {
        $overrides = AttendanceOverride::where('karyawan_id', $karyawanId)
            ->whereDate('tanggal', $date)
            ->pluck('type')
            ->all();

        // Sakit & cuti dominan — apapun slotnya, skip + create absent row dengan status sesuai
        if (in_array('sakit', $overrides, true)) return 'sakit';
        if (in_array('cuti', $overrides, true))  return 'cuti';

        // Slot-specific
        if ($slot === 'check_in' && in_array('izin_pagi', $overrides, true)) return 'skip';
        if ($slot === 'check_out' && in_array('izin_sore', $overrides, true)) return 'skip';

        if ($slot === 'lembur') {
            // Lembur hanya inject kalau ADA override 'lembur'
            return in_array('lembur', $overrides, true) ? 'inject' : 'skip';
        }

        return 'inject';
    }

    /**
     * Create / update Attendance row dengan timestamp scan.
     * Re-compute status & flags via importer logic.
     */
    protected function injectScan(PeriodePenggajian $periode, Karyawan $karyawan, Carbon $date, string $time, string $slot): Attendance
    {
        $att = Attendance::firstOrNew([
            'periode_id'  => $periode->id,
            'karyawan_id' => $karyawan->id,
            'tanggal'     => $date->toDateString(),
        ]);

        if ($att->exists && $att->edited_manually) {
            return $att; // jangan timpa edit manual
        }

        $timeWithSec = $time . ':00';

        // Set field berdasarkan slot
        if ($slot === 'check_in') {
            if (! $att->on_work1) $att->on_work1 = $timeWithSec;
        } elseif ($slot === 'check_out') {
            if (! $att->off_work1) $att->off_work1 = $timeWithSec;
        } elseif ($slot === 'lembur') {
            if (! $att->on_work2) $att->on_work2 = '16:30:00';
            if (! $att->off_work2 || $timeWithSec > $att->off_work2) {
                $att->off_work2 = $timeWithSec;
            }
        }

        if (! $att->week) {
            $att->week = $date->format('D');
        }

        // Recompute status & flags
        $overtimeAllowed = $karyawan->isOvertimeAllowedOn($date);
        $status = $this->importer->determineStatus($att->on_work1, $att->off_work1, $att->week);
        $flags = $this->importer->applyPayFlags($status, $att->on_work1);

        $att->status = $status;
        $att->overtime_hours = $this->importer->calculateOvertime($att->off_work2, $overtimeAllowed);
        $att->get_full_pay = $flags['full'];
        $att->get_half_pay = $flags['half'];
        $att->get_tunjangan = $flags['tunj'];
        $att->get_bonus_absen = $flags['bonus_absen'];

        $att->save();

        return $att;
    }

    /**
     * Untuk sakit/cuti: create attendance row tanpa scan, status = type.
     * Sakit dapat full pay (tanpa tunjangan), Cuti dapat full pay + tunjangan.
     */
    protected function createOrUpdateAbsentRow(PeriodePenggajian $periode, Karyawan $karyawan, Carbon $date, string $type): Attendance
    {
        $att = Attendance::firstOrNew([
            'periode_id'  => $periode->id,
            'karyawan_id' => $karyawan->id,
            'tanggal'     => $date->toDateString(),
        ]);

        if ($att->exists && $att->edited_manually) {
            return $att;
        }

        $att->status = $type; // 'sakit' or 'cuti'
        $att->week = $att->week ?: $date->format('D');

        $flags = $this->importer->applyPayFlags($type, null);
        $att->get_full_pay = $flags['full'];
        $att->get_half_pay = $flags['half'];
        $att->get_tunjangan = $flags['tunj'];
        $att->get_bonus_absen = false;
        $att->overtime_hours = 0;

        $att->save();

        return $att;
    }

    /**
     * Run semua 3 batch sekaligus (untuk testing manual).
     */
    public function runAllBatchesForDate(?Carbon $date = null): array
    {
        return [
            'check_in'  => $this->runBatch('check_in', $date),
            'check_out' => $this->runBatch('check_out', $date),
            'lembur'    => $this->runBatch('lembur', $date),
        ];
    }
}
