<?php

namespace App\Console\Commands;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Services\AttendanceImportService;
use Illuminate\Console\Command;

/**
 * Bersihkan jam absensi hasil FABRIKASI auto-attendance lama (AutoAttendanceService) yang
 * TIDAK didukung scan fingerprint asli, lalu hitung ulang status/flag dari log saja.
 *
 * Sentinel auto-inject: on_work1=08:00:00, off_work1=16:00:00, on_work2=16:30:00, off_work2=20:00:00.
 * Sebuah slot dianggap fabrikasi bila: nilainya = sentinel DAN tidak ada fingerprint log (terhubung
 * ke baris absensi itu) yang jatuh di window slot tsb. Baris edited_manually dilewati.
 *
 * Default DRY-RUN. Pakai --apply untuk benar-benar menulis. Batasi dengan --from/--to (YYYY-MM-DD).
 */
class CleanFabricatedAttendance extends Command
{
    protected $signature = 'sdm:clean-fabricated-attendance
        {--from= : Tanggal mulai (YYYY-MM-DD), default semua}
        {--to= : Tanggal akhir (YYYY-MM-DD), default semua}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Null-kan jam absensi fabrikasi auto-attendance yang tak didukung log fingerprint, lalu recompute status.';

    /** Sentinel jam yang dipakai AutoAttendanceService saat fabrikasi. */
    private const SENTINEL = [
        'on_work1'  => '08:00:00',
        'off_work1' => '16:00:00',
        'on_work2'  => '16:30:00',
        'off_work2' => '20:00:00',
    ];

    public function handle(AttendanceImportService $importer): int
    {
        $apply = (bool) $this->option('apply');
        $from  = $this->option('from');
        $to    = $this->option('to');

        $q = Attendance::query()->where('edited_manually', false);
        if ($from) $q->whereDate('tanggal', '>=', $from);
        if ($to)   $q->whereDate('tanggal', '<=', $to);

        $rows = $q->with('karyawan')->orderBy('tanggal')->get();
        $this->info(($apply ? '[APPLY] ' : '[DRY-RUN] ') . "Memeriksa {$rows->count()} baris absensi...");

        $changed = 0; $cleared = 0;

        foreach ($rows as $att) {
            // Window jam dari log yang TERHUBUNG ke baris ini (per heuristik mergeIntoAttendance).
            $logTimes = FingerprintLog::where('attendance_id', $att->id)
                ->get(['scan_at'])
                ->map(fn($l) => $l->scan_at->format('H:i:s'));

            $hasMorning = $logTimes->contains(fn($t) => $t < '11:30:00');                       // on_work1
            $hasMidDay  = $logTimes->contains(fn($t) => $t >= '11:30:00' && $t < '16:30:00');    // off_work1
            $hasEvening = $logTimes->contains(fn($t) => $t >= '16:30:00');                       // off_work2 / on_work2

            $support = [
                'on_work1'  => $hasMorning,
                'off_work1' => $hasMidDay,
                'on_work2'  => $hasEvening,
                'off_work2' => $hasEvening,
            ];

            $clears = [];
            foreach (self::SENTINEL as $field => $sentinel) {
                if ((string) $att->$field === $sentinel && ! $support[$field]) {
                    $clears[] = $field;
                }
            }

            if (! $clears) continue;

            $this->line(sprintf(
                "  %s  k#%-3d  hapus: %s",
                $att->tanggal instanceof \DateTimeInterface ? $att->tanggal->format('Y-m-d') : $att->tanggal,
                $att->karyawan_id,
                implode(', ', array_map(fn($f) => "$f({$att->$f})", $clears))
            ));

            foreach ($clears as $f) { $att->$f = null; $cleared++; }

            // Recompute status & flag dari sisa jam (log-only) — sama seperti mergeIntoAttendance.
            $overtimeAllowed = $att->karyawan?->isOvertimeAllowedOn($att->tanggal instanceof \DateTimeInterface ? $att->tanggal : \Carbon\Carbon::parse($att->tanggal)) ?? false;
            $status = $importer->determineStatus($att->on_work1, $att->off_work1, $att->week);
            $flags  = $importer->applyPayFlags($status, $att->on_work1);
            $att->status          = $status;
            $att->overtime_hours  = $importer->calculateOvertime($att->off_work2, $overtimeAllowed);
            $att->get_full_pay    = $flags['full'];
            $att->get_half_pay    = $flags['half'];
            $att->get_tunjangan   = $flags['tunj'];
            $att->get_bonus_absen = $flags['bonus_absen'];

            if ($apply) $att->save();
            $changed++;
        }

        $this->newLine();
        $this->info(($apply ? 'Selesai. ' : 'DRY-RUN. ') . "{$changed} baris terpengaruh, {$cleared} slot jam di-null-kan.");
        if (! $apply && $changed > 0) {
            $this->warn('Jalankan lagi dengan --apply untuk menyimpan perubahan.');
        }
        return self::SUCCESS;
    }
}
