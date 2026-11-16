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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Penilaian Assembling per OP: jatah = Σ pcs × standar, poin 2/1/0, ditahan bila janggal. */
class PenilaianKinerjaTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private Product $a;
    private Product $b;
    private Product $c;
    private DepartmentExecutor $novan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00');
        Department::forceCreate(['id' => 2, 'code' => 'ASM', 'name' => 'Assembling']);
        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        foreach (['a' => 'SKU-A', 'b' => 'SKU-B', 'c' => 'SKU-C'] as $k => $sku) {
            $this->$k = Product::create(['sku' => $sku, 'name' => $sku, 'sale_type' => 'ready',
                'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
        }
        $this->novan = DepartmentExecutor::create(['department_id' => 2, 'name' => 'Novan', 'is_active' => true]);
        $this->novan->forceFill(['created_at' => now()->subDays(200)])->save();

        $ws = app(WaktuStandarService::class);
        $ws->simpan(2, [$this->a->id => ['menit' => 2.0], $this->b->id => ['menit' => 3.0]], Carbon::parse('2026-10-01'), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, float> $isi product_id => qty */
    private function op(array $isi, float $menit, string $selesai = '2026-10-15 09:00', string $tipe = 'ready_stock', ?array $merk = null, ?array $jumlah = null): void
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-PK-$n", 'type' => $tipe, 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 0, 'production_date' => '2026-10-15', 'status' => 'completed', 'merk_bahan' => $merk, 'merk_jumlah' => $jumlah]);
        foreach ($isi as $pid => $qty) {
            ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $pid,
                'qty_planned' => $qty, 'qty_produced' => $qty, 'output_type' => 'main', 'percentage' => 100]);
        }
        $akhir = Carbon::parse($selesai);
        $mulai = $akhir->copy()->subSeconds((int) round($menit * 60));
        $step = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => 2,
            'name' => 'Rakit', 'status' => 'completed', 'started_at' => $mulai, 'completed_at' => $akhir]);
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'started', 'occurred_at' => $mulai]);
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'completed', 'occurred_at' => $akhir]);
        DB::table('production_order_step_executors')->insert(['step_id' => $step->id, 'executor_id' => $this->novan->id, 'joined_at' => $mulai]);
    }

    public function test_poin_per_op_dari_jatah_multi_produk(): void
    {
        // Jatah 50×2 + 30×3 = 190 mnt.
        $this->op([$this->a->id => 50, $this->b->id => 30], 160);   // 84% → 2
        $this->op([$this->a->id => 50, $this->b->id => 30], 200);   // 105% → 1
        $this->op([$this->a->id => 50, $this->b->id => 30], 230);   // 121% → 0
        $this->op([$this->a->id => 50, $this->b->id => 30], 20);    // 11% → perlu dicek
        $this->op([$this->a->id => 10, $this->c->id => 5], 30);     // SKU-C tanpa standar
        $this->op([$this->a->id => 50], 50, '2026-10-15 09:00', 'perbaikan'); // perbaikan tidak dinilai
        $this->op([$this->a->id => 50], 50, '2026-11-02 09:00');    // bulan lain

        $r = app(PenilaianKinerjaService::class)->bulan(2, Carbon::parse('2026-10-01'));
        $p = $r['pelaksana']->firstWhere('nama', 'Novan');

        $this->assertSame(3, $p['n']);
        $this->assertSame([1, 1, 1], [$p['poin2'], $p['poin1'], $p['poin0']]);
        $this->assertEqualsWithDelta(1.0, $p['rata'], 1e-9);
        $this->assertSame('Normal', $p['tingkat']['label']);
        $this->assertSame(1, $p['ditahan']);
        $this->assertSame(1, $p['tanpa_standar']);
        $this->assertEqualsWithDelta(570 / 590, $p['efisiensi'], 1e-6);
        $this->assertSame(['SKU-C'], $r['tanpa_standar']->first()['kurang']);
    }

    public function test_op_custom_ikut_dinilai_kecuali_produk_custom_bebas_cs(): void
    {
        $cs = Product::create(['sku' => 'CS1', 'name' => 'Custom + Print 1', 'sale_type' => 'preorder',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);

        $this->op([$this->a->id => 50], 80, '2026-10-15 09:00', 'custom');          // BC custom: 80/100 → 2 poin
        $this->op([$cs->id => 3], 120, '2026-10-16 09:00', 'custom');                 // CS1 → dilewati
        $this->op([$this->a->id => 10, $cs->id => 1], 60, '2026-10-17 09:00', 'custom'); // ada CS → dilewati

        $r = app(PenilaianKinerjaService::class)->bulan(2, Carbon::parse('2026-10-01'));
        $p = $r['pelaksana']->firstWhere('nama', 'Novan');

        $this->assertSame(1, $p['n']);
        $this->assertSame(1, $p['poin2']);
        $this->assertSame(0, $p['tanpa_standar'], 'OP CS tidak dianggap "tanpa standar" — memang tidak dinilai.');
        $this->assertCount(0, $r['tanpa_standar']);
    }

    public function test_simulasi_memakai_median_untuk_sku_tanpa_standar(): void
    {
        // Riwayat SKU-C 4 mnt/pcs cukup sampel → dipakai saat simulasi.
        foreach (range(1, 5) as $i) {
            $this->op([$this->c->id => 10], 40, "2026-10-0{$i} 09:00");
        }
        $pk = app(PenilaianKinerjaService::class);

        $this->assertSame(0, $pk->bulan(2, Carbon::parse('2026-10-01'))['op']->count());

        $sim = $pk->bulan(2, Carbon::parse('2026-10-01'), true);
        $this->assertSame(1, $sim['standar_simulasi']);
        $this->assertSame(5, $sim['pelaksana']->first()['poin1']);
    }

    public function test_tingkat_bonus_dari_rata_rata_poin_dan_pengaturan(): void
    {
        $pk = app(PenilaianKinerjaService::class);
        $this->assertSame(550000, $pk->tingkat(1.4)['bonus']);
        $this->assertSame(440000, $pk->tingkat(1.0)['bonus']);
        $this->assertSame(300000, $pk->tingkat(0.7)['bonus']);
        $this->assertSame(200000, $pk->tingkat(0.2)['bonus']);
        $this->assertNull($pk->tingkat(null));

        // Ambang tersimpan menggantikan default; urutan & lantai dirapikan otomatis.
        $pk->simpanParam(['pita' => 0.15, 'faktor_cek' => 3, 'min_op' => 5, 'bobot' => 'jatah', 'tingkat' => [
            ['label' => 'Rendah', 'min' => 0.4, 'bonus' => 200000],
            ['label' => 'Istimewa', 'min' => 1.25, 'bonus' => 550000],
        ]]);
        $baru = app(PenilaianKinerjaService::class);
        $this->assertSame(0.15, $baru->param()['pita']);
        $this->assertSame('jatah', $baru->param()['bobot']);
        $this->assertSame(550000, $baru->tingkat(1.25)['bonus']);
        $this->assertSame(200000, $baru->tingkat(0.1)['bonus'], 'Tingkat terbawah selalu lantai 0.');
        $this->assertSame(1, $baru->poin(1.12), 'Pita 15%: 112% masih sesuai.');
    }

    public function test_dashboard_khusus_owner_dan_simpan_parameter(): void
    {
        $this->op([$this->a->id => 50, $this->b->id => 30], 160);
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('production.waktu-standar.penilaian'))->assertForbidden();
        $this->actingAs($owner)->get(route('production.waktu-standar.penilaian', ['dari' => '2026-09', 'sampai' => '2026-10']))
            ->assertOk()->assertSee('Novan')->assertSee('OP-PK-')->assertSee('Dashboard Penilaian');

        $param = ['pita' => '0.12', 'faktor_cek' => '2.5', 'min_op' => '8', 'bobot' => 'op', 'tingkat' => [
            ['label' => 'Istimewa', 'min' => '1.3', 'bonus' => '550000'],
            ['label' => 'Normal', 'min' => '0.9', 'bonus' => '440000'],
            ['label' => 'Rendah', 'min' => '0', 'bonus' => '200000'],
        ]];
        $this->actingAs($admin)->post(route('production.waktu-standar.penilaian.simpan'), ['param' => $param])->assertForbidden();
        $this->actingAs($owner)->post(route('production.waktu-standar.penilaian.simpan'), ['param' => $param])
            ->assertRedirect()->assertSessionHas('success');

        $simpan = \App\Models\ProductionSetting::first()->penilaian_param;
        $this->assertSame(0.12, $simpan['pita']);
        $this->assertSame(1.3, $simpan['tingkat'][0]['min']);
        $this->assertCount(3, $simpan['tingkat']);
    }

    public function test_faktor_merk_memperpanjang_jatah_op_dengan_merk_tersulit(): void
    {
        // Jatah dasar 50×2 = 100 mnt; timer 120 mnt → 120% (0 poin) tanpa toleransi.
        $this->op([$this->a->id => 50], 120, '2026-10-15 09:00', 'ready_stock', ['MAG', 'NN']);
        $this->op([$this->a->id => 50], 120, '2026-10-16 09:00', 'ready_stock', ['NN']);

        $pk = app(PenilaianKinerjaService::class);
        $pk->simpanParam(['faktor_merk' => ['MAG' => '1.25', 'NN' => '1', 'Tiger' => '9']]);
        $this->assertSame(['MAG' => 1.25, 'Tiger' => 2.0], $pk->param()['faktor_merk']); // 1 dibuang, dibatasi ≤ 2

        $ops = $pk->opBulan(2, Carbon::parse('2026-10-01'))['op']->keyBy('op');
        $mag = $ops->first(fn ($o) => $o['merk'] === ['MAG', 'NN']);
        $nn  = $ops->first(fn ($o) => $o['merk'] === ['NN']);
        $this->assertEqualsWithDelta(125.0, $mag['jatah'], 1e-6);   // campur → faktor tersulit (MAG)
        $this->assertSame(1, $mag['poin']);                          // 120/125 = 96% → sesuai
        $this->assertEqualsWithDelta(100.0, $nn['jatah'], 1e-6);
        $this->assertSame(0, $nn['poin']);
    }

    public function test_faktor_merk_ditimbang_jumlah_lembar(): void
    {
        // MAG 2 + NN 1, MAG 1,25 → faktor (2×1,25 + 1)/3 = 1,1667; jatah 100 → 116,7 mnt.
        $this->op([$this->a->id => 50], 110, '2026-10-15 09:00', 'ready_stock', ['MAG', 'NN'], ['MAG' => 2, 'NN' => 1]);
        $pk = app(PenilaianKinerjaService::class);
        $pk->simpanParam(['faktor_merk' => ['MAG' => 1.25]]);

        $o = $pk->opBulan(2, Carbon::parse('2026-10-01'))['op']->first();
        $this->assertEqualsWithDelta(3.5 / 3, $o['faktor'], 1e-9);
        $this->assertEqualsWithDelta(116.7, $o['jatah'], 0.05);
        $this->assertSame(1, $o['poin']); // 110/116,7 = 94% → sesuai
    }
}
