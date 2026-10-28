<?php

namespace Tests\Feature\Tasks;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualSkill;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill Manajer Admin "Cetak stiker untuk OP": OP berbahan stiker → task di peran Desain & Konten,
 * selesai otomatis saat langkah Assembling dimulai.
 */
class CetakStikerTest extends TestCase
{
    use RefreshDatabase;

    private User $desainer;
    private Warehouse $gudang;
    private Department $cnc;
    private Department $rakit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desainer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->gudang   = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true]);
        $this->cnc      = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->rakit    = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'desain', 'user_id' => $this->desainer->id, 'urutan' => 1]);

        MejaKerjaSkill::updateOrCreate(['skill' => 'cetak-stiker'], ['peran' => 'desain', 'bobot' => 8, 'sla_menit' => 120]);
        KaryawanVirtualSkill::updateOrCreate(['skill' => 'admin.cetak-stiker'], ['mode' => 'otomatis', 'mode_diubah_at' => now()->subDay()]);
    }

    private function produk(string $sku, string $nama): Product
    {
        return Product::create(['sku' => $sku, 'name' => $nama, 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 0, 'is_active' => true]);
    }

    private function op(string $bahan): ProductionOrder
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create([
            'order_number' => "OP-ST-{$n}", 'type' => 'ready_stock', 'warehouse_id' => $this->gudang->id,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => 'confirmed',
            'score_type' => 'auto', 'created_via' => 'auto',
        ]);
        ProductionOrderMaterial::create(['production_order_id' => $op->id, 'product_id' => $this->produk("B-$n", $bahan)->id, 'qty_required' => 20, 'unit' => 'Lembar']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $this->produk("KOIN-TPM-$n", 'Koin')->id,
            'qty_planned' => 1500, 'output_type' => 'main', 'percentage' => 100]);
        foreach ([$this->cnc, $this->rakit] as $i => $d) {
            ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => $i + 1, 'department_id' => $d->id,
                'name' => $d->name, 'status' => 'pending']);
        }
        return $op;
    }

    public function test_op_berbahan_stiker_jadi_task_desain_lalu_selesai_saat_assembling_mulai(): void
    {
        $op = $this->op('Stiker Vynil A3+ Transparan');
        $this->op('Akrilik Lembaran 2 mm');
        $meja = app(MejaKerjaService::class);

        $meja->sinkron();
        $tasks = Task::where('skill', 'cetak-stiker')->get();

        $this->assertCount(1, $tasks, 'Hanya OP yang bahannya stiker.');
        $task = $tasks->first();
        $this->assertSame('desain', $task->peran);
        $this->assertSame($this->desainer->id, $task->assignee_user_id);
        $this->assertStringContainsString('10 lembar stiker', (string) $task->description);

        $meja->sinkron();
        $this->assertSame(1, Task::where('skill', 'cetak-stiker')->count(), 'Tidak dobel.');

        ProductionOrderStep::where('production_order_id', $op->id)->where('step_number', 2)
            ->update(['status' => 'in_progress', 'started_at' => now()]);
        $meja->sinkron();

        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_op_dibatalkan_task_batal(): void
    {
        $op = $this->op('Stiker Chromo A3+');
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();

        $op->update(['status' => 'cancelled']);
        $meja->sinkron();

        $this->assertSame('cancelled', Task::where('skill', 'cetak-stiker')->first()->status);
    }
}
