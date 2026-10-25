<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Modules\KaryawanVirtual\Contracts\Skill;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomMaterial;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\BomStep;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\NmDariBnmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill "Buat OP Nama Meja dari BNM": aturan 150 / 50–149 / <50 + potong lembar.
 */
class OpNmDariBnmTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.op-nm-dari-bnm';

    private Warehouse $gudang;
    private Department $cnc;
    private Department $rakit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        $this->cnc    = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->rakit  = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
    }

    private function produk(string $sku, float $stok, ?float $min = null): Product
    {
        $p = Product::create([
            'sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'made_to_order' => false,
            'base_unit' => 'pcs', 'base_price' => 10000, 'is_active' => true, 'min_stock' => $min,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stok]);
        return $p;
    }

    /** [NM, BNM] — NM menipis (stok 10, min 25). */
    private function pasangan(float $stokBnm, string $ukuran = '7X30-M1'): array
    {
        return [$this->produk("NM-{$ukuran}", 10, 25), $this->produk("BNM-{$ukuran}", $stokBnm)];
    }

    /** BOM potong lembar NM (auto-produksi mati, seperti di data asli). */
    private function bomPotong(Product $nm, float $perSiklus, bool $auto = false): Bom
    {
        $lembar = $this->produk('LBR-' . uniqid(), 100);
        $bom = Bom::create(['bom_number' => 'BOM-' . $nm->sku, 'name' => $nm->sku, 'auto_production' => $auto, 'typical_cycles' => 2]);
        BomMaterial::create(['bom_id' => $bom->id, 'product_id' => $lembar->id, 'qty_per_cycle' => 1, 'unit' => 'Lembar']);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $nm->id, 'qty_per_cycle' => $perSiklus, 'output_type' => 'main', 'percentage' => 100]);
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 1, 'department_id' => $this->cnc->id, 'name' => 'CNC']);
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 2, 'department_id' => $this->rakit->id, 'name' => 'Assembling']);
        return $bom;
    }

    private function jalankan(): array
    {
        return app(NmDariBnmService::class)->runAll();
    }

    private function opUntuk(Product $nm): ?ProductionOrder
    {
        return ProductionOrder::with(['materials', 'outputs', 'steps'])
            ->whereHas('outputs', fn ($q) => $q->where('product_id', $nm->id)->where('output_type', 'main'))
            ->first();
    }

    public function test_bnm_150_ke_atas_op_150_dari_150_bnm(): void
    {
        [$nm, $bnm] = $this->pasangan(686);
        $this->bomPotong($nm, 64);

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertNotNull($op);
        $this->assertNull($op->bom_id, 'Hanya dari BNM: tanpa BOM.');
        $this->assertSame('auto', $op->score_type);
        $this->assertSame('auto', $op->created_via);
        $this->assertEquals([[$bnm->id, 150.0]], $op->materials->map(fn ($m) => [$m->product_id, (float) $m->qty_required])->all());
        $this->assertEquals(150, (float) $op->outputs->firstWhere('output_type', 'main')->qty_planned);
        $this->assertSame(['Assembling'], $op->steps->pluck('name')->all(), 'BNM tinggal dirakit — tanpa CNC.');
    }

    public function test_bnm_50_sampai_149_op_sebanyak_bnm(): void
    {
        [$nm, $bnm] = $this->pasangan(83);
        $this->bomPotong($nm, 64);

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertEquals(83, (float) $op->materials->firstWhere('product_id', $bnm->id)->qty_required);
        $this->assertEquals(83, (float) $op->outputs->firstWhere('output_type', 'main')->qty_planned);
        $this->assertCount(1, $op->materials);
    }

    public function test_bnm_kurang_dari_50_ditambah_potong_lembar_mendekati_150(): void
    {
        // Contoh dari user: BNM 22, BOM NM 64/siklus → 22 + 2 × 64 = 150.
        [$nm, $bnm] = $this->pasangan(22);
        $bom = $this->bomPotong($nm, 64);

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertSame($bom->id, $op->bom_id);
        $this->assertEquals(2, (float) $op->planned_cycles);
        $this->assertEquals(150, (float) $op->outputs->firstWhere('output_type', 'main')->qty_planned);
        $this->assertEquals(22, (float) $op->materials->firstWhere('product_id', $bnm->id)->qty_required);
        $this->assertEquals(2, (float) $op->materials->firstWhere('product_id', '!=', $bnm->id)->qty_required, '2 lembar.');
        $this->assertSame(['CNC', 'Assembling'], $op->steps->pluck('name')->all());
    }

    public function test_siklus_dipilih_yang_paling_dekat_150(): void
    {
        // BNM 30, 96/siklus: 1 siklus → 126 (selisih 24), 2 siklus → 222 (selisih 72) → 1 siklus.
        [$nm] = $this->pasangan(30, '7X20-M1');
        $this->bomPotong($nm, 96);

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertEquals(1, (float) $op->planned_cycles);
        $this->assertEquals(126, (float) $op->outputs->firstWhere('output_type', 'main')->qty_planned);
    }

    public function test_bnm_habis_tetap_potong_lembar_minimal_satu_siklus(): void
    {
        [$nm, $bnm] = $this->pasangan(0);
        $this->bomPotong($nm, 96);

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertEquals(2, (float) $op->planned_cycles, '150/96 = 1,56 → 2 siklus (192).');
        $this->assertNull($op->materials->firstWhere('product_id', $bnm->id), 'BNM 0 tidak ikut jadi bahan.');
    }

    public function test_tanpa_bom_potong_pakai_bnm_yang_ada(): void
    {
        [$nm, $bnm] = $this->pasangan(20, '4X6-M1-2');

        $this->jalankan();
        $op = $this->opUntuk($nm);

        $this->assertEquals(20, (float) $op->outputs->firstWhere('output_type', 'main')->qty_planned);
        $this->assertSame([$this->rakit->id], $op->steps->pluck('department_id')->all(), 'Langkah = departemen Assembling.');
    }

    public function test_tanpa_bom_potong_dan_bnm_habis_dilewati(): void
    {
        [$nm] = $this->pasangan(0, '4X6-M1-2');

        $hasil = $this->jalankan();

        $this->assertNull($this->opUntuk($nm));
        $this->assertStringContainsString('tidak punya BOM potong', $hasil[0]['reason']);
    }

    public function test_stok_nm_aman_atau_op_aktif_tidak_membuat_op(): void
    {
        $nm = $this->produk('NM-7X25-M2', 100, 25);
        $this->produk('BNM-7X25-M2', 200);

        $this->jalankan();
        $this->assertNull($this->opUntuk($nm), 'Stok 100 > min 25.');

        [$nm2] = $this->pasangan(200);
        $this->jalankan();
        $this->jalankan();
        $this->assertSame(1, ProductionOrder::whereHas('outputs', fn ($q) => $q->where('product_id', $nm2->id))->count(), 'Tak dobel.');
    }

    public function test_bom_nm_auto_aktif_diserahkan_ke_skill_stok_menipis(): void
    {
        [$nm] = $this->pasangan(200);
        $this->bomPotong($nm, 64, auto: true);

        $hasil = $this->jalankan();

        $this->assertNull($this->opUntuk($nm));
        $this->assertStringContainsString('ditangani skill stok menipis', $hasil[0]['reason']);
    }

    public function test_skill_bawaan_minta_persetujuan_dan_berlabel_percobaan(): void
    {
        [$nm] = $this->pasangan(200);
        $kv = app(KaryawanVirtualService::class);

        $this->assertSame(Skill::PERSETUJUAN, $kv->mode(self::SKILL));
        $this->assertTrue($kv->dalamPercobaan(self::SKILL));
        $this->assertFalse($kv->dalamPercobaan('produksi.op-stok-menipis'));

        $hasil = $kv->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_JADWAL);

        $this->assertNull($this->opUntuk($nm), 'Belum disetujui → belum ada OP.');
        $usulan = KaryawanVirtualUsulan::where('skill', self::SKILL)->sole();
        $this->assertSame('nm:' . $nm->id, $usulan->kunci);
        $this->assertStringContainsString('150 pcs', $usulan->alasan);
        $this->assertSame(Skill::DIUSULKAN, $hasil['rincian'][0]['status']);
    }
}
