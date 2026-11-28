<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Langkah Desain (cetak/tempel stiker, tanpa divisi) disimpan di BOM lalu ikut tersalin ke OP —
 * contoh Akrilik Sign: BAS-3010 + stiker → 1 langkah "Desain — cetak & tempel".
 */
class BomLangkahDesainTest extends TestCase
{
    use RefreshDatabase;

    private Department $asm;
    private Product $bas;
    private Product $stiker;
    private Product $sign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asm    = Department::create(['code' => 'PRD-002', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->bas    = $this->produk('BAS-3010', 'Bahan Akrilik Sign 30x10');
        $this->stiker = $this->produk('STK-A3', 'Kertas Stiker A3+');
        $this->sign   = $this->produk('AS-JE-3010-KI-STD', 'Sign Jalur Evakuasi 30x10 Kiri');
    }

    private function produk(string $sku, string $nama): Product
    {
        return Product::create(['sku' => $sku, 'name' => $nama, 'base_unit' => 'pcs', 'base_price' => 0, 'is_active' => true]);
    }

    private function dataBom(array $langkah): array
    {
        return [
            'name'      => 'Sign JE 30x10 Kiri',
            'materials' => [
                ['product_id' => $this->bas->id, 'qty_per_cycle' => 1, 'unit' => 'pcs'],
                ['product_id' => $this->stiker->id, 'qty_per_cycle' => 0.25, 'unit' => 'lembar'],
            ],
            'outputs' => [['product_id' => $this->sign->id, 'qty_per_cycle' => 1, 'output_type' => 'main', 'percentage' => 100]],
            'steps'   => $langkah,
        ];
    }

    public function test_bom_menyimpan_langkah_desain_dan_divisi(): void
    {
        $bom = app(BomService::class)->create($this->dataBom([
            ['department_id' => 'desain:cetak', 'name' => ''],
            ['department_id' => (string) $this->asm->id, 'name' => 'Assembling'],
        ]));

        $langkah = $bom->steps()->get();
        $this->assertCount(2, $langkah);
        $this->assertSame('cetak', $langkah[0]->desain);
        $this->assertNull($langkah[0]->department_id);
        $this->assertSame(1, $langkah[0]->step_number);
        $this->assertSame('desain:cetak', $langkah[0]->pilihan);
        $this->assertNull($langkah[1]->desain);
        $this->assertSame($this->asm->id, $langkah[1]->department_id);

        $this->assertSame(
            [['name' => 'Desain — cetak stiker (ditempel Assembling)', 'department_id' => 'desain:cetak', 'department_name' => 'Desain — cetak stiker (ditempel Assembling)'],
             ['name' => 'Assembling', 'department_id' => $this->asm->id, 'department_name' => 'Assembling']],
            app(BomService::class)->getSteps($bom->id)
        );
    }

    public function test_op_dari_bom_mendapat_langkah_desain(): void
    {
        $bom = app(BomService::class)->create($this->dataBom([['department_id' => 'desain:tempel', 'name' => '']]));
        $gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true]);

        $op = app(ProductionOrderService::class)->create([
            'type' => 'ready_stock', 'bom_id' => $bom->id, 'warehouse_id' => $gudang->id, 'production_date' => now()->toDateString(),
            'planned_cycles' => 4,
            'materials' => [], 'outputs' => [['product_id' => $this->sign->id, 'qty_planned' => 4, 'output_type' => 'main', 'percentage' => 100]],
            'steps' => app(BomService::class)->getSteps($bom->id),
        ]);

        $step = $op->steps()->sole();
        $this->assertSame('tempel', $step->desain);
        $this->assertNull($step->department_id);
        $this->assertTrue($step->isDesain());
    }

    public function test_salin_bom_ikut_menyalin_langkah_desain(): void
    {
        $bom = app(BomService::class)->create($this->dataBom([['department_id' => 'desain:tempel', 'name' => '']]));
        $salinan = app(BomService::class)->clone($bom->id);

        $this->assertSame('tempel', $salinan->steps()->sole()->desain);
    }

    public function test_form_bom_menampilkan_pilihan_desain_dan_langkah_tersimpan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $this->get(route('production.boms.create'))->assertOk()->assertSee('value="desain:tempel"', false)->assertSee('sisaLembar()', false);

        $bom = app(BomService::class)->create($this->dataBom([['department_id' => 'desain:cetak', 'name' => '']]));
        $this->get(route('production.boms.edit', $bom->id))->assertOk()->assertSee('"department_id":"desain:cetak"', false);
    }

    public function test_form_bom_menolak_divisi_tak_dikenal_dan_menerima_desain(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->post(route('production.boms.store'), $this->dataBom([['department_id' => 'desain:lukis', 'name' => 'x']]))
            ->assertSessionHasErrors('steps.0.department_id');
        $this->assertSame(0, Bom::count());

        $this->post(route('production.boms.store'), $this->dataBom([['department_id' => 'desain:tempel', 'name' => '']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('tempel', Bom::sole()->steps()->sole()->desain);
    }
}
