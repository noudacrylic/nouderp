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
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Production\Services\QcService;
use App\Modules\Tasks\MejaKerja\Skills\QcSkill;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QC Timbang sebagai pemeriksaan (5 Nov 2026), bukan kerja produksi: langkahnya hanya penanda OP
 * belum boleh ditutup, tanpa timer & penimbang, tidak tampil di papan produksi, dan dikerjakan
 * Admin Layanan lewat Meja Kerja.
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

    private function mulai(ProductionOrder $order): ProductionOrderStep
    {
        $asm = $this->langkah($order, 1);
        $this->svc->startStep($asm->id, [$this->budi->id]);

        return $asm->fresh();
    }

    private function kartu(): array
    {
        return array_values(iterator_to_array(app(QcSkill::class)->pindai(now()->subDay()), false));
    }

    public function test_mulai_produksi_menambah_langkah_qc_dan_assembling_bukan_penutup(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);

        $qc = $this->langkah($order, 2);
        $this->assertTrue($qc->is_qc);
        $this->assertSame('pending', $qc->status);

        $this->assertFalse($this->svc->completeStep($asm->id));
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    /** Regresi OP/2026/11/00044: CNC → Assembling; QC sempat bernomor 2 lalu terlewat saat Assembling ditutup. */
    public function test_op_dua_langkah_qc_bernomor_terakhir_dan_tidak_terlewat(): void
    {
        $cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $andi = DepartmentExecutor::create(['department_id' => $cnc->id, 'name' => 'Andi', 'is_active' => true]);
        $order = $this->op();
        $this->langkah($order, 1)->update(['step_number' => 2]);
        ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $cnc->id, 'name' => 'CNC', 'status' => 'pending']);

        $langkahCnc = $this->langkah($order, 1);
        $this->svc->startStep($langkahCnc->id, [$andi->id]);

        $qc = $order->steps()->where('is_qc', true)->firstOrFail();
        $this->assertSame(3, $qc->step_number);
        $this->assertSame($this->assembling->id, $qc->department_id);

        $this->assertFalse($this->svc->completeStep($langkahCnc->id));
        $asm = $this->langkah($order, 2);
        $this->svc->startStep($asm->id, [$this->budi->id]);
        $this->assertFalse($this->svc->completeStep($asm->id), 'Masih ada langkah QC.');
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_menimbang_tanpa_penimbang_dan_tanpa_timer(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->svc->completeStep($asm->id);

        $user = \App\Models\User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $check = $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, $user->id);

        $this->assertSame($user->id, $check->user_id, 'Yang menimbang tercatat dari akun yang login.');
        $qc = $this->langkah($order, 2);
        $this->assertSame('pending', $qc->status, 'Tidak ada status berjalan.');
        $this->assertNull($qc->started_at);
        $this->assertSame(0, $qc->executors()->count());
        $this->assertSame(0, $qc->timeLogs()->count(), 'Kalender Produksi membaca log ini.');
    }

    public function test_menimbang_tidak_menjeda_langkah_produksi_yang_berjalan(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);

        $this->assertSame('in_progress', $asm->fresh()->status);
    }

    public function test_alur_penuh_sampai_op_tertutup_tanpa_jejak_waktu_qc(): void
    {
        $order = $this->op(2);
        $asm = $this->mulai($order);
        $this->svc->completeStep($asm->id);
        $qc = $this->langkah($order, 2);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);
        $this->qc->catatTimbang($order->id, $this->produk, 856, 'normal', 'simulasi', null, null);

        $out = $order->outputs->first()->id;
        $this->assertTrue($this->svc->completeStep($qc->id, null, null, [['output_id' => $out, 'qty_produced' => 2]]));
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(0, $qc->timeLogs()->count());

        $this->expectExceptionMessage('belum dimulai');
        $this->svc->completeStep($qc->id);
    }

    public function test_tutup_qc_ditolak_selama_produksi_belum_selesai(): void
    {
        $order = $this->op();
        $this->mulai($order);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);

        $this->expectExceptionMessage('belum selesai');
        $this->svc->completeStep($this->langkah($order, 2)->id, null, null,
            [['output_id' => $order->outputs->first()->id, 'qty_produced' => 1]]);
    }

    public function test_tutup_qc_ditolak_melebihi_lulus_atau_ada_perbaikan(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->svc->completeStep($asm->id);
        $this->qc->catatTimbang($order->id, $this->produk, 855, 'normal', 'simulasi', null, null);
        $this->qc->catatTimbang($order->id, $this->produk, 735, 'normal', 'simulasi', null, null);

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
        $this->assertSame('pending', $qc->fresh()->status);

        $this->qc->catatTimbang($order->id, $this->produk, 855, 'perbaikan', 'simulasi', null, null);
        $this->assertTrue($this->svc->completeStep($qc->id, null, null, [['output_id' => $out, 'qty_produced' => 2]]));
    }

    public function test_masuk_stok_sebagian_boleh_selagi_produksi_berjalan_dan_setelah_produksi_selesai(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $metode = new \ReflectionMethod($this->svc, 'assertPartialAllowed');

        $metode->invoke($this->svc, $order->fresh(['steps']));   // selagi Assembling jalan

        $this->svc->completeStep($asm->id);
        $metode->invoke($this->svc, $order->fresh(['steps']));   // setelah Assembling selesai
        $this->addToAssertionCount(2);
    }

    public function test_kartu_qc_muncul_setelah_produksi_selesai_untuk_admin_layanan(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->assertSame([], $this->kartu(), 'Produksi jalan & tidak ada permintaan: belum ada kartu.');

        $this->svc->completeStep($asm->id);

        $kartu = $this->kartu();
        $this->assertCount(1, $kartu);
        $this->assertSame('qc:step:' . $this->langkah($order, 2)->id, $kartu[0]['kunci']);
        $this->assertStringStartsWith('QC OP-', $kartu[0]['judul']);
        $this->assertSame('layanan', app(QcSkill::class)->peranBawaan());
    }

    public function test_minta_qc_sebagian_memberi_kartu_lalu_selesai_saat_masuk_stok(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $qc = $this->langkah($order, 2);

        $this->svc->mintaQcSebagian($asm->id);
        $this->assertNotNull($qc->fresh()->qc_diminta_at);
        $this->assertSame('in_progress', $asm->fresh()->status, 'Timer Assembling tidak terganggu.');

        $kartu = $this->kartu();
        $this->assertCount(1, $kartu);
        $this->assertStringStartsWith('QC sebagian ', $kartu[0]['judul']);
        $this->assertTrue($kartu[0]['meta']['sebagian']);

        $task = new Task(['taskable_id' => $order->id, 'meta' => $kartu[0]['meta']]);
        $this->assertNull(app(QcSkill::class)->keadaan($task), 'Masih menunggu ditimbang.');

        // Hasil sebagian masuk stok (jalur finalizePartial) → permintaan terjawab.
        $qc->fresh()->update(['qc_diminta_at' => null]);
        $this->assertSame('done', app(QcSkill::class)->keadaan($task)[0]);
        $this->assertSame([], $this->kartu());
    }

    public function test_kartu_sebagian_selesai_bila_produksi_rampung_dan_diganti_kartu_penutup(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->svc->mintaQcSebagian($asm->id);
        $task = new Task(['taskable_id' => $order->id, 'meta' => $this->kartu()[0]['meta']]);

        $this->svc->completeStep($asm->id);

        $this->assertSame('done', app(QcSkill::class)->keadaan($task)[0]);
        $this->assertFalse($this->kartu()[0]['meta']['sebagian']);
    }

    public function test_minta_qc_ditolak_dari_langkah_yang_tidak_berjalan(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->svc->completeStep($asm->id);

        $this->expectExceptionMessage('sedang dikerjakan');
        $this->svc->mintaQcSebagian($asm->id);
    }

    public function test_langkah_tambahan_disisipkan_sebelum_qc(): void
    {
        $order = $this->op();
        $this->mulai($order);

        $baru = $this->svc->addStep($order->id, $this->assembling->id);

        $this->assertSame(2, $baru->step_number);
        $this->assertTrue($this->langkah($order, 3)->is_qc);
    }

    public function test_papan_produksi_tanpa_kartu_qc_dan_layar_qc_tanpa_penimbang(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('production.process.index'))
            ->assertOk()->assertSee('Minta QC sebagian')->assertDontSee('Timbang QC');

        $this->actingAs($admin)->post(route('production.process.steps.minta-qc', $asm->id));
        $this->actingAs($admin)->get(route('production.process.index'))
            ->assertOk()->assertSee('QC sebagian sudah diminta');

        $asm->fresh()->update(['status' => 'in_progress']);
        $this->svc->completeStep($asm->id);
        $qc = $this->langkah($order, 2);

        $this->actingAs($admin)->get(route('production.process.index'))->assertOk()->assertDontSee('Timbang QC');

        $this->actingAs($admin)->get(route('production.qc.index', ['op' => $order->id]))
            ->assertOk()->assertDontSee('Penimbang')->assertDontSee('Timer QC')->assertSee('Selesai QC')
            ->assertSee('Kembalikan ke produksi');

        // Menutup QC: modal Selesai terbuka untuk langkah yang diminta saja.
        $this->actingAs($admin)->get(route('production.process.index', ['selesai' => $qc->id]))
            ->assertOk()->assertSee('open-selesai', false)->assertSee('lulus QC');
    }

    public function test_salah_tekan_selesai_bisa_dikembalikan_ke_produksi(): void
    {
        $order = $this->op();
        $asm = $this->mulai($order);
        $this->svc->completeStep($asm->id);
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('production.process.steps.revert', $this->langkah($order, 2)->id));

        $this->assertSame('pending', $asm->fresh()->status);
        $this->assertSame([], $this->kartu());
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
