<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\SDM\Models\FingerprintLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionTimerSync
{
    public function __construct(private ExecutorScheduleResolver $resolver) {}

    /**
     * Dipanggil dari Observer FingerprintLog::created.
     * Trigger cascade ke parent → semua descendant executor.
     */
    public function onFingerprintScan(FingerprintLog $log): void
    {
        if (!$log->karyawan_id) return;

        $now = Carbon::parse($log->scan_at);
        $karyawanId = (int) $log->karyawan_id;

        switch ($log->verify_type) {
            case 'check_in':
            case 'overtime_in':
                $this->activateExecutorsForKaryawan($karyawanId, $now, $log->verify_type);
                break;

            case 'check_out':
                $this->pauseExecutorsForKaryawan($karyawanId, $now, 'Scan pulang');
                break;
        }
    }

    /**
     * Dipanggil dari command per menit.
     * Rekonsiliasi: untuk semua step active/paused dengan executor, evaluasi kembali status.
     */
    public function tick(Carbon $now): array
    {
        // Mode testing: tidak ada scan sidik jari, sehingga rekonsiliasi jadwal akan
        // menganggap semua eksekutor "tidak aktif" lalu langsung auto-pause task. Lewati
        // total agar task tetap berjalan saat uji coba.
        if (\App\Models\ProductionSetting::isTestingMode()) {
            return ['paused' => 0, 'resumed' => 0, 'skipped' => 0, 'testing' => true];
        }

        $paused = 0;
        $resumed = 0;
        $skipped = 0;

        $steps = ProductionOrderStep::with(['executors', 'executorStatuses'])
            ->whereIn('status', ['in_progress', 'paused'])
            ->whereHas('executors')
            ->get();

        foreach ($steps as $step) {
            $this->ensureExecutorStatusRows($step);

            foreach ($step->executors as $exec) {
                $karyawanId = $exec->effectiveKaryawanId();
                if (!$karyawanId) { $skipped++; continue; }

                $sched = $this->resolver->forKaryawan($karyawanId, $now);
                $checkIn = $this->resolver->hasCheckedIn($karyawanId, $now);
                $checkOut = $this->resolver->hasCheckedOut($karyawanId, $now);
                $overtimeIn = $this->resolver->hasOvertimeIn($karyawanId, $now);
                $status = $this->resolver->currentStatus($sched, $now, $overtimeIn);

                $shouldBeActive = $checkIn
                    && (!$checkOut || ($overtimeIn && $checkOut->lt($overtimeIn)))
                    && in_array($status, ['in_work', 'in_overtime'], true);

                $reason = $this->describeReason($status, $checkIn, $checkOut, $overtimeIn, $sched);

                $execStatus = $step->executorStatuses->firstWhere('executor_id', $exec->id);
                if (!$execStatus) continue;

                if ($execStatus->is_active && !$shouldBeActive) {
                    // Skip jika sebelumnya manual-pause
                    if ($execStatus->paused_reason === 'manual') { continue; }
                    $execStatus->update([
                        'is_active'     => false,
                        'paused_at'     => $now,
                        'paused_reason' => $reason,
                    ]);
                } elseif (!$execStatus->is_active && $shouldBeActive) {
                    // Jangan auto-resume jika manual-pause
                    if ($execStatus->paused_reason === 'manual') { continue; }
                    $execStatus->update([
                        'is_active'     => true,
                        'paused_at'     => null,
                        'paused_reason' => null,
                    ]);
                }
            }

            // Rekonsiliasi status step dari aggregate executor status
            $step->load('executorStatuses');
            $anyActive = $step->executorStatuses->where('is_active', true)->isNotEmpty();
            $lastLog = $this->lastTimerLog($step->id);
            $isManualPaused = $step->status === 'paused' && $lastLog && $lastLog->event_type === 'paused';

            if ($anyActive && $step->status === 'paused' && !$isManualPaused) {
                $this->logResume($step, $now, 'Rekonsiliasi tick — eksekutor aktif');
                $resumed++;
            } elseif (!$anyActive && $step->status === 'in_progress') {
                $this->logPause($step, $now, 'Semua eksekutor tidak aktif');
                $paused++;
            }
        }

        return compact('paused', 'resumed', 'skipped');
    }

    /**
     * Set semua executor (karyawan ini + descendant) jadi active.
     * Juga update started_effective_at step bila belum di-set hari itu.
     */
    private function activateExecutorsForKaryawan(int $karyawanId, Carbon $now, string $kind): void
    {
        $sched = $this->resolver->forKaryawan($karyawanId, $now);
        if (!$sched) return;
        if ($sched->is_off) return;

        // Resolve daftar executor yang terdampak: yang karyawan_id-nya = karyawan ini,
        // ATAU semua descendant dari executor itu.
        $executorIds = $this->collectExecutorIdsCascade($karyawanId);
        if (empty($executorIds)) return;

        DB::transaction(function () use ($executorIds, $karyawanId, $now, $sched, $kind) {
            $statuses = ProductionStepExecutorStatus::with('step')
                ->whereIn('executor_id', $executorIds)
                ->get();

            $checkInTime = $kind === 'check_in'
                ? $this->resolver->hasCheckedIn($karyawanId, $now)
                : $this->resolver->hasOvertimeIn($karyawanId, $now);

            $effectiveStart = $checkInTime
                ? $this->resolver->effectiveStartTime($sched, $checkInTime)
                : $now;

            foreach ($statuses as $st) {
                if ($st->paused_reason === 'manual') continue;
                if (!$st->is_active) {
                    $st->update([
                        'is_active'     => true,
                        'paused_at'     => null,
                        'paused_reason' => null,
                    ]);
                }

                // Set started_effective_at step jika status step paused dan belum di-set hari ini
                $step = $st->step;
                if (!$step) continue;

                $needsEffective = !$step->started_effective_at
                    || !Carbon::parse($step->started_effective_at)->isSameDay($now);
                if ($needsEffective) {
                    $step->update(['started_effective_at' => $effectiveStart]);
                }

                if ($step->status === 'paused') {
                    $lastLog = $this->lastTimerLog($step->id);
                    if ($lastLog && $lastLog->event_type === 'paused') continue; // manual pause
                    $this->logResume($step, $now, "Scan {$kind}");
                }
            }
        });
    }

    private function pauseExecutorsForKaryawan(int $karyawanId, Carbon $now, string $reason): void
    {
        $executorIds = $this->collectExecutorIdsCascade($karyawanId);
        if (empty($executorIds)) return;

        DB::transaction(function () use ($executorIds, $now, $reason) {
            $statuses = ProductionStepExecutorStatus::with('step.executorStatuses')
                ->whereIn('executor_id', $executorIds)
                ->where('is_active', true)
                ->get();

            foreach ($statuses as $st) {
                $st->update([
                    'is_active'     => false,
                    'paused_at'     => $now,
                    'paused_reason' => $reason,
                ]);
            }

            // Untuk step yang semua executor-nya tidak aktif → auto-pause
            $stepIds = $statuses->pluck('step_id')->unique();
            foreach ($stepIds as $stepId) {
                $step = ProductionOrderStep::with('executorStatuses')->find($stepId);
                if (!$step || $step->status !== 'in_progress') continue;
                $anyActive = $step->executorStatuses->where('is_active', true)->isNotEmpty();
                if (!$anyActive) {
                    $this->logPause($step, $now, $reason);
                }
            }
        });
    }

    /**
     * Kumpulkan ID executor terdampak saat karyawan tertentu scan.
     * Termasuk: executor dengan karyawan_id = karyawan ini, plus semua descendant-nya.
     */
    private function collectExecutorIdsCascade(int $karyawanId): array
    {
        $directIds = DepartmentExecutor::where('karyawan_id', $karyawanId)->pluck('id')->toArray();
        $all = $directIds;
        foreach ($directIds as $id) {
            $exec = DepartmentExecutor::find($id);
            if ($exec) {
                $all = array_merge($all, $exec->descendantIds());
            }
        }
        return array_values(array_unique(array_map('intval', $all)));
    }

    private function ensureExecutorStatusRows(ProductionOrderStep $step): void
    {
        $existingExecIds = $step->executorStatuses->pluck('executor_id')->toArray();
        foreach ($step->executors as $exec) {
            if (in_array($exec->id, $existingExecIds, true)) continue;
            ProductionStepExecutorStatus::create([
                'step_id'     => $step->id,
                'executor_id' => $exec->id,
                'karyawan_id' => $exec->effectiveKaryawanId(),
                'is_active'   => $step->status === 'in_progress',
            ]);
        }
    }

    private function lastTimerLog(int $stepId): ?ProductionStepTimeLog
    {
        return ProductionStepTimeLog::where('production_order_step_id', $stepId)
            ->orderByDesc('id')->first();
    }

    private function logPause(ProductionOrderStep $step, Carbon $now, string $reason): void
    {
        $last = $this->lastTimerLog($step->id);
        if ($last && $last->event_type === 'auto_paused') return;

        $step->update(['status' => 'paused', 'paused_at' => $now]);
        ProductionStepTimeLog::create([
            'production_order_step_id' => $step->id,
            'event_type'               => 'auto_paused',
            'occurred_at'              => $now,
            'notes'                    => $reason,
        ]);
    }

    private function logResume(ProductionOrderStep $step, Carbon $now, string $reason): void
    {
        $step->update(['status' => 'in_progress', 'paused_at' => null]);
        ProductionStepTimeLog::create([
            'production_order_step_id' => $step->id,
            'event_type'               => 'auto_resumed',
            'occurred_at'              => $now,
            'notes'                    => $reason,
        ]);
    }

    private function describeReason(string $status, ?Carbon $checkIn, ?Carbon $checkOut, ?Carbon $overtimeIn, $sched): string
    {
        if (!$checkIn) return 'Belum scan check-in';
        if ($checkOut && (!$overtimeIn || $checkOut->gt($overtimeIn))) return 'Sudah scan pulang';
        return match ($status) {
            'in_break'       => 'Jam istirahat',
            'pre_work'       => 'Sebelum jam kerja',
            'after_work'     => 'Setelah jam pulang',
            'after_overtime' => 'Setelah jam pulang lembur',
            'off_day'        => 'Hari libur karyawan',
            'no_schedule'    => 'Tidak ada jadwal hari ini',
            default          => 'Di luar jam kerja',
        };
    }
}
