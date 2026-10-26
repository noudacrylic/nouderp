<?php

namespace Tests\Feature\KaryawanVirtual;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomMaterial;
use App\Modules\Production\Models\BomOutput;
use App\Modules\Production\Models\BomStep;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\IsiAntreanCncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill "Isi antrean CNC" per kelompok mesin: Mesin 1 (double head, products.cnc_kepala_ganda)
 * dipantau tersendiri; antrean kosong total → 2 OP (Mesin 1 + Mesin 2 & 3).
 */
class KelompokMesinCncTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.isi-antrean-cnc';

    private Warehouse $utama;
    private Department $cnc;
    private Product $lembar;
    private Carbon $senin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utama  = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);
        Warehouse::firstOrCreate(['name' => 'Cadangan'], ['is_sellable' => false, 'is_active' => true]);
        $this->cnc    = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->lembar = Product::create(['sku' => 'LBR', 'name' => 'LBR', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true]);
        ProductStock::create(['product_id' => $this->lembar->id, 'warehouse_id' => $this->utama->id, 'qty_on_hand' => 100]);
        $this->senin  = Carbon::parse('2026-10-26 10:00');
    }

    /** Produk andalan ber-BOM CNC (Tingkat 1 selalu lolos selama belum ada OP). */
    private function andalan(string $sku, bool $mesin1): Product
    {
        $p = Product::create([
            'sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1,
            'is_active' => true, 'produk_andalan' => true, 'cnc_kepala_ganda' => $mesin1, 'min_stock' => 1,
        ]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->utama->id, 'qty_on_hand' => 500]);
        $bom = Bom::create(['bom_number' => 'BOM-' . $sku, 'name' => $sku, 'typical_cycles' => 1]);
        BomMaterial::create(['bom_id' => $bom->id, 'product_id' => $this->lembar->id, 'qty_per_cycle' => 1, 'unit' => 'Lembar']);
        BomOutput::create(['bom_id' => $bom->id, 'product_id' => $p->id, 'qty_per_cycle' => 10, 'output_type' => 'main', 'percentage' => 100]);
        BomStep::create(['bom_id' => $bom->id, 'step_number' => 1, 'department_id' => $this->cnc->id, 'name' => 'CNC']);
        return $p;
    }

    /** Pekerjaan CNC yang sedang mengantre untuk produk $p (bukan andalan, tak ikut dipilih). */
    private function antre(bool $mesin1): void
    {
        static $n = 0;
        $n++;
        $p = Product::create(['sku' => "ANTRE-{$n}", 'name' => 'x', 'sale_type' => 'ready', 'base_unit' => 'pcs', 'base_price' => 1, 'cnc_kepala_ganda' => $mesin1]);
        $op = ProductionOrder::create([
            'order_number' => "OP-ANTRE-{$n}", 'type' => 'ready_stock', 'warehouse_id' => $this->utama->id,
            'production_date' => now()->toDateString(), 'planned_cycles' => 1, 'status' => 'confirmed',
            'score_type' => 'auto', 'created_via' => 'manual',
        ]);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $p->id, 'qty_planned' => 10, 'output_type' => 'main', 'percentage' => 100]);
        ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => $this->cnc->id, 'name' => 'CNC', 'status' => 'pending']);
    }

    private function isi(): array
    {
        return app(IsiAntreanCncService::class)->jalankan(false, true, $this->senin);
    }

    private function hasilPengisi(): array
    {
        return ProductionOrder::with('outputs.product')->where('kv_skill', 'like', self::SKILL . ':%')->orderBy('id')->get()
            ->map(fn ($op) => $op->outputs->firstWhere('output_type', 'main')->product->sku)->all();
    }

    public function test_antrean_hanya_mesin_2_3_diisi_satu_op_mesin_1(): void
    {
        $this->andalan('TBKD-A', true);
        $this->andalan('BC-A', false);
        $this->antre(false);

        $hasil = $this->isi();

        $this->assertSame(['TBKD-A'], $this->hasilPengisi());
        $this->assertStringContainsString('tak satu pun produk Mesin 1', collect($hasil)->firstWhere('jenis', 'antrean')['reason']);
    }

    public function test_antrean_masih_ada_produk_mesin_1_tidak_diisi(): void
    {
        $this->andalan('TBKD-A', true);
        $this->antre(true);

        $hasil = $this->isi();

        $this->assertSame([], $this->hasilPengisi());
        $this->assertStringContainsString('1 di antaranya produk Mesin 1', $hasil[0]['reason']);
    }

    public function test_antrean_kosong_total_dua_op_tiap_kelompok(): void
    {
        $this->andalan('TBKD-A', true);
        $this->andalan('BC-A', false);

        $this->isi();

        $this->assertSame(['TBKD-A', 'BC-A'], $this->hasilPengisi());
        $this->assertStringContainsString('(Mesin 1)', ProductionOrder::where('kv_skill', 'like', self::SKILL . ':%')->first()->notes);
    }

    public function test_dua_usulan_antrean_kosong_bisa_disetujui_berurutan(): void
    {
        Carbon::setTestNow($this->senin);
        $this->andalan('TBKD-A', true);
        $this->andalan('BC-A', false);
        $kv = app(KaryawanVirtualService::class);

        $kv->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_JADWAL);
        $usulan = KaryawanVirtualUsulan::where('skill', self::SKILL)->orderBy('id')->get();
        $this->assertCount(2, $usulan);
        $this->assertStringContainsString('Isi antrean CNC Mesin 1', $usulan[0]->judul);

        foreach ($usulan as $u) {
            $this->assertTrue($kv->skill(self::SKILL)->kerjakanUsulan($u->data)[0]['created']);
        }
        $this->assertSame(['TBKD-A', 'BC-A'], $this->hasilPengisi());
        Carbon::setTestNow();
    }

    public function test_halaman_pengaturan_simpan_kelompok_mesin_dan_andalan(): void
    {
        $tb = $this->andalan('TB-13-T1-M5', false);
        $bc = $this->andalan('BC-A', false);
        $andi = DepartmentExecutor::create(['department_id' => $this->cnc->id, 'name' => 'Andi', 'is_active' => true]);
        $m1 = DepartmentExecutor::create(['department_id' => $this->cnc->id, 'name' => 'Mesin 1', 'parent_executor_id' => $andi->id, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('karyawan-virtual.show', 'manager-produksi'))->assertOk()->assertSee('⚙ Pengaturan');
        $this->actingAs($admin)->get(route('karyawan-virtual.cnc.pengaturan', 'manager-produksi'))
            ->assertOk()->assertSee('TB-13-T1-M5')->assertSee('Mesin 1');

        $this->actingAs($admin)->post(route('karyawan-virtual.cnc.pengaturan.simpan', 'manager-produksi'), [
            'produk' => [$tb->id, $bc->id], 'ganda' => [$tb->id], 'andalan' => [$bc->id],
            'mesin' => [$m1->id], 'kepala_ganda' => [$m1->id],
        ])->assertSessionHas('success');

        $this->assertTrue($tb->fresh()->cnc_kepala_ganda);
        $this->assertFalse($tb->fresh()->produk_andalan);
        $this->assertFalse($bc->fresh()->cnc_kepala_ganda);
        $this->assertTrue($bc->fresh()->produk_andalan);
        $this->assertTrue($m1->fresh()->kepala_ganda);
    }

    public function test_pengaturan_hanya_boleh_diubah_yang_berhak(): void
    {
        $p = $this->andalan('BC-A', false);
        $staf = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($staf)->post(route('karyawan-virtual.cnc.pengaturan.simpan', 'manager-produksi'), [
            'produk' => [$p->id], 'ganda' => [$p->id],
        ])->assertForbidden();
        $this->assertFalse($p->fresh()->cnc_kepala_ganda);
    }
}
