<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\ProductionSetting;
use App\Models\User;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan as Usulan;
use App\Modules\KaryawanVirtual\Services\KaryawanVirtualService;
use App\Modules\KaryawanVirtual\Services\UsulanService;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Services\PergantianPelaksanaService;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\SDM\Models\Karyawan;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pelaksana langkah diganti di tengah jalan (1 Nov 2026, Ridwan resign):
 * - karyawan dinonaktifkan → langkahnya dilepas jadi "Tanpa pelaksana", dilanjutkan siapa saja;
 * - oper tugas → usulan Manager Produksi ke Telegram, dikerjakan setelah disetujui owner;
 * - keduanya mencoret LANGKAH itu (divisinya) dari sampel waktu, divisi lain tetap.
 */
class PergantianPelaksanaTest extends TestCase
{
    use RefreshDatabase;

    private int $produk;
    private int $gudang;
    private Department $cnc;
    private Department $asm;
    private Karyawan $ridwan;
    private DepartmentExecutor $exRidwan;
    private DepartmentExecutor $exBudi;
    private DepartmentExecutor $exSari;
    private ProductionOrderService $svc;
    private PergantianPelaksanaService $ganti;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => 'TOKEN-UJI']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        ProductionSetting::create(['testing_mode' => true]);

        $this->svc   = app(ProductionOrderService::class);
        $this->ganti = app(PergantianPelaksanaService::class);

        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'tb-1', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
        $this->cnc = Department::create(['code' => 'CNC', 'name' => 'CNC', 'type' => 'produksi', 'is_active' => true]);
        $this->asm = Department::create(['code' => 'ASM', 'name' => 'Assembling', 'type' => 'produksi', 'is_active' => true]);

        $this->ridwan   = Karyawan::create(['staf_code' => 'RDW', 'name' => 'Ridwan', 'department_id' => $this->asm->id, 'is_active' => true]);
        $this->exRidwan = DepartmentExecutor::create(['department_id' => $this->asm->id, 'karyawan_id' => $this->ridwan->id,
            'name' => 'Ridwan', 'is_active' => true]);
        $this->exBudi = DepartmentExecutor::create(['department_id' => $this->asm->id, 'name' => 'Budi', 'is_active' => true]);
        $this->exSari = DepartmentExecutor::create(['department_id' => $this->cnc->id, 'name' => 'Sari', 'is_active' => true]);
    }

    /** OP: langkah 1 CNC (selesai), langkah 2 Assembling dimulai Ridwan. */
    private function opAssemblingRidwan(): ProductionOrderStep
    {
        $order = ProductionOrder::create(['order_number' => 'OP-' . uniqid(), 'type' => 'ready_stock',
            'warehouse_id' => $this->gudang, 'planned_cycles' => 1, 'planned_qty' => 1,
            'production_date' => now()->toDateString(), 'status' => 'confirmed']);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => 1, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 100]);
        $s1 = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 1,
            'department_id' => $this->cnc->id, 'name' => 'CNC', 'status' => 'pending']);
        $s2 = ProductionOrderStep::create(['production_order_id' => $order->id, 'step_number' => 2,
            'department_id' => $this->asm->id, 'name' => 'Assembling', 'status' => 'pending']);

        $this->svc->startStep($s1->id, [$this->exSari->id]);
        $this->travel(10)->minutes();
        $this->svc->completeStep($s1->id);
        $this->svc->startStep($s2->id, [$this->exRidwan->id]);
        $this->travel(5)->minutes();

        return $s2->fresh();
    }

    private function owner(): User
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $u->menuPermissions()->create(['menu_key' => 'production.orders']);
        $u->forceFill(['telegram_chat_id' => '5001'])->save();
        return $u->fresh();
    }

    // ───────────── Karyawan keluar ─────────────

    public function test_nonaktif_melepas_langkah_jadi_tanpa_pelaksana(): void
    {
        $step = $this->opAssemblingRidwan();

        $hasil = $this->ganti->lepasKaryawan($this->ridwan);

        $this->assertCount(1, $hasil);
        $this->assertTrue($hasil[0]['tanpa_pelaksana']);
        $step = $step->fresh('executors');
        $this->assertSame('paused', $step->status, 'timer tidak boleh jalan tanpa orang');
        $this->assertTrue($step->executors->isEmpty());
        $this->assertTrue(PergantianPelaksanaService::tanpaPelaksana($step));
        $this->assertStringContainsString('Pelaksana keluar: Ridwan', $step->sample_excluded_reason);
        $this->assertSame(app(KaryawanVirtualService::class)->akun('manager-produksi')->id, $step->sample_excluded_by);
        $this->assertFalse($this->exRidwan->fresh()->is_active, 'tidak muncul lagi di pilihan pelaksana');
    }

    public function test_langkah_tanpa_pelaksana_dilanjutkan_orang_lain(): void
    {
        $step = $this->opAssemblingRidwan();
        $this->ganti->lepasKaryawan($this->ridwan);

        $this->ganti->lanjutkan($step->id, [$this->exBudi->id]);

        $step = $step->fresh('executors');
        $this->assertSame('in_progress', $step->status);
        $this->assertSame([$this->exBudi->id], $step->executors->pluck('id')->all());
        $this->assertSame('resumed', $step->timeLogs()->get()->last()->event_type);
    }

    public function test_lanjutkan_ditolak_bila_masih_ada_pelaksana(): void
    {
        $step = $this->opAssemblingRidwan();

        $this->expectExceptionMessage('masih punya pelaksana');
        $this->ganti->lanjutkan($step->id, [$this->exBudi->id]);
    }

    public function test_karyawan_diaktifkan_lagi_pelaksananya_aktif(): void
    {
        $this->ganti->lepasKaryawan($this->ridwan);
        $this->ganti->aktifkanKembali($this->ridwan);
        $this->assertTrue($this->exRidwan->fresh()->is_active);
    }

    public function test_arsipkan_lewat_sdm_melepas_langkah(): void
    {
        $step = $this->opAssemblingRidwan();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('sdm.karyawan.archive', $this->ridwan->id))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'tanpa pelaksana'));

        $this->assertFalse($this->ridwan->fresh()->is_active);
        $this->assertTrue($step->fresh()->executors->isEmpty());
    }

    // ───────────── Oper tugas lewat persetujuan ─────────────

    public function test_ajukan_tidak_mengubah_apa_pun_sebelum_disetujui(): void
    {
        $owner = $this->owner();
        $step  = $this->opAssemblingRidwan();

        $u = $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exBudi->id], 'Ridwan sakit', $owner);

        $this->assertSame(Usulan::MENUNGGU, $u->status);
        $this->assertSame('manager-produksi', $u->karyawan);
        $this->assertSame([$this->exRidwan->id], $step->fresh()->executors->pluck('id')->all(), 'tetap pelaksana lama');
        $this->assertSame('in_progress', $step->fresh()->status);
        $this->assertNull($step->fresh()->sample_excluded_reason);

        $kirim = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage'));
        $this->assertCount(1, $kirim);
        $this->assertStringContainsString("kv:ok:{$u->id}", $kirim[0][0]['reply_markup']);
        $this->assertStringContainsString('Ridwan → Budi', $kirim[0][0]['text']);

        $this->expectExceptionMessage('masih menunggu');
        $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exBudi->id], 'lagi', $owner);
    }

    public function test_disetujui_pelaksana_diganti_dan_langkah_dicoret(): void
    {
        $owner = $this->owner();
        $step  = $this->opAssemblingRidwan();
        $u = $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exBudi->id], 'Ridwan sakit', $owner);

        $hasil = app(UsulanService::class)->putuskan($u->id, true, $owner, 'telegram');

        $this->assertTrue($hasil['ok'], $hasil['pesan']);
        $this->assertSame(Usulan::DISETUJUI, $u->fresh()->status);
        $step = $step->fresh('executors');
        $this->assertSame('in_progress', $step->status, 'langkah jalan tetap jalan');
        $this->assertSame([$this->exBudi->id], $step->executors->pluck('id')->all());
        $this->assertStringContainsString('Ridwan → Budi', $step->sample_excluded_reason);
    }

    public function test_pemeriksaan_berkala_tidak_membatalkan_pengajuan(): void
    {
        $owner = $this->owner();
        $step  = $this->opAssemblingRidwan();
        $u = $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exBudi->id], 'Ridwan sakit', $owner);

        app(KaryawanVirtualService::class)->jalankan(PergantianPelaksanaService::SKILL, KaryawanVirtualService::PEMICU_MANUAL);

        $this->assertSame(Usulan::MENUNGGU, $u->fresh()->status);
    }

    public function test_disetujui_tapi_langkah_sudah_selesai_dilewati(): void
    {
        $owner = $this->owner();
        $step  = $this->opAssemblingRidwan();
        $u = $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exBudi->id], 'Ridwan sakit', $owner);
        $this->svc->completeStep($step->id);

        $hasil = app(UsulanService::class)->putuskan($u->id, true, $owner, 'web');

        $this->assertFalse($hasil['ok']);
        $this->assertSame(Usulan::BATAL, $u->fresh()->status);
        $this->assertNull($step->fresh()->sample_excluded_reason);
    }

    public function test_pengganti_harus_divisi_langkah(): void
    {
        $step = $this->opAssemblingRidwan();

        $this->expectExceptionMessage('divisi langkah ini');
        $this->ganti->ajukan($step->id, [$this->exRidwan->id], [$this->exSari->id], 'x', $this->owner());
    }

    // ───────────── Sampel waktu ─────────────

    public function test_sampel_hanya_divisi_yang_dicoret_yang_hilang(): void
    {
        $step = $this->opAssemblingRidwan();
        $this->ganti->lepasKaryawan($this->ridwan);
        $this->ganti->lanjutkan($step->id, [$this->exBudi->id]);
        $this->travel(5)->minutes();
        $this->svc->completeStep($step->id);
        $step->productionOrder->update(['status' => 'completed']);

        $row = app(ProductionTimeAnalysisService::class)->forProduct($this->produk);
        $sampel = collect($row['samples'])->merge($row['ineligible_samples'])->sole();

        $this->assertContains('langkah_dicoret', $sampel['flags']);
        $this->assertArrayHasKey($this->cnc->id, $sampel['sec'], 'CNC tetap dipakai');
        $this->assertArrayNotHasKey($this->asm->id, $sampel['sec'], 'Assembling dicoret');
        $this->assertArrayHasKey($this->asm->id, $sampel['divisi_dicoret']);
    }
}
