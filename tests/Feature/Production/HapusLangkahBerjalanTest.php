<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Warehouse;
use App\Modules\Analysis\Models\ProductionTimeSampleExclusion;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Langkah antre boleh dihapus saat OP berjalan (mis. Assembling OP koin diganti Desain tempel
 * stiker) — dan OP-nya dicoret dari sampel waktu karena alurnya tak lagi sesuai BOM.
 */
class HapusLangkahBerjalanTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderService $svc;
    private Department $cnc;
    private Department $asm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ProductionOrderService::class);
        $this->cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
    }

    /** OP berjalan: CNC selesai → Assembling antre → CNC antre. */
    private function op(): ProductionOrder
    {
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $order = ProductionOrder::create(['order_number' => 'OP-' . uniqid(), 'type' => 'ready_stock',
            'warehouse_id' => $gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => now()->toDateString(), 'status' => 'in_progress']);
        foreach ([[$this->cnc, 'completed'], [$this->asm, 'pending'], [$this->cnc, 'pending']] as $i => [$d, $st]) {
            ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => $i + 1,
                'department_id' => $d->id, 'name' => $d->name, 'status' => $st]);
        }
        return $order;
    }

    private function langkah(ProductionOrder $order, int $no): ProductionOrderStep
    {
        return $order->steps()->where('step_number', $no)->firstOrFail();
    }

    public function test_assembling_diganti_desain_dan_sampel_dicoret(): void
    {
        $order = $this->op();

        $this->svc->addStep($order->id, 'desain:tempel', 1);   // Desain disisipkan setelah CNC 1
        $this->svc->deleteStep($order->id, $this->langkah($order, 3)->id); // Assembling (kini no. 3)

        $urut = $order->steps()->orderBy('step_number')->get();
        $this->assertSame([1, 2, 3], $urut->pluck('step_number')->all());
        $this->assertSame('tempel', $urut[1]->desain);
        $this->assertSame($this->cnc->id, $urut[2]->department_id);
        $this->assertStringContainsString('tidak sesuai standar',
            ProductionTimeSampleExclusion::where('production_order_id', $order->id)->value('reason'));
    }

    public function test_tolak_bila_langkah_sesudahnya_sudah_dikerjakan(): void
    {
        $order = $this->op();
        $this->langkah($order, 3)->update(['status' => 'in_progress']);

        $this->expectExceptionMessage('sesudahnya belum dikerjakan');
        $this->svc->deleteStep($order->id, $this->langkah($order, 2)->id);
    }

    public function test_tolak_menghapus_langkah_terakhir_yang_belum_selesai(): void
    {
        $order = $this->op();
        $this->svc->deleteStep($order->id, $this->langkah($order, 3)->id);

        $this->expectExceptionMessage('tambahkan langkah penggantinya dulu');
        $this->svc->deleteStep($order->id, $this->langkah($order, 2)->id);
    }

    public function test_hapus_saat_draft_tidak_mencoret_sampel(): void
    {
        $order = $this->op();
        $order->update(['status' => 'confirmed']);
        $this->svc->deleteStep($order->id, $this->langkah($order, 2)->id);

        $this->assertFalse(ProductionTimeSampleExclusion::where('production_order_id', $order->id)->exists());
    }
}
