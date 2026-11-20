<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\DepartmentExecutor;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\ProductionOrderStep;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\PenilaianKinerjaService;
use App\Modules\Production\Services\WaktuStandarService;
use App\Modules\Tasks\MejaKerja\MejaKerjaService;
use App\Modules\Tasks\MejaKerja\Models\MejaKerjaPeran;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Skill Pengawasan (keputusan owner 9 Nov 2026): langkah Assembling yang timernya janggal
 * dibanding target ditahan dari penilaian & kartunya masuk Meja Owner; owner memutus
 * Wajar / Abaikan / Tidak wajar.
 */
class PengawasanProduksiTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'pengawasan-produksi';

    private int $gudang;
    private Product $a;
    private DepartmentExecutor $novan;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00');
        Department::forceCreate(['id' => 2, 'code' => 'ASM', 'name' => 'Assembling']);
        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->a = Product::create(['sku' => 'SKU-A', 'name' => 'Produk A', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
        $this->novan = DepartmentExecutor::create(['department_id' => 2, 'name' => 'Novan', 'is_active' => true]);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        MejaKerjaPeran::create(['peran' => 'owner', 'user_id' => $this->owner->id, 'urutan' => 1]);
        app(WaktuStandarService::class)->simpan(2, [$this->a->id => ['menit' => 2.0]], Carbon::parse('2026-10-01'), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** OP 50 pcs (target 100 mnt) dengan langkah Rakit selesai; $jeda = [mulai pause, lanjut] dalam menit dari awal. */
    private function op(float $menit, string $selesai = '2026-11-18 15:00', ?array $jeda = null): ProductionOrderStep
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-AW-$n", 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 0, 'production_date' => '2026-11-18', 'status' => 'completed']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $this->a->id,
            'qty_planned' => 50, 'qty_produced' => 50, 'output_type' => 'main', 'percentage' => 100]);
        $akhir = Carbon::parse($selesai);
        $mulai = $akhir->copy()->subSeconds((int) round($menit * 60));
        $step = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => 2,
            'name' => 'Rakit', 'status' => 'completed', 'started_at' => $mulai, 'completed_at' => $akhir]);
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'started', 'occurred_at' => $mulai]);
        if ($jeda) {
            ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'paused', 'alasan' => 'bongkar_barang',
                'occurred_at' => $mulai->copy()->addMinutes($jeda[0])]);
            ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'resumed', 'occurred_at' => $mulai->copy()->addMinutes($jeda[1])]);
        }
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'completed', 'occurred_at' => $akhir]);
        DB::table('production_order_step_executors')->insert(['step_id' => $step->id, 'executor_id' => $this->novan->id, 'joined_at' => $mulai]);

        return $step;
    }

    public function test_timer_janggal_masuk_meja_owner_dengan_bukti_lalu_putusan_menentukan_nilai(): void
    {
        $this->op(100, '2026-11-17 15:00');                      // 100% → normal, tanpa kartu
        // Mulai 13:00, pause 13:05 (bongkar barang), lanjut 14:50, selesai 15:00 → timer 15 mnt dari target 100.
        $cepat = $this->op(120, '2026-11-18 15:00', [5, 110]);
        $this->op(100, '2026-09-10 15:00');                      // terlalu lama (di luar bulan lalu & ini)

        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', self::SKILL)->sole();
        $this->assertSame('owner', $t->peran);
        $this->assertSame($cepat->id, (int) $t->taskable_id);
        $this->assertStringContainsString('Timer janggal: OP-AW-2 — Novan (15% dari target)', $t->title);
        $this->assertStringContainsString('Target 1 jam 40 mnt · timer 15 mnt', (string) $t->description);
        $this->assertStringContainsString('13:05 ⏸ Pause — 📦 Bongkar', (string) $t->description);

        // Sebelum diputus: ditahan, tidak ikut nilai.
        $pk = app(PenilaianKinerjaService::class);
        $p = $pk->bulan(2, Carbon::parse('2026-11-01'))['pelaksana']->first();
        $this->assertSame([1, 1], [$p['n'], $p['ditahan']]);

        $skill = $meja->skill(self::SKILL);
        $this->assertSame(['wajar', 'abaikan', 'curang'], collect($skill->jawaban($t))->pluck('kunci')->all());
        $skill->jawab($t, 'curang', $this->owner);
        $meja->sinkron();
        $this->assertSame('done', $t->fresh()->status);
        $this->assertSame(1, Task::where('skill', self::SKILL)->count(), 'Tidak muncul lagi setelah diputus.');

        $p = app(PenilaianKinerjaService::class)->bulan(2, Carbon::parse('2026-11-01'))['pelaksana']->first();
        $this->assertSame([2, 0, 1], [$p['n'], $p['ditahan'], $p['poin0']], 'Tidak wajar = dinilai 0 poin.');

        // Abaikan: keluar dari penilaian; Wajar: dinilai sesuai timer (15% → 2 poin).
        $pk->putuskan($cepat->id, 'abaikan', $this->owner->id);
        $p = app(PenilaianKinerjaService::class)->bulan(2, Carbon::parse('2026-11-01'))['pelaksana']->first();
        $this->assertSame([1, 0], [$p['n'], $p['ditahan']]);
        $pk->putuskan($cepat->id, 'wajar', $this->owner->id);
        $p = app(PenilaianKinerjaService::class)->bulan(2, Carbon::parse('2026-11-01'))['pelaksana']->first();
        $this->assertSame([2, 1], [$p['n'], $p['poin2']]);
    }

    public function test_kartu_batal_bila_tidak_lagi_janggal_setelah_standar_berubah(): void
    {
        $this->op(15);
        $meja = app(MejaKerjaService::class);
        $meja->sinkron();
        $t = Task::where('skill', self::SKILL)->sole();

        // Standar dikoreksi: 50 pcs × 0,3 = 15 mnt → timer pas target.
        app(WaktuStandarService::class)->simpan(2, [$this->a->id => ['menit' => 0.3]], Carbon::parse('2026-10-01'), null);
        $meja->sinkron();
        $this->assertSame('cancelled', $t->fresh()->status);
    }

    public function test_dashboard_mengirim_putusan_ke_browser(): void
    {
        $step = $this->op(15);
        app(PenilaianKinerjaService::class)->putuskan($step->id, 'abaikan', $this->owner->id);

        $this->actingAs($this->owner)->get(route('production.waktu-standar.penilaian', ['dari' => '2026-11', 'sampai' => '2026-11']))
            ->assertOk()->assertSee('"putusan":"abaikan"', false);
    }
}
