<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomMaterial;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\BomStep;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\AutoProductionService;
use App\Modules\Production\Services\IsiAntreanCncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BOM alternatif (urutan_pakai): Manager Produksi memakai BOM urutan terkecil yang bahannya cukup.
 * Kasus nyata box mahar sliding 40x30 hitam:
 *   BOM "pakai stok tutup" (urutan 1): 2 hitam + 1 bening + 2 tutup → 26 box
 *   BOM "tutup sisa" (urutan 2, auto): 2 hitam + 1⅓ bening → 26 box + 6 tutup sampingan
 */
class BomAlternatifTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $utama;
    private Department $cnc;
    private Product $hitam;
    private Product $bening;
    private Product $tutup;
    private Product $box;
    private Bom $pakaiTutup;
    private Bom $tutupSisa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utama  = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $this->cnc    = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->hitam  = $this->barang('LBR-HITAM', 20);
        $this->bening = $this->barang('LBR-BENING', 20);
        $this->tutup  = $this->barang('TUTUP-40x30', 0);
        $this->box    = $this->barang('PRD00011', 0, min: 10);

        $this->tutupSisa = $this->bom('BOM-TUTUP-SISA', ['auto_production' => true, 'urutan_pakai' => 2], [
            [$this->hitam, 2], [$this->bening, 1.3333],
        ], withTutupSampingan: true);
        $this->pakaiTutup = $this->bom('BOM-PAKAI-TUTUP', ['urutan_pakai' => 1], [
            [$this->hitam, 2], [$this->bening, 1], [$this->tutup, 2],
        ]);
    }

    private function barang(string $sku, float $stok, ?float $min = null, bool $andalan = false): Product
    {
        $p = Product::create([
            'sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'made_to_order' => false, 'base_unit' => 'pcs',
            'base_price' => 10000, 'is_active' => true, 'min_stock' => $min, 'produk_andalan' => $andalan,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->utama->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    private function bom(string $nomor, array $attr, array $bahan, bool $withTutupSampingan = false): Bom
    {
        $bom = Bom::create(['bom_number' => $nomor, 'name' => $nomor, 'typical_cycles' => 1] + $attr);
        foreach ($bahan as [$p, $qty]) {
            BomMaterial::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => $qty, 'unit' => 'pcs']);
        }
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $this->box->id, 'qty_per_cycle' => 26, 'output_type' => 'main', 'percentage' => $withTutupSampingan ? 95 : 100]);
        if ($withTutupSampingan) {
            BomOutput::create(['bom_id' => $bom->id, 'product_id' => $this->tutup->id, 'qty_per_cycle' => 6, 'output_type' => 'by_product', 'percentage' => 5]);
        }
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 1, 'department_id' => $this->cnc->id, 'name' => 'CNC']);
        return $bom;
    }

    private function stokTutup(float $qty): void
    {
        ProductStock::where('product_id', $this->tutup->id)->update(['qty_on_hand' => $qty]);
    }

    private function opBox(): ProductionOrder
    {
        return ProductionOrder::with('outputs')
            ->whereHas('outputs', fn ($q) => $q->where('product_id', $this->box->id)->where('output_type', 'main'))
            ->sole();
    }

    public function test_stok_tutup_cukup_pakai_bom_urutan_pertama(): void
    {
        $this->stokTutup(6);

        $hasil = app(AutoProductionService::class)->runForBom($this->tutupSisa);

        $this->assertTrue($hasil['created']);
        $this->assertSame($this->pakaiTutup->id, $this->opBox()->bom_id);
        $this->assertSame($this->tutupSisa->id, $hasil['bom_id'], 'Kunci usulan tetap BOM auto.');
        $this->assertSame('BOM-PAKAI-TUTUP', $hasil['bom_number']);
        $this->assertStringContainsString('pakai BOM-PAKAI-TUTUP (urutan 1)', $hasil['reason']);
    }

    public function test_stok_tutup_kurang_pindah_ke_bom_tutup_sisa(): void
    {
        $this->stokTutup(1);

        $hasil = app(AutoProductionService::class)->runForBom($this->tutupSisa);

        $op = $this->opBox();
        $this->assertSame($this->tutupSisa->id, $op->bom_id);
        $this->assertNotNull($op->outputs->firstWhere('product_id', $this->tutup->id), 'Tutup sisa jadi produk sampingan.');
        $this->assertStringContainsString('BOM-PAKAI-TUTUP dilewati: TUTUP-40x30 butuh 2, ada 1', $hasil['reason']);
    }

    public function test_usulan_dipilih_ulang_saat_disetujui(): void
    {
        $svc = app(AutoProductionService::class);
        $this->stokTutup(0);
        $usul = collect($svc->runAll(true))->firstWhere('bom_id', $this->tutupSisa->id);
        $this->assertTrue($usul['simulasi']);
        $this->assertSame('BOM-TUTUP-SISA', $usul['bom_number']);
        $this->assertCount(1, $svc->runAll(true), 'BOM alternatif non-auto tidak diperiksa sendiri.');

        $this->stokTutup(4); // tutup retur masuk sebelum disetujui
        $svc->runForBom($this->tutupSisa);

        $this->assertSame($this->pakaiTutup->id, $this->opBox()->bom_id);
    }

    public function test_tanpa_urutan_pakai_perilaku_lama(): void
    {
        $this->tutupSisa->update(['urutan_pakai' => null]);
        $this->pakaiTutup->update(['urutan_pakai' => null]);
        $this->stokTutup(6);

        $hasil = app(AutoProductionService::class)->runForBom($this->tutupSisa);

        $this->assertSame($this->tutupSisa->id, $this->opBox()->bom_id);
        $this->assertStringNotContainsString('BOM:', $hasil['reason']);
    }

    public function test_skill_isi_antrean_cnc_ikut_memilih_bom(): void
    {
        $this->box->update(['produk_andalan' => true]);
        ProductStock::where('product_id', $this->box->id)->update(['qty_on_hand' => 100]);
        $this->stokTutup(6);

        app(IsiAntreanCncService::class)->jalankan(false, true, Carbon::parse('2026-10-26 10:00'));

        $op = $this->opBox();
        $this->assertSame($this->pakaiTutup->id, $op->bom_id);
        $this->assertStringContainsString('BOM: pakai BOM-PAKAI-TUTUP', $op->notes);
    }

    public function test_form_bom_menyimpan_urutan_pakai(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $isi = fn (?int $urutan) => [
            'name' => 'BOM-PAKAI-TUTUP', 'typical_cycles' => 1, 'urutan_pakai' => $urutan,
            'materials' => [['product_id' => $this->hitam->id, 'qty_per_cycle' => 2, 'unit' => 'Lembar']],
            'outputs'   => [['product_id' => $this->box->id, 'qty_per_cycle' => 26, 'output_type' => 'main', 'percentage' => 100]],
        ];

        $this->actingAs($admin)->put(route('production.boms.update', $this->pakaiTutup->id), $isi(3))->assertSessionHasNoErrors();
        $this->assertSame(3, $this->pakaiTutup->fresh()->urutan_pakai);

        $this->actingAs($admin)->put(route('production.boms.update', $this->pakaiTutup->id), $isi(null));
        $this->assertNull($this->pakaiTutup->fresh()->urutan_pakai);

        $this->actingAs($admin)->get(route('production.boms.edit', $this->tutupSisa->id))->assertOk()->assertSee('Urutan pakai');
    }

    public function test_skill_cnc_lewati_bila_bahan_semua_alternatif_kurang(): void
    {
        $this->box->update(['produk_andalan' => true]);
        ProductStock::where('product_id', $this->hitam->id)->update(['qty_on_hand' => 0]);

        $hasil = app(IsiAntreanCncService::class)->jalankan(false, true, Carbon::parse('2026-10-26 10:00'));

        $this->assertSame(0, ProductionOrder::count());
        $this->assertStringContainsString('bahan semua BOM alternatif kurang', collect($hasil)
            ->first(fn ($r) => $r['tingkat'] === 1 && $r['kelompok'] === IsiAntreanCncService::TUNGGAL)['reason']);
    }
}
