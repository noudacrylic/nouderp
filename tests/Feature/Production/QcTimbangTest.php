<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Production\Models\ProductionFinalization;
use App\Modules\Production\Models\ProductionFinalizationItem;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\QcCheck;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Services\QcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QC Timbang produk knockdown (17 Okt 2026).
 *
 * Contoh tb-13-t1-m3: depan 1 (210 g), samping 2 (120 g), belakang 1 (210 g),
 * bawah 1 (180 g), plastik 15 g → paket 855 g. Toleransi 3% ≈ 25,7 g.
 */
class QcTimbangTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private int $produk;
    private QcService $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qc = app(QcService::class);
        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'tb-13-t1-m3', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
    }

    private function profil(array $ubah = []): QcProfile
    {
        return $this->qc->simpanProfil(array_merge([
            'product_id'     => $this->produk,
            'packaging_gram' => 15,
            'samples'        => [855, 856, 854],
            'components'     => [
                ['name' => 'Depan',    'qty' => 1, 'unit_gram' => 210],
                ['name' => 'Samping',  'qty' => 2, 'unit_gram' => 120],
                ['name' => 'Belakang', 'qty' => 1, 'unit_gram' => 210],
                ['name' => 'Bawah',    'qty' => 1, 'unit_gram' => 180],
            ],
        ], $ubah), null);
    }

    private function op(string $status = 'in_progress', int $target = 10): ProductionOrder
    {
        $order = ProductionOrder::create(['order_number' => 'OP-' . uniqid(), 'type' => 'ready_stock',
            'warehouse_id' => $this->gudang, 'planned_cycles' => $target, 'planned_qty' => $target,
            'production_date' => now()->toDateString(), 'status' => $status]);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => $target, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 0]);

        return $order->fresh('outputs');
    }

    private function rows(ProductionOrder $order, float $qty): array
    {
        return [['rec' => $order->outputs->first(), 'qty' => $qty, 'failed' => 0, 'input' => []]];
    }

    public function test_berat_dalam_toleransi_lulus(): void
    {
        $a = $this->qc->analisa($this->profil(), 860);

        $this->assertSame(QcCheck::LULUS, $a['result']);
        $this->assertSame(855.0, $a['target']);
    }

    public function test_kurang_satu_samping_terdeteksi(): void
    {
        $a = $this->qc->analisa($this->profil(), 736);

        $this->assertSame(QcCheck::KURANG, $a['result']);
        $this->assertSame('1× Samping', $a['kandidat'][0]['label']);
    }

    public function test_komponen_berberat_sama_disebut_a_atau_b(): void
    {
        $a = $this->qc->analisa($this->profil(), 645);

        $this->assertTrue($a['kandidat'][0]['ambigu']);
        $this->assertStringContainsString('Depan', $a['kandidat'][0]['label']);
        $this->assertStringContainsString('Belakang', $a['kandidat'][0]['label']);
    }

    public function test_komponen_ringan_ditandai_tak_terdeteksi(): void
    {
        $profil = $this->profil(['components' => [
            ['name' => 'Panel', 'qty' => 1, 'unit_gram' => 800],
            ['name' => 'Magnet', 'qty' => 2, 'unit_gram' => 3],
        ]]);

        $this->assertSame(['Magnet'], $this->qc->komponenTakTerdeteksi($profil)->pluck('name')->all());
    }

    public function test_gagal_lalu_perbaikan_menutup_antrean(): void
    {
        $this->profil();
        $order = $this->op();

        $gagal = $this->qc->catatTimbang($order->id, $this->produk, 736, 'normal', 'simulasi', null, null);
        $this->assertSame('menunggu', $gagal->repair_status);
        $this->assertSame(1, $this->qc->ringkasan($order, $this->produk)['menunggu']);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'perbaikan', 'simulasi', null, null);

        $r = $this->qc->ringkasan($order, $this->produk);
        $this->assertSame(0, $r['menunggu']);
        $this->assertSame(1, $r['lulus']);
        $this->assertSame('diperbaiki', $gagal->fresh()->repair_status);
    }

    public function test_finalisasi_ditolak_melebihi_jumlah_lulus(): void
    {
        $this->profil();
        $order = $this->op();
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);
        $this->qc->catatTimbang($order->id, $this->produk, 856, 'normal', 'simulasi', null, null);

        $this->qc->assertBolehFinalisasi($order, $this->rows($order, 2), false);

        $this->expectExceptionMessage('baru 2 bungkus');
        $this->qc->assertBolehFinalisasi($order, $this->rows($order, 3), false);
    }

    public function test_yang_sudah_difinalisasi_mengurangi_jatah(): void
    {
        $this->profil();
        $order = $this->op();
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);

        $batch = ProductionFinalization::create(['production_order_id' => $order->id, 'sequence' => 1,
            'is_closing' => false, 'wip_released' => 0, 'wip_total_snapshot' => 0]);
        ProductionFinalizationItem::create(['production_finalization_id' => $batch->id,
            'production_order_output_id' => $order->outputs->first()->id, 'product_id' => $this->produk,
            'qty' => 2, 'cost' => 0, 'unit_cost' => 0]);

        $this->assertSame(0.0, $this->qc->ringkasan($order, $this->produk)['boleh']);
        $this->expectException(\Exception::class);
        $this->qc->assertBolehFinalisasi($order, $this->rows($order, 1), false);
    }

    public function test_penutupan_ditolak_selama_ada_yang_menunggu_perbaikan(): void
    {
        $this->profil();
        $order = $this->op();
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);
        $this->qc->catatTimbang($order->id, $this->produk, 700, 'normal', 'simulasi', null, null);

        $this->expectExceptionMessage('menunggu perbaikan');
        $this->qc->assertBolehFinalisasi($order, $this->rows($order, 1), true);
    }

    public function test_op_lama_sebelum_profil_dibebaskan(): void
    {
        $order = $this->op();
        $order->forceFill(['created_at' => now()->subDay()])->save();
        $this->profil();

        $this->qc->assertBolehFinalisasi($order->fresh('outputs'), $this->rows($order, 5), true);
        $this->assertTrue(true);
    }

    public function test_pelepasan_admin_dihitung_lulus(): void
    {
        $this->profil();
        $order = $this->op();
        $this->qc->lepas($order->id, $this->produk, 3, 'Timbangan rusak', null);

        $this->assertSame(3.0, $this->qc->ringkasan($order, $this->produk)['boleh']);
    }

    public function test_acuan_berubah_diarsipkan(): void
    {
        $profil = $this->profil();
        $this->qc->simpanProfil(['product_id' => $this->produk, 'packaging_gram' => 15, 'samples' => [880, 882],
            'batch_note' => 'Lembaran batch Okt', 'components' => [['name' => 'Depan', 'qty' => 1, 'unit_gram' => 220]]],
            null, $profil);

        $this->assertSame(1, $profil->history()->count());
        $this->assertSame(855.0, (float) $profil->history()->first()->snapshot['target_gram']);
        $this->assertSame(881.0, $profil->fresh()->target_gram);
    }
}
