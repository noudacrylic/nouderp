<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepExecutorStatus;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\ProductionTimerSync;
use App\Modules\SDM\Models\FingerprintLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laporan operator 21 Nov 2026: scan masuk jam 7 (jam kerja 08:00) membuat timer langkah yang
 * kemarin dijeda otomatis langsung jalan — semenit kemudian baru dijeda tick, dan di layar
 * terlihat berjalan. Timer baru boleh jalan saat jam kerja dimulai.
 */
class ScanSebelumJamKerjaTest extends TestCase
{
    use RefreshDatabase;

    private const SABTU = '2026-11-21';

    private int $karyawanId;
    private int $machineId;
    private ProductionOrderStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        $this->karyawanId = DB::table('sdm_karyawan')->insertGetId([
            'staf_code' => 'K-001', 'name' => 'Andi', 'user_id_fingerprint' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(0, 6) as $dow) {
            DB::table('sdm_karyawan_schedule')->insert([
                'karyawan_id' => $this->karyawanId, 'day_of_week' => $dow,
                'jam_masuk' => '08:00:00', 'jam_pulang' => '16:00:00',
                'jam_istirahat_start' => '12:00:00', 'jam_istirahat_end' => '13:00:00',
                'awal_absen_pulang' => 120, 'akhir_absen_pulang' => 15,
                'has_lembur' => 0, 'is_off' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->machineId = DB::table('sdm_fingerprint_machines')->insertGetId([
            'code' => 'FP-01', 'name' => 'Mesin Absen', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $ex  = DepartmentExecutor::create(['department_id' => $asm->id, 'name' => 'Andi', 'karyawan_id' => $this->karyawanId, 'is_active' => true]);
        $order = ProductionOrder::create(['order_number' => 'OP-UJI-1', 'type' => 'ready_stock',
            'warehouse_id' => $gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => self::SABTU, 'status' => 'in_progress']);

        // Keadaan pagi: langkah kemarin dijeda otomatis saat jam pulang.
        $this->step = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $asm->id, 'name' => 'Assembling', 'status' => 'paused']);
        $this->step->executors()->attach($ex->id);
        ProductionStepExecutorStatus::create(['step_id' => $this->step->id, 'executor_id' => $ex->id,
            'karyawan_id' => $this->karyawanId, 'is_active' => false, 'paused_reason' => 'Setelah jam pulang']);
        ProductionStepTimeLog::create(['production_order_step_id' => $this->step->id, 'event_type' => 'auto_paused',
            'occurred_at' => '2026-11-20 16:00:02', 'notes' => 'Semua eksekutor tidak aktif']);
    }

    private function scan(string $jam): void
    {
        FingerprintLog::create(['machine_id' => $this->machineId, 'karyawan_id' => $this->karyawanId,
            'user_id_fingerprint' => '1', 'scan_at' => self::SABTU . " $jam", 'verify_type' => 'check_in']);
    }

    private function logTerakhir(): ?ProductionStepTimeLog
    {
        return ProductionStepTimeLog::where('production_order_step_id', $this->step->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();
    }

    public function test_scan_jam_7_tidak_menjalankan_timer_lalu_jalan_jam_8(): void
    {
        $this->scan('07:13:15');

        $this->assertSame('paused', $this->step->fresh()->status, 'Scan sebelum jam kerja tidak boleh menjalankan timer.');
        $this->assertSame('auto_paused', $this->logTerakhir()->event_type);

        app(ProductionTimerSync::class)->tick(Carbon::parse(self::SABTU . ' 07:14:00'));
        $this->assertSame('paused', $this->step->fresh()->status);

        app(ProductionTimerSync::class)->tick(Carbon::parse(self::SABTU . ' 08:00:02'));
        $this->assertSame('in_progress', $this->step->fresh()->status, 'Jam 8 timer jalan sendiri.');
        $this->assertSame('2026-11-21 08:00:02', $this->logTerakhir()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_scan_telat_dalam_jam_kerja_langsung_menjalankan_timer(): void
    {
        $this->scan('08:20:00');

        $this->assertSame('in_progress', $this->step->fresh()->status);
        $this->assertSame('Scan check_in', $this->logTerakhir()->notes);
    }
}
