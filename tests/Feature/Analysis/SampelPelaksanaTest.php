<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\Production\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tabel sampel Waktu Produksi ikut menyebut SIAPA yang mengerjakan tiap divisi.
 *
 * Tanpa itu barisnya cuma angka: 5 jam di CNC tidak bisa dinilai bagus atau buruk
 * kalau tidak diketahui mesin mana yang mengerjakannya. Sumbernya pivot langkah —
 * satu langkah bisa dikerjakan beberapa orang sekaligus, dan satu divisi bisa punya
 * beberapa langkah, jadi yang disimpan himpunan nama, bukan satu nama per divisi.
 */
class SampelPelaksanaTest extends TestCase
{
    use RefreshDatabase;

    private int $cnc;
    private int $asm;
    private int $productId;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cnc = Department::create(['code' => 'PRD-001', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true])->id;
        $this->asm = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true])->id;

        $this->productId = DB::table('products')->insertGetId([
            'sku' => 'UJI-PLK', 'name' => 'Produk Uji', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->orderId = DB::table('production_orders')->insertGetId([
            'order_number' => 'OP/UJI/0001', 'type' => 'ready_stock', 'warehouse_id' => 1,
            'production_date' => '2026-08-10', 'status' => 'finalized', 'planned_cycles' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('production_order_outputs')->insert([
            'production_order_id' => $this->orderId, 'product_id' => $this->productId,
            'output_type' => 'main', 'qty_planned' => 2, 'qty_produced' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function executor(int $deptId, string $nama): int
    {
        return DB::table('production_department_executors')->insertGetId([
            'department_id' => $deptId, 'name' => $nama, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Satu langkah selesai berdurasi $menit, dikerjakan $executorIds. */
    private function step(int $deptId, string $nama, int $menit, array $executorIds): int
    {
        static $n = 0;
        $n++;

        $stepId = DB::table('production_order_steps')->insertGetId([
            'production_order_id' => $this->orderId, 'step_number' => $n, 'name' => $nama,
            'department_id' => $deptId, 'status' => 'completed',
            'started_at' => '2026-08-10 08:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($executorIds as $eid) {
            DB::table('production_order_step_executors')->insert([
                'step_id' => $stepId, 'executor_id' => $eid, 'joined_at' => now(),
            ]);
        }

        DB::table('production_step_time_logs')->insert([
            [
                'production_order_step_id' => $stepId, 'event_type' => 'started',
                'occurred_at' => '2026-08-10 08:00:00', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'production_order_step_id' => $stepId, 'event_type' => 'auto_paused',
                'occurred_at' => '2026-08-10 ' . sprintf('%02d:%02d:00', 8 + intdiv($menit, 60), $menit % 60),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        return $stepId;
    }

    private function sampel(): array
    {
        $row = app(ProductionTimeAnalysisService::class)
            ->forProduct($this->productId, ['types' => ['ready_stock']]);

        $this->assertNotNull($row, 'Produk uji harus punya sampel.');

        return $row['samples'][0];
    }

    public function test_pelaksana_tercatat_per_divisi(): void
    {
        $mesin1 = $this->executor($this->cnc, 'Mesin 1');
        $novan  = $this->executor($this->asm, 'Novan');

        $this->step($this->cnc, 'Potong', 120, [$mesin1]);
        $this->step($this->asm, 'Rakit', 60, [$novan]);

        $s = $this->sampel();

        $this->assertSame(['Mesin 1'], $s['executors'][$this->cnc]);
        $this->assertSame(['Novan'], $s['executors'][$this->asm]);
    }

    public function test_langkah_bertangan_banyak_menyebut_semuanya(): void
    {
        $novan = $this->executor($this->asm, 'Novan');
        $reza  = $this->executor($this->asm, 'Reza');

        $this->step($this->asm, 'Rakit', 60, [$novan, $reza]);

        $this->assertSame(['Novan', 'Reza'], $this->sampel()['executors'][$this->asm]);
    }

    public function test_beberapa_langkah_satu_divisi_digabung_tanpa_kembar(): void
    {
        $mesin1 = $this->executor($this->cnc, 'Mesin 1');
        $mesin3 = $this->executor($this->cnc, 'Mesin 3');

        $this->step($this->cnc, 'Potong', 60, [$mesin1]);
        $this->step($this->cnc, 'Ukir', 30, [$mesin1, $mesin3]);

        $pelaksana = $this->sampel()['executors'][$this->cnc];
        sort($pelaksana);

        $this->assertSame(['Mesin 1', 'Mesin 3'], $pelaksana);
    }

    public function test_langkah_tanpa_pelaksana_tidak_mengarang_nama(): void
    {
        $this->step($this->cnc, 'Potong', 60, []);

        $s = $this->sampel();

        $this->assertArrayHasKey('executors', $s, 'Kuncinya harus tetap ada supaya "belum tercatat" bisa dibedakan.');
        $this->assertArrayNotHasKey($this->cnc, $s['executors']);
    }

    public function test_pelaksana_tampil_di_halaman_waktu_produksi(): void
    {
        $mesin1 = $this->executor($this->cnc, 'Mesin 1');
        $this->step($this->cnc, 'Potong', 60, [$mesin1]);

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get('/erp/analisa/waktu-produksi/' . $this->productId . '?types[]=ready_stock')
            ->assertOk()
            ->assertSee('Mesin 1');
    }
}
