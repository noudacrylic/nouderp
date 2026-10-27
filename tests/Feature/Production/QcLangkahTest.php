<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\ProductionSetting;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\QcCheck;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Production\Services\QcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QC Timbang sebagai langkah terakhir OP (23 Okt 2026): ditambahkan otomatis, timernya
 * berjalan dari penimbangan, dan langkah QC menjadi penutup produksi.
 */
class QcLangkahTest extends TestCase
{
    use RefreshDatabase;

    private int $produk;
    private int $gudang;
    private Department $assembling;
    private DepartmentExecutor $budi;
    private QcService $qc;
    private ProductionOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qc  = app(QcService::class);
        $this->svc = app(ProductionOrderService::class);

        // Mode testing: lewati cek scan fingerprint & jadwal eksekutor.
        ProductionSetting::create(['testing_mode' => true]);

        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'tb-13-t1-m3', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
        $this->assembling = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);
        $this->budi = DepartmentExecutor::create(['department_id' => $this->assembling->id, 'name' => 'Budi', 'is_active' => true]);

        $this->qc->simpanProfil([
            'product_id'     => $this->produk,
            'packaging_gram' => 15,
            'samples'        => [855],
            'components'     => [['name' => 'Samping', 'qty' => 2, 'unit_gram' => 120]],
        ], null);
    }

    /** OP dikonfirmasi dengan satu langkah Assembling, dibuat SETELAH profil QC. */
    private function op(int $target = 3): ProductionOrder
    {
        $this->travel(1)->seconds();
        $order = ProductionOrder::create(['order_number' => 'OP-' . uniqid(), 'type' => 'ready_stock',
            'warehouse_id' => $this->gudang, 'planned_cycles' => $target, 'planned_qty' => $target,
            'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => $target, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $this->assembling->id, 'name' => 'Assembling', 'status' => 'pending']);

        return $order->fresh(['outputs', 'steps']);
    }

    private function langkah(ProductionOrder $order, int $nomor): ProductionOrderStep
    {
        return $order->steps()->where('step_number', $nomor)->firstOrFail();
    }

    public function test_mulai_produksi_menambah_langkah_qc_di_ujung(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);

        $qc = $this->langkah($order, 2);
        $this->assertTrue($qc->is_qc);
        $this->assertSame('pending', $qc->status);
        $this->assertSame($this->assembling->id, $qc->department_id, 'sementara ikut divisi langkah terakhir');

        // Selesai Assembling bukan lagi penutup: OP berlanjut ke QC.
        $this->assertFalse($this->svc->completeStep($this->langkah($order, 1)->id));
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_divisi_qc_dari_setting(): void
    {
        $qcDept = Department::create(['code' => 'QC', 'name' => 'QC', 'type' => 'produksi', 'is_active' => true]);
        ProductionSetting::first()->forceFill(['qc_department_id' => $qcDept->id])->save();

        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);

        $this->assertSame($qcDept->id, $this->langkah($order, 2)->department_id);
    }

    public function test_timbang_pertama_menjalankan_timer_qc(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);
        $this->svc->completeStep($this->langkah($order, 1)->id);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);

        $qc = $this->langkah($order, 2);
        $this->assertSame('in_progress', $qc->status);
        $this->assertSame([$this->budi->id], $qc->executors()->pluck('production_department_executors.id')->all());
        $this->assertSame('started', $qc->timeLogs()->first()->event_type);

        // Jeda (hasil sebagian masuk stok) → timbang lagi melanjutkan timer.
        $this->svc->pauseQcTimer($order->id);
        $this->assertSame('paused', $qc->fresh()->status);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);
        $this->assertSame('in_progress', $qc->fresh()->status);
        $this->assertSame('resumed', $qc->timeLogs()->get()->last()->event_type);
    }

    public function test_tanpa_penimbang_ditolak_dan_tidak_tercatat(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);
        $this->svc->completeStep($this->langkah($order, 1)->id);

        try {
            $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, []);
            $this->fail('Seharusnya ditolak');
        } catch (\Exception $e) {
            $this->assertStringContainsString('penimbang', $e->getMessage());
        }
        $this->assertSame(0, QcCheck::count());
    }

    public function test_qc_sebagian_menjeda_assembling_penimbang(): void
    {
        $order = $this->op();
        $asm = $this->langkah($order, 1);
        $this->svc->startStep($asm->id, [$this->budi->id]);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);

        $this->assertSame('paused', $asm->fresh()->status, 'satu jam tidak dihitung dua kali');
        $this->assertSame('in_progress', $this->langkah($order, 2)->status);
        $this->assertCount(1, $this->qc->jedaOtomatis);

        // Assembling dilanjutkan lalu diselesaikan — QC yang sudah jalan tidak kembali ke antre.
        $asm->update(['status' => 'in_progress', 'paused_at' => null]); // resumeStep tak mengenal mode testing
        $this->svc->completeStep($asm->id);
        $this->assertSame('in_progress', $this->langkah($order, 2)->status);
    }

    public function test_tutup_qc_ditolak_selama_produksi_belum_selesai(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);

        $this->expectExceptionMessage('belum selesai');
        $this->svc->completeStep($this->langkah($order, 2)->id, null, null,
            [['output_id' => $order->outputs->first()->id, 'qty_produced' => 1]]);
    }

    public function test_tutup_qc_ditolak_melebihi_lulus_atau_ada_perbaikan(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);
        $this->svc->completeStep($this->langkah($order, 1)->id);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);
        $this->qc->catatTimbang($order->id, $this->produk, 735, 'normal', 'simulasi', null, null, [$this->budi->id]);

        $qc = $this->langkah($order, 2);
        $out = $order->outputs->first()->id;

        try {
            $this->svc->completeStep($qc->id, null, null, [['output_id' => $out, 'qty_produced' => 2]]);
            $this->fail('Seharusnya ditolak: hanya 1 lulus');
        } catch (\Exception $e) {
            $this->assertStringContainsString('baru 1 bungkus', $e->getMessage());
        }

        try {
            $this->svc->completeStep($qc->id, null, null, [['output_id' => $out, 'qty_produced' => 1]]);
            $this->fail('Seharusnya ditolak: 1 bungkus menunggu perbaikan');
        } catch (\Exception $e) {
            $this->assertStringContainsString('menunggu perbaikan', $e->getMessage());
        }
        $this->assertSame('in_progress', $qc->fresh()->status);

        // Diperbaiki → lulus → QC boleh ditutup (OP selesai semua langkah).
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'perbaikan', 'simulasi', null, null, [$this->budi->id]);
        $this->assertTrue($this->svc->completeStep($qc->id, null, null, [['output_id' => $out, 'qty_produced' => 2]]));
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_langkah_tambahan_disisipkan_sebelum_qc(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);

        $baru = $this->svc->addStep($order->id, $this->assembling->id);

        $this->assertSame(2, $baru->step_number);
        $this->assertTrue($this->langkah($order, 3)->is_qc);
    }

    public function test_kartu_proses_dan_layar_qc_tampil(): void
    {
        $order = $this->op();
        $this->svc->startStep($this->langkah($order, 1)->id, [$this->budi->id]);
        $this->svc->completeStep($this->langkah($order, 1)->id);
        $qc = $this->langkah($order, 2);
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        // Antrean: kartu QC tanpa tombol Mulai, ada Timbang QC.
        $this->actingAs($admin)->get(route('production.process.index'))
            ->assertOk()->assertSee('Timbang QC')->assertSee('QC Timbang');

        $this->actingAs($admin)->get(route('production.qc.index', ['op' => $order->id]))
            ->assertOk()->assertSee('Penimbang')->assertSee('Budi');

        // Setelah ditimbang: tombol tutup QC mengarah ke modal Selesai di Proses Produksi.
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null, [$this->budi->id]);
        $this->actingAs($admin)->get(route('production.qc.index', ['op' => $order->id]))
            ->assertOk()->assertSee('Selesai QC');
        $this->actingAs($admin)->get(route('production.process.index', ['selesai' => $qc->id]))
            ->assertOk()->assertSee('open-selesai', false)->assertSee('lulus QC');
    }

    public function test_op_lama_tanpa_langkah_qc(): void
    {
        $this->travel(-1)->days();
        $order = ProductionOrder::create(['order_number' => 'OP-LAMA', 'type' => 'ready_stock',
            'warehouse_id' => $this->gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        $this->travelBack();
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => 1, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        $step = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $this->assembling->id, 'name' => 'Assembling', 'status' => 'pending']);

        $this->svc->startStep($step->id, [$this->budi->id]);

        $this->assertSame(1, $order->steps()->count());
    }
}
