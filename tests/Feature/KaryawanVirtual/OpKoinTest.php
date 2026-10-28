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
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\OpKoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Skill "Buat OP koin kotak saran": 4 koin dari satu BOM, pemicu koin mana pun,
 * lembar dibagi supaya stok sesudah produksi TPM ≈ 3× koin lain.
 */
class OpKoinTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'produksi.op-koin';

    private Warehouse $gudang;
    private array $koin = [];
    private Bom $bom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang = Warehouse::firstOrCreate(['name' => 'Gudang Utama'], ['is_sellable' => true, 'is_active' => true]);

        $lembar = $this->produk('LBR-122x244-2-TR', 0, null, 10);
        $this->bom = Bom::create(['bom_number' => 'BOM-KOIN', 'name' => 'Koin', 'auto_production' => false, 'typical_cycles' => 1]);
        BomMaterial::create(['bom_id' => $this->bom->id, 'product_id' => $lembar->id, 'qty_per_cycle' => 1, 'unit' => 'Lembar']);
        foreach (['KOIN-TPM' => [600, 1050, 'main'], 'KOIN-PH' => [300, 600, 'by_product'],
                  'KOIN-PK' => [300, 750, 'by_product'], 'KOIN-SPH' => [300, 600, 'by_product']] as $sku => [$min, $qty, $jenis]) {
            $this->koin[$sku] = $this->produk($sku, 1000, $min);
            BomOutput::create(['bom_id' => $this->bom->id, 'product_id' => $this->koin[$sku]->id,
                'qty_per_cycle' => $qty, 'output_type' => $jenis, 'percentage' => 25]);
        }
    }

    private function produk(string $sku, float $stok, ?float $min, ?float $stokAwal = null): Product
    {
        $p = Product::create(['sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'made_to_order' => false,
            'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'min_stock' => $min]);
        ProductStock::create(['product_id' => $p->id, 'warehouse_id' => $this->gudang->id, 'qty_on_hand' => $stokAwal ?? $stok]);
        return $p;
    }

    private function stok(array $stok): void
    {
        foreach ($stok as $sku => $qty) {
            ProductStock::where('product_id', $this->koin[$sku]->id)->update(['qty_on_hand' => $qty]);
        }
    }

    private function svc(): OpKoinService
    {
        return app(OpKoinService::class);
    }

    private function otomatis(): void
    {
        app(KaryawanVirtualService::class)->ubahMode(self::SKILL, Skill::OTOMATIS, null);
    }

    public function test_contoh_user_tpm_3x_koin_lain(): void
    {
        $lembar = $this->svc()->bagiLembar(['KOIN-TPM' => 1200, 'KOIN-PH' => 350, 'KOIN-PK' => 500, 'KOIN-SPH' => 400], 20);

        $this->assertSame(['KOIN-TPM' => 10, 'KOIN-PH' => 4, 'KOIN-PK' => 3, 'KOIN-SPH' => 3], $lembar);
    }

    public function test_koin_yang_stoknya_sudah_berlebih_tidak_dapat_lembar(): void
    {
        $lembar = $this->svc()->bagiLembar(['KOIN-TPM' => 9000, 'KOIN-PH' => 300, 'KOIN-PK' => 650, 'KOIN-SPH' => 350], 20);

        $this->assertSame(0, $lembar['KOIN-TPM'], 'TPM sudah jauh di atas 3× → semua lembar ke koin lain.');
        // (1.300 + 3.000) / 3 = 1.433 per koin → PH 7,56 · PK 5,22 · SPH 7,22 lembar.
        $this->assertSame([8, 5, 7], [$lembar['KOIN-PH'], $lembar['KOIN-PK'], $lembar['KOIN-SPH']], 'PH/PK/SPH diratakan.');
    }

    public function test_tambah_lembar_besar_bila_satu_belum_cukup(): void
    {
        $min = ['KOIN-TPM' => 600, 'KOIN-PH' => 300, 'KOIN-PK' => 300, 'KOIN-SPH' => 300];

        [$siklus] = $this->svc()->rencana(['KOIN-TPM' => 1200, 'KOIN-PH' => 350, 'KOIN-PK' => 500, 'KOIN-SPH' => 400], $min);
        $this->assertSame(1, $siklus);

        // Semua habis, min tinggi: 1 lembar besar → TPM 1.500 ≤ 2.000 → tambah; 2 lembar → TPM 3.000, lain 1.000.
        [$siklus, $lembar] = $this->svc()->rencana(['KOIN-TPM' => 0, 'KOIN-PH' => 0, 'KOIN-PK' => 0, 'KOIN-SPH' => 0],
            ['KOIN-TPM' => 2000, 'KOIN-PH' => 700, 'KOIN-PK' => 700, 'KOIN-SPH' => 700]);
        $this->assertSame(2, $siklus);
        $this->assertSame(40, array_sum($lembar));
    }

    public function test_pemicu_koin_selain_tpm_membuat_op_dengan_jumlah_hasil_diedit(): void
    {
        $this->otomatis();
        $this->stok(['KOIN-TPM' => 1200, 'KOIN-PH' => 290, 'KOIN-PK' => 500, 'KOIN-SPH' => 400]);

        $r = $this->svc()->periksa();

        $this->assertTrue($r['created'], $r['reason']);
        $op = ProductionOrder::with(['outputs', 'materials'])->where('order_number', $r['order_number'])->first();
        $hasil = $op->outputs->mapWithKeys(fn ($o) => [Product::find($o->product_id)->sku => (float) $o->qty_planned])->all();
        $this->assertEquals(['KOIN-TPM' => 1500, 'KOIN-PH' => 600, 'KOIN-PK' => 450, 'KOIN-SPH' => 450], $hasil);
        $this->assertSame('main', $op->outputs->firstWhere('product_id', $this->koin['KOIN-TPM']->id)->output_type);
        $this->assertEquals(100, round($op->outputs->sum('percentage')));
        $this->assertEquals(1, (float) $op->materials->first()->qty_required, '1 lembar besar.');
        $this->assertSame(self::SKILL, $op->kv_skill);
    }

    public function test_stok_aman_dan_op_berjalan_tidak_membuat_op(): void
    {
        $this->otomatis();
        $this->assertFalse($this->svc()->periksa()['created'], 'Semua 1.000 > min.');

        $this->stok(['KOIN-PK' => 100]);
        $this->assertTrue($this->svc()->periksa()['created']);

        $r = $this->svc()->periksa();
        $this->assertFalse($r['created']);
        $this->assertStringContainsString('Masih ada OP koin berjalan', $r['reason']);
    }

    public function test_mode_bawaan_minta_persetujuan(): void
    {
        $this->stok(['KOIN-SPH' => 100]);

        app(KaryawanVirtualService::class)->jalankan(self::SKILL, KaryawanVirtualService::PEMICU_JADWAL);

        $this->assertSame(0, ProductionOrder::count());
        $this->assertSame(1, KaryawanVirtualUsulan::where('skill', self::SKILL)->count());
    }
}
