<?php

namespace App\Console\Commands;

use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Production\Services\ProductionTimerSync;
use App\Modules\SDM\Models\FingerprintLog;
use App\Modules\SDM\Models\FingerprintMachine;
use App\Modules\SDM\Models\Karyawan;
use App\Modules\SDM\Models\KaryawanSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E2E test untuk sistem penyatuan jam kerja produksi.
 *
 * Setup data sandbox (department, karyawan, executor, schedule, step) dengan prefix
 * TEST- agar bisa cleanup di akhir. Pakai Carbon::setTestNow() untuk mock waktu.
 *
 * Run: php artisan test:production-timer-scenarios
 *      php artisan test:production-timer-scenarios --keep   (skip teardown untuk debug)
 */
class TestProductionTimerScenarios extends Command
{
    protected $signature = 'test:production-timer-scenarios {--keep : skip teardown for debugging}';
    protected $description = 'Automated E2E test untuk penyatuan jam kerja produksi (mocked time)';

    private array $created = []; // track created IDs for cleanup
    private array $results = []; // [name, status PASS/FAIL, message]

    public function handle(): int
    {
        $this->info('=== E2E Test: Penyatuan Jam Kerja Produksi ===');
        $this->newLine();

        DB::beginTransaction();

        try {
            $this->setup();
            $this->newLine();
            $this->info('--- Running Scenarios ---');

            $this->t01_no_scan_policy();
            $this->t02_scan_before_jam_masuk();
            $this->t03_scan_after_jam_masuk();
            $this->t04_auto_pause_break();
            $this->t05_auto_resume_after_break();
            $this->t06_auto_pause_end_of_day();
            $this->t07_overtime_auto_start();
            $this->t08_cascade_parent_to_child();
            $this->t09_multi_executor_independent();
            $this->t10_manual_pause_survives_tick();
            $this->t11_off_day();
            $this->t12_drop_old_system();
            $this->t13_circular_parent();
            $this->t14_resolver_status_branches();
            $this->t15_manual_resume_when_machine_fails();
        } catch (\Throwable $e) {
            $this->error('Test setup/run failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
        } finally {
            if (!$this->option('keep')) {
                DB::rollBack();
                $this->newLine();
                $this->info('Teardown: transaction rolled back, no permanent data created.');
            } else {
                DB::commit();
                $this->warn('Test data PERSISTED (--keep flag). Manual cleanup needed.');
                $this->warn('Created: ' . json_encode($this->created));
            }
            Carbon::setTestNow(); // reset
        }

        $this->report();
        return $this->resultsHaveFailure() ? 1 : 0;
    }

    /* ====================== SETUP ====================== */

    private function setup(): void
    {
        $this->line('Setup sandbox data...');

        // Department
        $dept = Department::create([
            'code' => 'TEST-PRD-001',
            'name' => 'TEST Assembling',
            'type' => 'produksi',
            'is_active' => true,
        ]);
        $this->created['dept_id'] = $dept->id;

        // Karyawan utama "Andi"
        $andi = Karyawan::create([
            'staf_code' => 'TEST-EMP-001',
            'name' => 'TEST Andi (Operator)',
            'department_id' => $dept->id,
            'jabatan' => 'Operator',
            'gaji_pokok' => 2610000,
            'tunjangan_pegawai' => 0,
            'mulai_kerja' => '2026-01-01',
            'sanksi' => 'SP0',
            'hak_cuti' => 12,
            'user_id_fingerprint' => 'TEST001',
            'is_active' => true,
        ]);
        $this->created['karyawan_andi_id'] = $andi->id;

        // Karyawan kedua "Budi" untuk multi-executor test
        $budi = Karyawan::create([
            'staf_code' => 'TEST-EMP-002',
            'name' => 'TEST Budi (Operator)',
            'department_id' => $dept->id,
            'jabatan' => 'Operator',
            'gaji_pokok' => 2610000,
            'tunjangan_pegawai' => 0,
            'mulai_kerja' => '2026-01-01',
            'sanksi' => 'SP0',
            'hak_cuti' => 12,
            'user_id_fingerprint' => 'TEST002',
            'is_active' => true,
        ]);
        $this->created['karyawan_budi_id'] = $budi->id;

        // Schedule untuk Andi & Budi: Senin (DOW 1) — kerja 08:00-17:00 break 12:00-13:00, lembur 17:30-20:00
        foreach ([$andi->id, $budi->id] as $kId) {
            for ($dow = 0; $dow <= 6; $dow++) {
                KaryawanSchedule::create([
                    'karyawan_id' => $kId,
                    'day_of_week' => $dow,
                    'jam_masuk' => $dow === 0 ? null : '08:00:00', // Minggu off
                    'jam_pulang' => $dow === 0 ? null : '17:00:00',
                    'jam_istirahat_start' => $dow === 0 ? null : '12:00:00',
                    'jam_istirahat_end' => $dow === 0 ? null : '13:00:00',
                    'is_off' => $dow === 0,
                    'has_lembur' => in_array($dow, [1, 2, 3, 4, 5], true),
                    'jam_masuk_lembur' => '17:30:00',
                    'jam_pulang_lembur' => '20:00:00',
                    'late_in_minutes' => 10,
                    'early_out_minutes' => 0,
                ]);
            }
        }

        // Executor Andi (parent) + Mesin1 child + Mesin2 child standalone (no parent)
        $execAndi = DepartmentExecutor::create([
            'department_id' => $dept->id,
            'karyawan_id' => $andi->id,
            'name' => 'TEST Andi',
            'role' => 'Operator',
            'is_active' => true,
        ]);
        $execBudi = DepartmentExecutor::create([
            'department_id' => $dept->id,
            'karyawan_id' => $budi->id,
            'name' => 'TEST Budi',
            'role' => 'Operator',
            'is_active' => true,
        ]);
        $execMesin1 = DepartmentExecutor::create([
            'department_id' => $dept->id,
            'karyawan_id' => null,
            'parent_executor_id' => $execAndi->id,
            'name' => 'TEST Mesin1 (child Andi)',
            'role' => 'Mesin',
            'is_active' => true,
        ]);
        $execMesin2 = DepartmentExecutor::create([
            'department_id' => $dept->id,
            'karyawan_id' => null,
            'parent_executor_id' => null, // standalone, tidak bisa auto-timer
            'name' => 'TEST Mesin2 (standalone)',
            'role' => 'Mesin',
            'is_active' => true,
        ]);
        $this->created['exec_andi_id'] = $execAndi->id;
        $this->created['exec_budi_id'] = $execBudi->id;
        $this->created['exec_mesin1_id'] = $execMesin1->id;
        $this->created['exec_mesin2_id'] = $execMesin2->id;

        // Fingerprint machine (untuk attach FingerprintLog)
        $machine = FingerprintMachine::create([
            'code' => 'TEST-FP-001',
            'name' => 'TEST Machine',
            'serial_number' => 'TEST-SN-001',
            'ip_address' => '127.0.0.1',
            'port' => 4370,
            'model' => 'TEST',
            'is_active' => true,
        ]);
        $this->created['machine_id'] = $machine->id;

        $this->line('  ✓ Created dept, 2 karyawan, 4 executors (Andi parent, Mesin1 child, Mesin2 standalone), 1 machine.');
    }

    /* ====================== HELPERS ====================== */

    private function freshStep(string $name = 'TEST Step'): ProductionOrderStep
    {
        // Release semua step in_progress yang dipegang test eksekutor agar tidak collision dengan busy-check
        $testExecutorIds = [
            $this->created['exec_andi_id'] ?? 0,
            $this->created['exec_budi_id'] ?? 0,
            $this->created['exec_mesin1_id'] ?? 0,
            $this->created['exec_mesin2_id'] ?? 0,
        ];
        $busyStepIds = DB::table('production_order_step_executors')
            ->join('production_order_steps', 'production_order_steps.id', '=', 'production_order_step_executors.step_id')
            ->whereIn('production_order_steps.status', ['in_progress', 'paused'])
            ->whereIn('production_order_step_executors.executor_id', $testExecutorIds)
            ->pluck('production_order_steps.id')->unique()->toArray();
        if (!empty($busyStepIds)) {
            DB::table('production_order_steps')->whereIn('id', $busyStepIds)
                ->update(['status' => 'completed', 'completed_at' => now()]);
            // Tidak perlu hapus pivot — pivot tetap, tapi step.status='completed' tidak akan trigger busy
            ProductionStepExecutorStatus::whereIn('step_id', $busyStepIds)->update(['is_active' => false]);
        }

        $warehouseId = \App\Core\Inventory\Warehouse::value('id') ?? 1;
        $order = ProductionOrder::create([
            'order_number' => 'TEST-ORD-' . uniqid(),
            'type' => 'ready_stock',
            'warehouse_id' => $warehouseId,
            'planned_cycles' => 1,
            'planned_qty' => 1,
            'production_date' => Carbon::today()->toDateString(),
            'status' => 'confirmed',
            'created_via' => 'test',
        ]);
        return ProductionOrderStep::create([
            'production_order_id' => $order->id,
            'step_number' => 1,
            'department_id' => $this->created['dept_id'],
            'name' => $name,
            'status' => 'pending',
        ]);
    }

    private function clearScans(): void
    {
        FingerprintLog::where('karyawan_id', $this->created['karyawan_andi_id'])->delete();
        FingerprintLog::where('karyawan_id', $this->created['karyawan_budi_id'])->delete();
    }

    /**
     * Insert scan log. Pakai DB::table langsung agar tidak fire Observer
     * (Observer akan dijalankan manual lewat ProductionTimerSync di test).
     * Alternatif: insert via Model::create() agar Observer aktif — ini akan kita pakai untuk T-08.
     */
    private function insertScan(int $karyawanId, string $verifyType, Carbon $at, bool $fireObserver = true): FingerprintLog
    {
        if ($fireObserver) {
            return FingerprintLog::create([
                'machine_id' => $this->created['machine_id'],
                'karyawan_id' => $karyawanId,
                'user_id_fingerprint' => $karyawanId === $this->created['karyawan_andi_id'] ? 'TEST001' : 'TEST002',
                'scan_at' => $at,
                'verify_method' => 'fingerprint',
                'verify_type' => $verifyType,
                'processed' => false,
            ]);
        }

        $id = DB::table('sdm_fingerprint_logs')->insertGetId([
            'machine_id' => $this->created['machine_id'],
            'karyawan_id' => $karyawanId,
            'user_id_fingerprint' => $karyawanId === $this->created['karyawan_andi_id'] ? 'TEST001' : 'TEST002',
            'scan_at' => $at,
            'verify_method' => 'fingerprint',
            'verify_type' => $verifyType,
            'processed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return FingerprintLog::find($id);
    }

    private function setMockTime(string $hms, string $date = '2026-05-25'): Carbon
    {
        // 2026-05-25 = Senin (DOW=1). Cek: PHP echo (new DateTime('2026-05-25'))->format('N') = 1 Senin.
        $c = Carbon::parse($date . ' ' . $hms);
        Carbon::setTestNow($c);
        return $c;
    }

    private function assertEquals($expected, $actual, string $label): bool
    {
        if ($expected === $actual) {
            return true;
        }
        $this->error("    ✗ {$label}: expected " . json_encode($expected) . ", got " . json_encode($actual));
        return false;
    }

    private function assertTrue($cond, string $label): bool
    {
        if ($cond) return true;
        $this->error("    ✗ {$label}: expected TRUE, got FALSE");
        return false;
    }

    private function recordPass(string $name, string $msg = ''): void
    {
        $this->results[] = ['name' => $name, 'status' => 'PASS', 'message' => $msg];
        $this->info("  ✓ {$name}" . ($msg ? " — {$msg}" : ''));
    }

    private function recordFail(string $name, string $msg): void
    {
        $this->results[] = ['name' => $name, 'status' => 'FAIL', 'message' => $msg];
        $this->error("  ✗ {$name} — {$msg}");
    }

    /* ====================== SCENARIOS ====================== */

    private function t01_no_scan_policy(): void
    {
        $this->setMockTime('09:00:00');
        $this->clearScans();
        $step = $this->freshStep('T-01 No Scan');

        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
            $this->recordFail('T-01', 'Expected Exception (belum scan check-in), tapi startStep sukses.');
            return;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'belum scan check-in')) {
                $this->recordPass('T-01', 'No-scan policy: start ditolak dengan pesan yang benar.');
            } else {
                $this->recordFail('T-01', 'Exception salah: ' . $e->getMessage());
            }
        }
    }

    private function t02_scan_before_jam_masuk(): void
    {
        $now = $this->setMockTime('08:30:00');
        $this->clearScans();

        // Andi scan check_in jam 07:45 (sebelum jam_masuk 08:00)
        $scanTime = $this->setMockTime('07:45:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime);

        // Maju ke 08:30, klik Start
        $this->setMockTime('08:30:00');
        $step = $this->freshStep('T-02 Scan Before');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
        } catch (\Exception $e) {
            $this->recordFail('T-02', 'startStep gagal: ' . $e->getMessage());
            return;
        }

        $step->refresh();
        $effective = $step->started_effective_at?->format('H:i:s');
        // Timer task realistis: mulai dihitung sejak klik Mulai (08:30), bukan backdate ke scan/jam_masuk.
        if ($effective === '08:30:00') {
            $this->recordPass('T-02', "started_effective_at = 08:30 (waktu klik Mulai), tidak backdate ke jam_masuk 08:00.");
        } else {
            $this->recordFail('T-02', "Expected 08:30:00, got {$effective}");
        }
    }

    private function t03_scan_after_jam_masuk(): void
    {
        $this->clearScans();

        // Scan 08:15 (setelah jam_masuk 08:00)
        $scanTime = $this->setMockTime('08:15:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime);

        $this->setMockTime('09:00:00');
        $step = $this->freshStep('T-03 Scan After');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
        } catch (\Exception $e) {
            $this->recordFail('T-03', 'startStep gagal: ' . $e->getMessage());
            return;
        }

        $step->refresh();
        $effective = $step->started_effective_at?->format('H:i:s');
        // Timer task realistis: mulai dihitung sejak klik Mulai (09:00), bukan backdate ke scan 08:15.
        if ($effective === '09:00:00') {
            $this->recordPass('T-03', "started_effective_at = 09:00 (waktu klik Mulai), bukan scan time 08:15.");
        } else {
            $this->recordFail('T-03', "Expected 09:00:00, got {$effective}");
        }
    }

    private function t04_auto_pause_break(): void
    {
        $this->clearScans();
        $scanTime = $this->setMockTime('08:00:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime);

        $this->setMockTime('11:55:00');
        $step = $this->freshStep('T-04 Break');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
        } catch (\Exception $e) {
            $this->recordFail('T-04', 'startStep gagal: ' . $e->getMessage());
            return;
        }

        // Maju ke 12:01 (dalam window istirahat 12:00-13:00) dan run tick
        $this->setMockTime('12:01:00');
        app(ProductionTimerSync::class)->tick(now());

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        $ok = $step->status === 'paused' && $lastLog?->event_type === 'auto_paused';
        if ($ok) {
            $this->recordPass('T-04', 'Step auto-paused saat istirahat (reason: ' . ($lastLog->notes ?? '-') . ').');
        } else {
            $this->recordFail('T-04', "Expected paused+auto_paused, got status={$step->status} last_event=" . ($lastLog?->event_type ?? 'null'));
        }
    }

    private function t05_auto_resume_after_break(): void
    {
        // Lanjutan T-04 — step terakhir ada di state paused (auto_paused karena istirahat).
        // Maju ke 13:01 dan run tick.
        $step = ProductionOrderStep::where('name', 'T-04 Break')->latest()->first();
        if (!$step) {
            $this->recordFail('T-05', 'Step T-04 tidak ditemukan');
            return;
        }

        $this->setMockTime('13:01:00');
        app(ProductionTimerSync::class)->tick(now());

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        $ok = $step->status === 'in_progress' && $lastLog?->event_type === 'auto_resumed';
        if ($ok) {
            $this->recordPass('T-05', 'Step auto-resumed setelah istirahat.');
        } else {
            $this->recordFail('T-05', "Expected in_progress+auto_resumed, got status={$step->status} last_event=" . ($lastLog?->event_type ?? 'null'));
        }
    }

    private function t06_auto_pause_end_of_day(): void
    {
        // Pakai step T-04 yang masih running. Maju ke 17:01 (lewat jam_pulang).
        $step = ProductionOrderStep::where('name', 'T-04 Break')->latest()->first();
        $this->setMockTime('17:01:00');
        app(ProductionTimerSync::class)->tick(now());

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        $ok = $step->status === 'paused' && $lastLog?->event_type === 'auto_paused';
        if ($ok) {
            $this->recordPass('T-06', 'Step auto-paused setelah jam pulang.');
        } else {
            $this->recordFail('T-06', "Expected paused, got status={$step->status} last_event=" . ($lastLog?->event_type ?? 'null'));
        }
    }

    private function t07_overtime_auto_start(): void
    {
        // Lanjutkan dari T-06 — step paused karena lewat jam_pulang. Andi scan overtime_in 17:30.
        $step = ProductionOrderStep::where('name', 'T-04 Break')->latest()->first();
        $this->setMockTime('17:30:00');
        // Trigger via Observer (fire=true)
        $this->insertScan($this->created['karyawan_andi_id'], 'overtime_in', now(), true);

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        $ok = $step->status === 'in_progress' && $lastLog?->event_type === 'auto_resumed';
        if ($ok) {
            $this->recordPass('T-07', 'Step auto-resumed lewat scan overtime_in.');
        } else {
            $this->recordFail('T-07', "Expected in_progress+auto_resumed, got status={$step->status} last_event=" . ($lastLog?->event_type ?? 'null'));
        }
    }

    private function t08_cascade_parent_to_child(): void
    {
        $this->clearScans();
        $scanTime = $this->setMockTime('08:00:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime, false);

        $this->setMockTime('10:00:00');
        // Step pakai Mesin1 (child Andi) — TIDAK pakai Andi direct
        $step = $this->freshStep('T-08 Cascade');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_mesin1_id']]);
        } catch (\Exception $e) {
            $this->recordFail('T-08', 'startStep Mesin1 gagal: ' . $e->getMessage());
            return;
        }

        // Sekarang Andi scan check_out — harus cascade ke Mesin1 (child)
        $this->setMockTime('17:05:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_out', now(), true);

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        $ok = $step->status === 'paused' && $lastLog?->event_type === 'auto_paused';
        if ($ok) {
            $this->recordPass('T-08', 'Cascade: scan check_out Andi → Mesin1 (child) ter-pause.');
        } else {
            $this->recordFail('T-08', "Expected paused via cascade, got status={$step->status} last_event=" . ($lastLog?->event_type ?? 'null'));
        }
    }

    private function t09_multi_executor_independent(): void
    {
        $this->clearScans();
        $scanTime = $this->setMockTime('08:00:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime, false);
        $this->insertScan($this->created['karyawan_budi_id'], 'check_in', $scanTime, false);

        $this->setMockTime('10:00:00');
        $step = $this->freshStep('T-09 Multi-Executor');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [
                $this->created['exec_andi_id'],
                $this->created['exec_budi_id'],
            ]);
        } catch (\Exception $e) {
            $this->recordFail('T-09', 'startStep multi gagal: ' . $e->getMessage());
            return;
        }

        // Budi scan check_out duluan — step harus TETAP in_progress (Andi masih aktif)
        $this->setMockTime('15:00:00');
        $this->insertScan($this->created['karyawan_budi_id'], 'check_out', now(), true);

        $step->refresh();
        $budiStatus = ProductionStepExecutorStatus::where('step_id', $step->id)
            ->where('executor_id', $this->created['exec_budi_id'])->first();
        $andiStatus = ProductionStepExecutorStatus::where('step_id', $step->id)
            ->where('executor_id', $this->created['exec_andi_id'])->first();

        $check1 = $step->status === 'in_progress';
        $check2 = $budiStatus && !$budiStatus->is_active;
        $check3 = $andiStatus && $andiStatus->is_active;

        if ($check1 && $check2 && $check3) {
            $this->info('    Step tetap in_progress, Budi paused, Andi aktif.');
        } else {
            $this->recordFail('T-09', "Step status={$step->status}, Budi active=" . ($budiStatus?->is_active ?? '?') . ", Andi active=" . ($andiStatus?->is_active ?? '?'));
            return;
        }

        // Sekarang Andi scan check_out — step seharusnya pause
        $this->setMockTime('17:05:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_out', now(), true);

        $step->refresh();
        if ($step->status === 'paused') {
            $this->recordPass('T-09', 'Step pause hanya setelah SEMUA eksekutor check_out (independent per-executor).');
        } else {
            $this->recordFail('T-09', "Setelah Andi keluar, expected paused, got {$step->status}");
        }
    }

    private function t10_manual_pause_survives_tick(): void
    {
        $this->clearScans();
        $scanTime = $this->setMockTime('08:00:00');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime, false);

        $this->setMockTime('10:00:00');
        $step = $this->freshStep('T-10 Manual Pause');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
        } catch (\Exception $e) {
            $this->recordFail('T-10', 'startStep gagal: ' . $e->getMessage());
            return;
        }

        // Manual pause
        $this->setMockTime('10:05:00');
        $service->pauseStep($step->id);

        $execStatus = ProductionStepExecutorStatus::where('step_id', $step->id)->first();
        if (!$execStatus || $execStatus->paused_reason !== 'manual') {
            $this->recordFail('T-10', "Manual pause tidak set paused_reason='manual'. Got: " . ($execStatus->paused_reason ?? 'null'));
            return;
        }

        // Run tick - tidak boleh auto-resume
        $this->setMockTime('10:10:00');
        app(ProductionTimerSync::class)->tick(now());

        $step->refresh();
        if ($step->status === 'paused') {
            $this->recordPass('T-10', 'Manual pause TIDAK ter-auto-resume oleh tick.');
        } else {
            $this->recordFail('T-10', "Expected paused (manual), got {$step->status}");
        }
    }

    private function t11_off_day(): void
    {
        // Set jam ke Minggu (DOW=0) — schedule is_off=true
        $this->clearScans();
        // 2026-05-31 = Minggu
        $scanTime = $this->setMockTime('08:00:00', '2026-05-31');
        $this->insertScan($this->created['karyawan_andi_id'], 'check_in', $scanTime, false);

        $this->setMockTime('09:00:00', '2026-05-31');
        $step = $this->freshStep('T-11 Off Day');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']]);
            $this->recordFail('T-11', 'Expected Exception (off day), tapi startStep sukses.');
            return;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'libur')) {
                $this->recordPass('T-11', 'Off day: start ditolak dengan pesan benar.');
            } else {
                $this->recordFail('T-11', 'Exception salah: ' . $e->getMessage());
            }
        }
    }

    private function t12_drop_old_system(): void
    {
        $tableGone = !\Schema::hasTable('sdm_work_sessions');
        $colGone = !\Schema::hasColumn('production_departments', 'overtime_active');
        $startedEffective = \Schema::hasColumn('production_order_steps', 'started_effective_at');
        $pivotExists = \Schema::hasTable('production_step_executor_status');
        $parentCol = \Schema::hasColumn('production_department_executors', 'parent_executor_id');

        if ($tableGone && $colGone && $startedEffective && $pivotExists && $parentCol) {
            $this->recordPass('T-12', 'Schema baru OK & sistem lama dihapus.');
        } else {
            $msg = "tableGone={$tableGone} colGone={$colGone} startedEff={$startedEffective} pivot={$pivotExists} parent={$parentCol}";
            $this->recordFail('T-12', $msg);
        }
    }

    private function t13_circular_parent(): void
    {
        // Coba update Andi.parent_executor_id = Mesin1 (Mesin1 child Andi → circular)
        $controller = new \App\Modules\Production\Controllers\ExecutorController();
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'parent_executor_id' => $this->created['exec_mesin1_id'],
            'role' => 'Operator',
        ]);
        try {
            $controller->update($request, $this->created['exec_andi_id']);
            $this->recordFail('T-13', 'Expected ValidationException (circular), tapi update sukses.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['parent_executor_id'])) {
                $this->recordPass('T-13', 'Circular parent ditolak: ' . implode('; ', $errors['parent_executor_id']));
            } else {
                $this->recordFail('T-13', 'Validation error tapi bukan di parent_executor_id: ' . json_encode($errors));
            }
        } catch (\Exception $e) {
            $this->recordFail('T-13', 'Unexpected exception: ' . $e->getMessage());
        }
    }

    private function t14_resolver_status_branches(): void
    {
        $resolver = app(\App\Modules\Production\Services\ExecutorScheduleResolver::class);
        $sched = KaryawanSchedule::where('karyawan_id', $this->created['karyawan_andi_id'])
            ->where('day_of_week', 1)->first();

        $cases = [
            ['07:30', null, 'pre_work'],
            ['08:30', null, 'in_work'],
            ['12:30', null, 'in_break'],
            ['14:00', null, 'in_work'],
            ['17:05', null, 'after_work'],
            ['18:00', '17:30', 'in_overtime'],
            ['20:30', '17:30', 'after_overtime'],
        ];

        $failures = [];
        foreach ($cases as [$hm, $ovScan, $expected]) {
            $now = Carbon::parse('2026-05-25 ' . $hm . ':00');
            $ov = $ovScan ? Carbon::parse('2026-05-25 ' . $ovScan . ':00') : null;
            $status = $resolver->currentStatus($sched, $now, $ov);
            if ($status !== $expected) {
                $failures[] = "{$hm}/" . ($ovScan ?? 'no-ot') . " expected {$expected}, got {$status}";
            }
        }
        // off_day & no_schedule
        $schedOff = clone $sched;
        $schedOff->is_off = true;
        if ($resolver->currentStatus($schedOff, Carbon::parse('2026-05-25 10:00:00'), null) !== 'off_day') {
            $failures[] = "off_day branch failed";
        }
        if ($resolver->currentStatus(null, Carbon::parse('2026-05-25 10:00:00'), null) !== 'no_schedule') {
            $failures[] = "no_schedule branch failed";
        }

        if (empty($failures)) {
            $this->recordPass('T-14', 'Semua 9 cabang currentStatus() benar.');
        } else {
            $this->recordFail('T-14', implode(' | ', $failures));
        }
    }

    private function t15_manual_resume_when_machine_fails(): void
    {
        // Skenario: Step paused (auto). Mesin scan rusak / belum scan.
        // Eksekutor klik Resume manual — harus TETAP berhasil (mode longgar).
        $this->clearScans();

        $this->setMockTime('10:00:00');
        // Mulai step dengan force=true karena belum ada scan (simulasi: mesin rusak dari pagi)
        $step = $this->freshStep('T-15 Manual Resume');
        $service = app(ProductionOrderService::class);
        try {
            $service->startStep($step->id, [$this->created['exec_andi_id']], force: true);
        } catch (\Exception $e) {
            $this->recordFail('T-15', 'startStep force gagal: ' . $e->getMessage());
            return;
        }

        // Pause manual
        $service->pauseStep($step->id);

        // Tanpa scan apapun, klik Resume — harus berhasil (mode longgar)
        try {
            $service->resumeStep($step->id);
        } catch (\Exception $e) {
            $this->recordFail('T-15', 'resumeStep manual gagal padahal harusnya longgar: ' . $e->getMessage());
            return;
        }

        $step->refresh();
        $lastLog = ProductionStepTimeLog::where('production_order_step_id', $step->id)->orderByDesc('id')->first();
        if ($step->status === 'in_progress' && $lastLog?->event_type === 'resumed' && str_contains($lastLog->notes ?? '', 'manual')) {
            $this->recordPass('T-15', 'Resume manual berhasil meski tanpa scan (audit di notes).');
        } else {
            $this->recordFail('T-15', "Expected in_progress+resumed+manual-note, got status={$step->status}, last={$lastLog?->event_type}, notes=" . ($lastLog?->notes ?? 'null'));
        }
    }

    /* ====================== REPORT ====================== */

    private function report(): void
    {
        $this->newLine();
        $this->info('=== TEST REPORT ===');
        $pass = 0;
        $fail = 0;
        foreach ($this->results as $r) {
            if ($r['status'] === 'PASS') $pass++;
            else $fail++;
        }
        $total = count($this->results);
        $this->line("Total: {$total} | PASS: {$pass} | FAIL: {$fail}");
        $this->newLine();

        $rows = array_map(fn($r) => [$r['name'], $r['status'], substr($r['message'], 0, 80)], $this->results);
        $this->table(['Scenario', 'Status', 'Note'], $rows);
    }

    private function resultsHaveFailure(): bool
    {
        foreach ($this->results as $r) {
            if ($r['status'] === 'FAIL') return true;
        }
        return false;
    }
}
