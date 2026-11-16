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
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merk akrilik per OP (16 Nov 2026): operator CNC memilih merk saat Mulai (boleh campur),
 * tanpa mengubah BOM/SKU bahan. Dasar membandingkan lama kerja per merk (MAG sulit dirakit).
 */
class MerkAkrilikTest extends TestCase
{
    use RefreshDatabase;

    private ProductionOrder $order;
    private ProductionOrderStep $cnc;
    private ProductionOrderStep $asm;
    private int $exCnc;
    private int $exAsm;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionSetting::create(['testing_mode' => true, 'merk_akrilik' => ['MAG', 'NN', 'Tiger', 'Tanpa Merk']]);

        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'tb-1', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
        $cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->exCnc = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Mesin 1', 'is_active' => true])->id;
        $this->exAsm = DepartmentExecutor::create(['department_id' => $asm->id, 'name' => 'Budi', 'is_active' => true])->id;

        $this->order = ProductionOrder::create(['order_number' => 'OP-UJI-1', 'type' => 'ready_stock',
            'warehouse_id' => $gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        ProductionOrderOutput::create(['production_order_id' => $this->order->id, 'product_id' => $produk,
            'qty_planned' => 1, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        $this->cnc = ProductionOrderStep::create(['production_order_id' => $this->order->id, 'step_number' => 1,
            'department_id' => $cnc->id, 'name' => 'CNC', 'status' => 'pending']);
        $this->asm = ProductionOrderStep::create(['production_order_id' => $this->order->id, 'step_number' => 2,
            'department_id' => $asm->id, 'name' => 'Assembling', 'status' => 'pending']);

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function mulai(ProductionOrderStep $step, int $ex, ?array $merk)
    {
        $data = ['executor_ids' => [$ex]];
        if ($merk !== null) {
            $data += ['merk_dikirim' => 1, 'merk' => $merk];
        }
        return $this->actingAs($this->admin)->from(route('production.process.index'))
            ->post(route('production.process.steps.start', $step->id), $data);
    }

    public function test_mulai_cnc_menyimpan_merk_campur_urut_daftar(): void
    {
        $this->mulai($this->cnc, $this->exCnc, ['Tiger' => 1, 'MAG' => 1])->assertSessionHas('success');

        $this->assertSame('in_progress', $this->cnc->fresh()->status);
        $this->assertSame(['MAG', 'Tiger'], $this->order->fresh()->merk_bahan);
    }

    public function test_jumlah_lembar_per_merk_dibatasi_bahan_lembaran_op(): void
    {
        // Bahan lembaran 1,6 + 0,5 = 2,1 lembar → maks 3 (dibulatkan ke atas); bahan non-LBR tak dihitung.
        foreach (['LBR-122x244-2-TR' => 1.6, 'LBR-122x244-3-PK' => 0.5, 'ENG-1' => 10] as $sku => $qty) {
            $p = Product::create(['sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'base_unit' => 'lembar',
                'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
            \App\Modules\Production\Models\ProductionOrderMaterial::create(['production_order_id' => $this->order->id,
                'product_id' => $p->id, 'qty_required' => $qty, 'qty_consumed' => 0, 'unit' => 'lembar']);
        }
        $this->assertSame([$this->order->id => 3], ProductionOrder::maksLembarAkrilik([$this->order->id]));

        $this->mulai($this->cnc, $this->exCnc, ['MAG' => 3, 'NN' => 1])->assertSessionHas('error');
        $this->assertSame('pending', $this->cnc->fresh()->status);

        $this->mulai($this->cnc, $this->exCnc, ['NN' => 1, 'MAG' => 2, 'Tiger' => 0])->assertSessionHas('success');
        $op = $this->order->fresh();
        $this->assertSame(['MAG', 'NN'], $op->merk_bahan);
        $this->assertSame(['MAG' => 2, 'NN' => 1], $op->merk_jumlah);

        // Kartu menampilkan jumlah; dibetulkan dari Assembling juga tunduk batas.
        $this->actingAs($this->admin)->get(route('production.process.index'))->assertSee('🧱 MAG 2 + NN 1', false);
        $this->actingAs($this->admin)->from(route('production.process.index'))
            ->post(route('production.process.steps.merk', $this->asm->id), ['merk' => ['MAG' => 4]])->assertSessionHas('error');
        $this->actingAs($this->admin)->from(route('production.process.index'))
            ->post(route('production.process.steps.merk', $this->asm->id), ['merk' => ['MAG' => 3]])->assertSessionHas('success');
        $this->assertSame(['MAG' => 3], $this->order->fresh()->merk_jumlah);
    }

    public function test_merk_di_luar_daftar_ditolak(): void
    {
        $this->mulai($this->cnc, $this->exCnc, ['Acrylite' => 1])->assertSessionHas('error');
        $this->assertSame('pending', $this->cnc->fresh()->status);
        $this->assertNull($this->order->fresh()->merk_bahan);
    }

    public function test_wajib_tanpa_merk_ditolak_tidak_wajib_boleh_kosong(): void
    {
        ProductionSetting::first()->update(['merk_akrilik_wajib' => true]);
        $this->mulai($this->cnc, $this->exCnc, [])->assertSessionHas('error');
        $this->assertSame('pending', $this->cnc->fresh()->status);

        ProductionSetting::first()->update(['merk_akrilik_wajib' => false]);
        $this->mulai($this->cnc, $this->exCnc, [])->assertSessionHas('success');
        $this->assertSame('in_progress', $this->cnc->fresh()->status);
    }

    public function test_langkah_bukan_cnc_tidak_mengubah_merk(): void
    {
        ProductionSetting::first()->update(['merk_akrilik_wajib' => true]);
        $this->mulai($this->cnc, $this->exCnc, ['NN' => 1]);
        $this->cnc->update(['status' => 'completed', 'completed_at' => now()]);

        $this->mulai($this->asm, $this->exAsm, null)->assertSessionHas('success');
        $this->assertSame(['NN'], $this->order->fresh()->merk_bahan);
    }

    public function test_kartu_tampil_merk_dan_bisa_dibetulkan(): void
    {
        $this->mulai($this->cnc, $this->exCnc, ['NN' => 1]);

        $this->actingAs($this->admin)->get(route('production.process.index'))->assertOk()
            ->assertSee('🧱 NN', false);

        $this->actingAs($this->admin)->from(route('production.process.index'))
            ->post(route('production.process.steps.merk', $this->asm->id), ['merk' => ['MAG' => 1, 'NN' => 1]])
            ->assertSessionHas('success');
        $this->assertSame(['MAG', 'NN'], $this->order->fresh()->merk_bahan);
    }

    public function test_pengaturan_daftar_merk_dan_sakelar(): void
    {
        $this->actingAs($this->admin)->post(route('production.settings.merk-akrilik.update'), [
            'daftar' => "MAG\n NN \nmag\n\nPronamax", 'merk_akrilik_wajib' => '1',
        ])->assertSessionHas('success');

        $this->assertSame(['MAG', 'NN', 'Pronamax'], ProductionSetting::daftarMerkAkrilik());
        $this->assertTrue(ProductionSetting::isMerkAkrilikWajib());

        $this->actingAs($this->admin)->get(route('production.settings'))->assertOk()->assertSee('Pronamax');
    }
}
