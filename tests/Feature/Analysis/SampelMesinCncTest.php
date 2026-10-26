<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sampel Waktu Produksi dicoret bila langkah CNC dikerjakan di mesin lain kelompok:
 * Mesin 1 double head jauh lebih cepat dari Mesin 2 & 3 single head.
 */
class SampelMesinCncTest extends TestCase
{
    use RefreshDatabase;

    private int $cnc;
    private int $asm;
    private int $andi;
    private int $mesin1;
    private int $mesin2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cnc    = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true])->id;
        $this->asm    = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true])->id;
        $this->andi   = $this->executor($this->cnc, 'Andi');
        $this->mesin1 = $this->executor($this->cnc, 'Mesin 1', $this->andi, true);
        $this->mesin2 = $this->executor($this->cnc, 'Mesin 2', $this->andi);
    }

    private function executor(int $deptId, string $nama, ?int $induk = null, bool $ganda = false): int
    {
        return DB::table('production_department_executors')->insertGetId([
            'department_id' => $deptId, 'name' => $nama, 'is_active' => 1, 'parent_executor_id' => $induk,
            'kepala_ganda' => $ganda, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** OP selesai untuk produk baru $sku dengan satu langkah CNC 60 menit oleh $mesin. */
    private function sampel(string $sku, bool $produkGanda, array $mesin): array
    {
        static $n = 0;
        $n++;
        $pid = DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $sku, 'cnc_kepala_ganda' => $produkGanda, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $op = DB::table('production_orders')->insertGetId([
            'order_number' => "OP/UJI/{$n}", 'type' => 'ready_stock', 'warehouse_id' => 1,
            'production_date' => '2026-10-20', 'status' => 'finalized', 'planned_cycles' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_outputs')->insert([
            'production_order_id' => $op, 'product_id' => $pid, 'output_type' => 'main',
            'qty_planned' => 10, 'qty_produced' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $step = DB::table('production_order_steps')->insertGetId([
            'production_order_id' => $op, 'step_number' => 1, 'name' => 'Potong', 'department_id' => $this->cnc,
            'status' => 'completed', 'started_at' => '2026-10-20 08:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($mesin as $eid) {
            DB::table('production_order_step_executors')->insert(['step_id' => $step, 'executor_id' => $eid, 'joined_at' => now()]);
        }
        DB::table('production_step_time_logs')->insert([
            ['production_order_step_id' => $step, 'event_type' => 'started', 'occurred_at' => '2026-10-20 08:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['production_order_step_id' => $step, 'event_type' => 'auto_paused', 'occurred_at' => '2026-10-20 09:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $row = app(ProductionTimeAnalysisService::class)->forProduct($pid, ['types' => ['ready_stock']]);
        return array_merge($row['samples'], $row['ineligible_samples'])[0];
    }

    public function test_produk_mesin_1_di_mesin_1_tetap_dipakai(): void
    {
        $s = $this->sampel('TBKD-A5-T1-M3', true, [$this->mesin1]);

        $this->assertTrue($s['is_eligible']);
        $this->assertNotContains('mesin_tak_sesuai', $s['flags']);
    }

    public function test_produk_mesin_1_di_mesin_2_dicoret(): void
    {
        $s = $this->sampel('TBKD-A5-T1-M3', true, [$this->mesin2]);

        $this->assertFalse($s['is_eligible']);
        $this->assertContains('mesin_tak_sesuai', $s['flags']);
        $this->assertSame('Mesin 2', $s['mesin_tak_sesuai']);
    }

    public function test_produk_mesin_2_dan_3_di_mesin_1_dicoret(): void
    {
        $s = $this->sampel('BC-2x2', false, [$this->mesin1]);

        $this->assertFalse($s['is_eligible']);
        $this->assertSame('Mesin 1', $s['mesin_tak_sesuai']);
    }

    public function test_operator_penaung_dan_divisi_lain_tidak_dihitung(): void
    {
        $this->assertTrue($this->sampel('BC-2x3', false, [$this->mesin2, $this->andi])['is_eligible']);
    }

    public function test_label_tampil_di_halaman_waktu_produksi(): void
    {
        $this->sampel('TBKD-A5-T1-M3', true, [$this->mesin2]);
        $pid = DB::table('products')->where('sku', 'TBKD-A5-T1-M3')->value('id');

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get('/erp/analisa/waktu-produksi/' . $pid . '?types[]=ready_stock')
            ->assertOk()
            ->assertSee('Mesin tak sesuai kelompok: Mesin 2');
    }
}
