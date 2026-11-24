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
use App\Modules\Production\Services\KeterampilanService;
use App\Modules\Production\Services\PenilaianKinerjaService;
use App\Modules\Production\Services\WaktuStandarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Keterampilan (keputusan owner 24 Nov 2026): dikuasai = ≥3 OP waktu baik (≥1 poin) dan ≥ separuh
 * OP kelompoknya baik (seluruh riwayat, bisa dicabut). Kelompok dari awalan SKU.
 */
class KeterampilanTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private DepartmentExecutor $novan;
    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00');
        Department::forceCreate(['id' => 2, 'code' => 'ASM', 'name' => 'Assembling']);
        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->novan = DepartmentExecutor::create(['department_id' => 2, 'name' => 'Novan', 'is_active' => true]);
        $std = [];
        foreach (['BC-3x2', 'TBKD-13-T2-M1', 'TB-13-T1-M5', 'AF-A3-LBR'] as $sku) {
            $this->p[$sku] = Product::create(['sku' => $sku, 'name' => $sku, 'sale_type' => 'ready',
                'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
            $std[$this->p[$sku]->id] = ['menit' => 2.0];
        }
        app(WaktuStandarService::class)->simpan(2, $std, Carbon::parse('2026-10-01'), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** OP 50 pcs (target 100 mnt) selesai $tgl dengan timer $menit. */
    private function op(string $sku, float $menit, string $tgl): void
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-KT-$n", 'type' => 'ready_stock', 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 0, 'production_date' => $tgl, 'status' => 'completed']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $this->p[$sku]->id,
            'qty_planned' => 50, 'qty_produced' => 50, 'output_type' => 'main', 'percentage' => 100]);
        $akhir = Carbon::parse($tgl . ' 15:00');
        $mulai = $akhir->copy()->subMinutes((int) $menit);
        $s = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => 2,
            'name' => 'Rakit', 'status' => 'completed', 'started_at' => $mulai, 'completed_at' => $akhir]);
        ProductionStepTimeLog::create(['production_order_step_id' => $s->id, 'event_type' => 'started', 'occurred_at' => $mulai]);
        ProductionStepTimeLog::create(['production_order_step_id' => $s->id, 'event_type' => 'completed', 'occurred_at' => $akhir]);
        DB::table('production_order_step_executors')->insert(['step_id' => $s->id, 'executor_id' => $this->novan->id, 'joined_at' => $mulai]);
    }

    private function hasil(string $bulan = '2026-11-30'): array
    {
        return app(KeterampilanService::class)->orang(2, $this->novan->id, Carbon::parse($bulan))['kelompok'];
    }

    public function test_kelompok_sku_mengikuti_keputusan_owner(): void
    {
        $k = fn ($sku) => KeterampilanService::kelompokSku(2, $sku);
        $this->assertSame('bungkus-kd', $k('TBKD-13-T2-M1'));
        $this->assertSame('bungkus-kd', $k('TB-13-T1P-M1'));
        $this->assertSame('rakit', $k('TB-13-T1-M5'));
        $this->assertSame('rakit', $k('TO-8-M2-2'));
        $this->assertSame('rakit', $k('KS-A4'));
        $this->assertSame('rakit-bc', $k('BC-3x2'));
        $this->assertSame('tekuk', $k('NM-01'));
        $this->assertSame('magnet', $k('FF-10'));
        $this->assertSame('stiker', $k('KOIN-01'));
        $this->assertSame('stiker', $k('AS-JE-3010-KI-STD'));
        $this->assertNull($k('AF-A3-LBR'));
    }

    public function test_dikuasai_butuh_tiga_op_baik_dan_separuh_lalu_tetap_melekat(): void
    {
        // BC: 2 baik dari 2 → belum (kurang 1).
        $this->op('BC-3x2', 90, '2026-10-05');
        $this->op('BC-3x2', 100, '2026-10-06');
        $this->assertSame(['belajar', 2], [$this->hasil()['rakit-bc']['status'], $this->hasil()['rakit-bc']['baik']]);

        // OP ke-3 baik → dikuasai sejak 7 Okt; 3 OP lambat sesudahnya masih separuh (3/6) → tetap.
        $this->op('BC-3x2', 105, '2026-10-07');
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $t) $this->op('BC-3x2', 150, $t);
        $bc = $this->hasil()['rakit-bc'];
        $this->assertSame(['dikuasai', 3, 6, '2026-10-07'], [$bc['status'], $bc['baik'], $bc['n'], $bc['dikuasai_sejak']->toDateString()]);
        $this->assertSame('2026-11-04', $bc['terakhir']->toDateString());

        // Satu lagi lambat → 3/7 di bawah separuh → dicabut jadi belajar; lalu 1 baik → 4/8 → dikuasai lagi sejak itu.
        $this->op('BC-3x2', 150, '2026-11-05');
        $this->assertSame(['belajar', null], [$this->hasil()['rakit-bc']['status'], $this->hasil()['rakit-bc']['dikuasai_sejak']]);
        $this->op('BC-3x2', 95, '2026-11-06');
        $this->assertSame('2026-11-06', $this->hasil()['rakit-bc']['dikuasai_sejak']->toDateString());

        // Bungkus KD: 3 baik dari 7 (< separuh) → belum dikuasai walau OP baik sudah 3.
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'] as $t) $this->op('TBKD-13-T2-M1', 150, $t);
        foreach (['2026-10-14', '2026-10-15', '2026-10-16'] as $t) $this->op('TBKD-13-T2-M1', 80, $t);
        $this->assertSame('belajar', $this->hasil()['bungkus-kd']['status']);

        // AF tidak masuk kelompok mana pun; kelompok tanpa OP = belum.
        $this->op('AF-A3-LBR', 80, '2026-11-05');
        $h = $this->hasil();
        $this->assertSame(8 + 7, collect($h)->sum('n'));
        $this->assertSame('belum', $h['magnet']['status']);

        // Dihitung s/d akhir bulan dipilih: per Oktober BC belum punya OP November.
        $this->assertSame(3, $this->hasil('2026-10-31')['rakit-bc']['n']);
    }

    public function test_rapor_menampilkan_keterampilan(): void
    {
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $t) $this->op('BC-3x2', 95, $t);
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->get(route('production.waktu-standar.rapor', ['orang' => $this->novan->id, 'bulan' => '2026-11']))
            ->assertOk()
            ->assertSee('D. Keterampilan — 1 dari 6 kelompok dikuasai')
            ->assertSee('Merakit BC')
            ->assertSee('✓ Dikuasai')
            ->assertSee('dikuasai sejak 04/11/2026');
    }
}
