<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\ProductionSetting;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pending manual wajib alasan (9 Nov 2026) — dasar neraca jam kerja & skill Pengawasan
 * penilaian Assembling: pelaksana tak bisa diam-diam mengerjakan OP saat timer di-pending.
 */
class AlasanPendingTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrderStep $step;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionSetting::create(['testing_mode' => true, 'alasan_pending_wajib' => true]);

        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'tb-1', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
        $asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $ex  = DepartmentExecutor::create(['department_id' => $asm->id, 'name' => 'Budi', 'is_active' => true]);

        $order = ProductionOrder::create(['order_number' => 'OP-UJI-1', 'type' => 'ready_stock',
            'warehouse_id' => $gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $produk,
            'qty_planned' => 1, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        $this->step = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $asm->id, 'name' => 'Assembling', 'status' => 'pending']);
        app(ProductionOrderService::class)->startStep($this->step->id, [$ex->id]);

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function pause(array $data)
    {
        return $this->actingAs($this->admin)->from(route('production.process.index'))
            ->post(route('production.process.steps.pause', $this->step->id), $data);
    }

    public function test_pending_tanpa_alasan_ditolak(): void
    {
        $this->pause([])->assertSessionHasErrors('alasan');
        $this->assertSame('in_progress', $this->step->fresh()->status);
    }

    public function test_alasan_berketerangan_wajib_diisi(): void
    {
        $this->pause(['alasan' => 'belanja', 'keterangan' => '  '])->assertSessionHas('error');
        $this->assertSame('in_progress', $this->step->fresh()->status);
    }

    public function test_pending_beralasan_tercatat_dan_tampil_di_kartu(): void
    {
        $this->pause(['alasan' => 'belanja', 'keterangan' => 'Beli galon 2'])->assertSessionHas('success');

        $this->assertSame('paused', $this->step->fresh()->status);
        $log = ProductionStepTimeLog::where('production_order_step_id', $this->step->id)->where('event_type', 'paused')->sole();
        $this->assertSame('belanja', $log->alasan);
        $this->assertSame('Beli galon 2', $log->notes);

        $this->actingAs($this->admin)->get(route('production.process.index'))->assertOk()
            ->assertSee('Belanja keperluan', false)->assertSee('Beli galon 2', false);
    }

    public function test_sakelar_mati_pause_langsung_tanpa_alasan(): void
    {
        ProductionSetting::first()->update(['alasan_pending_wajib' => false]);

        $this->pause([])->assertSessionHas('success');
        $this->assertSame('paused', $this->step->fresh()->status);
        $this->assertNull(ProductionStepTimeLog::where('event_type', 'paused')->value('alasan'));
    }

    public function test_sakelar_di_pengaturan_produksi(): void
    {
        $this->actingAs($this->admin)->post(route('production.settings.alasan-pending.update'), ['alasan_pending_wajib' => '0'])
            ->assertSessionHas('success');
        $this->assertFalse(ProductionSetting::isAlasanPendingWajib());

        $this->actingAs($this->admin)->post(route('production.settings.alasan-pending.update'), ['alasan_pending_wajib' => '1']);
        $this->assertTrue(ProductionSetting::isAlasanPendingWajib());
    }

    public function test_alasan_tanpa_keterangan_boleh(): void
    {
        $this->pause(['alasan' => 'bongkar_barang'])->assertSessionHas('success');
        $this->assertSame('bongkar_barang', ProductionStepTimeLog::where('event_type', 'paused')->value('alasan'));
    }
}
