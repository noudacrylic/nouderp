<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\Attendance;
use App\Modules\SDM\Models\FingerprintCommand;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\FingerprintMachine;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\PeriodePenggajian;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles ZKTeco-compatible ADMS push protocol.
 *
 * Endpoint flow:
 *  1. Device GET /iclock/cdata?SN=xxx&options=all  → server returns config text
 *  2. Device POST /iclock/cdata?SN=xxx&table=ATTLOG → server saves logs, returns "OK: N"
 *  3. Device POST /iclock/cdata?SN=xxx&table=OPERLOG → operation logs
 *  4. Device GET /iclock/getrequest?SN=xxx → returns pending commands
 *  5. Device POST /iclock/devicecmd?SN=xxx → command result acknowledgement
 */
class AdmsService
{
    public function __construct(protected AttendanceImportService $importer) {}

    public function findMachineBySerial(string $sn): ?FingerprintMachine
    {
        return FingerprintMachine::where('serial_number', $sn)->where('is_active', true)->first();
    }

    public function touchSeen(FingerprintMachine $machine): void
    {
        $machine->update(['last_seen_at' => now()]);
    }

    /**
     * Build response untuk handshake awal device (GET /iclock/cdata).
     */
    public function buildHandshakeConfig(FingerprintMachine $machine): string
    {
        $sn = $machine->serial_number;
        return implode("\r\n", [
            "GET OPTION FROM: {$sn}",
            "ATTLOGStamp=None",
            "OPERLOGStamp=9999",
            "ATTPHOTOStamp=None",
            "ErrorDelay=30",
            "Delay=10",
            "TransTimes=00:00;14:05",
            "TransInterval=1",
            "TransFlag=TransData AttLog OpLog AttPhoto EnrollUser ChgUser EnrollFP ChgFP UserPic",
            "TimeZone=7",
            "Realtime=1",
            "Encrypt=None",
            "ServerVer=NoudERP-ADMS-1.0",
        ]) . "\r\n";
    }

    /**
     * Parse ATTLOG payload (raw body dari device).
     * Format per baris: PIN<TAB>Timestamp<TAB>Status<TAB>Verify<TAB>Workcode<TAB>...
     * Returns: count of inserted rows.
     */
    public function ingestAttLog(FingerprintMachine $machine, string $body): int
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body));
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $pin = trim($parts[0]);
            $tsRaw = trim($parts[1]);
            $status = $parts[2] ?? null;
            $verify = $parts[3] ?? null;

            try {
                $scanAt = Carbon::parse($tsRaw);
            } catch (\Throwable $e) {
                Log::warning('ADMS: failed parse timestamp', ['line' => $line, 'err' => $e->getMessage()]);
                continue;
            }

            $karyawan = Karyawan::where('user_id_fingerprint', $pin)->first();

            $log = FingerprintLog::updateOrCreate(
                [
                    'machine_id'          => $machine->id,
                    'user_id_fingerprint' => $pin,
                    'scan_at'             => $scanAt,
                ],
                [
                    'karyawan_id'    => $karyawan?->id,
                    'verify_method'  => $this->mapVerifyMethod($verify),
                    'verify_type'    => $this->mapVerifyType($status),
                    'raw_payload'    => [
                        'line'       => $line,
                        'parts'      => $parts,
                        'parsed_at'  => now()->toIso8601String(),
                    ],
                ]
            );

            // Auto-assign ke periode aktif kalau ketemu
            if ($karyawan) {
                $this->mergeIntoAttendance($log);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Operation log handler — disimpan ke logs table tapi tidak diproses jadi attendance.
     */
    public function ingestOperLog(FingerprintMachine $machine, string $body): int
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($body));
        return count(array_filter($lines, fn($l) => trim($l) !== ''));
    }

    /**
     * Ambil command pending untuk dieksekusi mesin.
     */
    public function pullPendingCommands(FingerprintMachine $machine): string
    {
        $cmds = FingerprintCommand::where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(10)
            ->get();

        if ($cmds->isEmpty()) {
            return "OK\r\n";
        }

        $lines = [];
        foreach ($cmds as $c) {
            $lines[] = "C:{$c->id}:{$c->command}";
            $c->update(['status' => 'sent', 'sent_at' => now()]);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Acknowledge command execution dari mesin.
     * Body format: ID=xxx&Return=0&CMD=...
     */
    public function ackCommand(FingerprintMachine $machine, string $body): void
    {
        // Format URL-encoded
        parse_str(str_replace('&', '&', $body), $parsed);
        $id = $parsed['ID'] ?? null;
        $return = $parsed['Return'] ?? null;
        if (! $id) return;

        $cmd = FingerprintCommand::where('machine_id', $machine->id)->find((int) $id);
        if (! $cmd) return;

        $status = ((string) $return === '0') ? 'executed' : 'failed';
        $cmd->update([
            'status'      => $status,
            'response'    => $body,
            'executed_at' => now(),
        ]);
    }

    /**
     * Map raw FingerprintLog ke baris Attendance pada periode aktif (status != finalized/void).
     */
    public function mergeIntoAttendance(FingerprintLog $log): ?Attendance
    {
        if (! $log->karyawan_id || $log->processed) return null;

        $tanggal = $log->scan_at->toDateString();

        // Pastikan periode bulan scan tersedia. Kalau belum ada (mis. awal bulan baru,
        // periode belum dibuat manual), buat otomatis sebagai draft supaya scan langsung
        // mengisi absensi — selaras pola auto-create periode akuntansi. Bila periode bulan
        // itu sudah finalized/void, jangan tulis ke periode tertutup → bail.
        $periode = $this->ensurePeriodeForDate($log->scan_at);

        if (! $periode) return null;

        $att = Attendance::firstOrNew([
            'periode_id'  => $periode->id,
            'karyawan_id' => $log->karyawan_id,
            'tanggal'     => $tanggal,
        ]);

        // Skip kalau sudah edited manual
        if ($att->exists && $att->edited_manually) {
            $log->update(['processed' => true, 'processed_at' => now(), 'attendance_id' => $att->id]);
            return $att;
        }

        $time = $log->scan_at->format('H:i:s');

        // Heuristik penempatan slot: pre-12:30 = on_work1, 11:30-13:00 = off/on around lunch, post-16:00 = off_work
        if ($time < '11:30:00') {
            // Datang pagi → on_work1 (ambil yang paling awal)
            if (! $att->on_work1 || $time < $att->on_work1) {
                $att->on_work1 = $time;
            }
        } elseif ($time < '14:00:00') {
            // Sebelum/sesudah istirahat
            if (! $att->off_work1 || $time > $att->off_work1) {
                $att->off_work1 = $time;
            }
        } elseif ($time < '16:30:00') {
            // Pulang regular
            if (! $att->off_work1 || $time > $att->off_work1) {
                $att->off_work1 = $time;
            }
        } else {
            // Lembur
            if (! $att->off_work2 || $time > $att->off_work2) {
                $att->off_work2 = $time;
            }
            if (! $att->on_work2) {
                $att->on_work2 = '16:30:00';
            }
        }

        if (! $att->week) {
            $att->week = $log->scan_at->format('D');
        }

        // Recompute status & overtime
        $karyawan = $log->karyawan;
        $overtimeAllowed = $karyawan->isOvertimeAllowedOn($log->scan_at);
        $status = $this->importer->determineStatus($att->on_work1, $att->off_work1, $att->week);
        $flags = $this->importer->applyPayFlags($status, $att->on_work1);

        $att->status = $status;
        $att->overtime_hours = $this->importer->calculateOvertime($att->off_work2, $overtimeAllowed);
        $att->get_full_pay = $flags['full'];
        $att->get_half_pay = $flags['half'];
        $att->get_tunjangan = $flags['tunj'];
        $att->get_bonus_absen = $flags['bonus_absen'];
        $att->save();

        $log->update([
            'processed'    => true,
            'processed_at' => now(),
            'attendance_id'=> $att->id,
        ]);

        return $att;
    }

    /**
     * Pastikan periode penggajian untuk bulan tanggal scan tersedia.
     * Belum ada → buat draft; finalized/void → null (periode tertutup).
     * Logika dipusatkan di PeriodePenggajianService (dipakai juga cron & tombol manual).
     */
    protected function ensurePeriodeForDate(Carbon $date): ?PeriodePenggajian
    {
        return app(PeriodePenggajianService::class)->ensureForDate($date);
    }

    /**
     * Manual sync: replay all unprocessed logs ke attendance untuk mesin tertentu.
     */
    public function syncAllUnprocessedForMachine(FingerprintMachine $machine): array
    {
        $logs = FingerprintLog::where('machine_id', $machine->id)
            ->where('processed', false)
            ->orderBy('scan_at')
            ->get();

        $matched = 0;
        $unmatched = 0;

        foreach ($logs as $log) {
            if (! $log->karyawan_id) {
                // try re-match
                $k = Karyawan::where('user_id_fingerprint', $log->user_id_fingerprint)->first();
                if ($k) {
                    $log->update(['karyawan_id' => $k->id]);
                }
            }

            if ($log->karyawan_id) {
                $this->mergeIntoAttendance($log);
                $matched++;
            } else {
                $unmatched++;
            }
        }

        $machine->update(['last_sync_at' => now()]);

        return ['matched' => $matched, 'unmatched' => $unmatched, 'total' => $logs->count()];
    }

    protected function mapVerifyMethod(?string $verify): ?string
    {
        return match ((string) $verify) {
            '0' => 'password',
            '1' => 'fingerprint',
            '2' => 'card',
            '15' => 'face',
            default => $verify ?: null,
        };
    }

    protected function mapVerifyType(?string $status): ?string
    {
        return match ((string) $status) {
            '0' => 'check_in',
            '1' => 'check_out',
            '2' => 'break_out',
            '3' => 'break_in',
            '4' => 'overtime_in',
            '5' => 'overtime_out',
            default => $status ?: null,
        };
    }
}
