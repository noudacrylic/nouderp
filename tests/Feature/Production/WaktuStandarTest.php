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
use App\Modules\Production\Models\ProductionStandardTime;
use App\Modules\Production\Models\ProductionStepTimeLog;
use App\Modules\Production\Services\WaktuStandarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Waktu standar Assembling: usulan median per SKU, catatan tak wajar, simpan berlaku-mulai. */
class WaktuStandarTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private Product $produk;
    private DepartmentExecutor $lama;
    private DepartmentExecutor $baru;

    protected function setUp(): void
    {
        parent::setUp();
        Department::forceCreate(['id' => 1, 'code' => 'CNC', 'name' => 'CNC']);
        Department::forceCreate(['id' => 2, 'code' => 'ASM', 'name' => 'Assembling']);
        $this->gudang = Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'TBKD-13-T2-M1', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);

        $this->lama = DepartmentExecutor::create(['department_id' => 2, 'name' => 'Novan', 'is_active' => true]);
        $this->lama->forceFill(['created_at' => now()->subDays(200)])->save();
        $this->baru = DepartmentExecutor::create(['department_id' => 2, 'name' => 'Mafazal', 'is_active' => true]);
    }

    /** Satu OP ready berisi 1 produk dengan langkah Assembling selesai sekian menit. */
    private function op(float $qty, float $menit, ?DepartmentExecutor $oleh = null, string $tipe = 'ready_stock', int $hariLalu = 10): ProductionOrderStep
    {
        static $n = 0;
        $n++;
        $op = ProductionOrder::create(['order_number' => "OP-WS-$n", 'type' => $tipe, 'warehouse_id' => $this->gudang,
            'planned_cycles' => 1, 'planned_qty' => 0, 'production_date' => now()->toDateString(), 'status' => 'completed']);
        ProductionOrderOutput::create(['production_order_id' => $op->id, 'product_id' => $this->produk->id,
            'qty_planned' => $qty, 'qty_produced' => $qty, 'output_type' => 'main', 'percentage' => 100]);

        $mulai = now()->subDays($hariLalu)->setTime(8, 0);
        $selesai = $mulai->copy()->addSeconds((int) round($menit * 60));
        $step = ProductionOrderStep::create(['production_order_id' => $op->id, 'step_number' => 1, 'department_id' => 2,
            'name' => 'Rakit', 'status' => 'completed', 'started_at' => $mulai, 'completed_at' => $selesai]);
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'started', 'occurred_at' => $mulai]);
        ProductionStepTimeLog::create(['production_order_step_id' => $step->id, 'event_type' => 'completed', 'occurred_at' => $selesai]);
        DB::table('production_order_step_executors')->insert(['step_id' => $step->id, 'executor_id' => ($oleh ?? $this->lama)->id, 'joined_at' => $mulai]);

        return $step;
    }

    public function test_usulan_median_per_sku_tanpa_catatan_tak_wajar_custom_dan_pelaksana_percobaan(): void
    {
        foreach ([2.0, 2.2, 2.1, 1.9, 2.0] as $mpp) {
            $this->op(50, 50 * $mpp);
        }
        $this->op(50, 0.5);            // timer lupa dinyalakan → tak wajar
        $this->op(50, 50 * 9);         // lupa dijeda → tak wajar
        $this->op(50, 50 * 5, null, 'perbaikan');       // perbaikan tidak dinilai
        $this->op(50, 50 * 5, $this->baru);             // pelaksana percobaan tidak jadi acuan

        $ws = app(WaktuStandarService::class);
        $u = $ws->usulan(2, now()->subDays(150), $ws->pelaksanaLama(2)->all())->get($this->produk->id);

        $this->assertSame(7, $u['n']);
        $this->assertSame(5, $u['n_wajar']);
        $this->assertSame(2.0, $u['median']);
        $this->assertTrue($u['cukup']);
        $this->assertSame(2, $u['catatan']->where('wajar', false)->count());
    }

    public function test_op_custom_jadi_sampel_kecuali_produk_custom_bebas_cs(): void
    {
        $this->op(50, 100, null, 'custom');     // custom biasa (mis. BC custom) ikut jadi sampel
        $this->produk = Product::create(['sku' => 'CS2', 'name' => 'Produk Custom 2', 'sale_type' => 'preorder',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true]);
        $this->op(50, 100, null, 'custom');     // CS bukan sampel

        $c = app(WaktuStandarService::class)->catatan(2, now()->subDays(150));
        $this->assertCount(1, $c);
        $this->assertTrue(WaktuStandarService::skuCustomBebas('cs6'));
        $this->assertFalse(WaktuStandarService::skuCustomBebas('BC-2x2'));
    }

    public function test_simpan_berlaku_mulai_menyimpan_riwayat_dan_melewati_yang_tak_berubah(): void
    {
        $ws = app(WaktuStandarService::class);
        $pid = $this->produk->id;

        $this->assertSame(1, $ws->simpan(2, [$pid => ['menit' => 2.0, 'sumber' => 'median']], Carbon::parse('2026-10-01'), null));
        $this->assertSame(0, $ws->simpan(2, [$pid => ['menit' => 2.0]], Carbon::parse('2026-10-01'), null), 'Angka sama tidak disimpan ulang.');
        $this->assertSame(1, $ws->simpan(2, [$pid => ['menit' => 2.4]], Carbon::parse('2027-01-01'), null));

        $this->assertSame(2, ProductionStandardTime::count(), 'Kuartal baru = baris baru; yang lama tetap.');
        $this->assertSame(2.0, $ws->berlaku(2, $pid, Carbon::parse('2026-11-15'))->minutes_per_unit, 'November tetap memakai standar Oktober.');
        $this->assertSame(2.4, $ws->berlaku(2, $pid, Carbon::parse('2027-02-01'))->minutes_per_unit);
        $this->assertNull($ws->berlaku(2, $pid, Carbon::parse('2026-09-30')));
    }

    public function test_halaman_khusus_owner_dan_simpan_angka_berkoma(): void
    {
        foreach ([2.0, 2.2, 2.1, 1.9, 2.0] as $mpp) {
            $this->op(50, 50 * $mpp);
        }
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('production.waktu-standar.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('production.waktu-standar.index'))
            ->assertOk()->assertSee('TBKD-13-T2-M1')->assertSee('2,04')->assertSee('median 2,00')->assertSee('belum ada');

        $this->actingAs($owner)->post(route('production.waktu-standar.simpan'), [
            'divisi' => 2, 'berlaku_mulai' => '2026-10-01',
            'standar' => [$this->produk->id => ['menit' => '2,04', 'usulan' => '2,04', 'sampel' => 5]],
        ])->assertRedirect()->assertSessionHas('success');

        $st = ProductionStandardTime::sole();
        $this->assertSame(2.04, $st->minutes_per_unit);
        $this->assertSame('rata', $st->source);
        $this->assertSame($owner->id, $st->approved_by);
    }

    public function test_coret_sampel_hanya_untuk_standar_rata_rata_dari_sisa(): void
    {
        foreach ([2.0, 2.2, 2.1, 1.9, 2.0] as $mpp) {
            $this->op(50, 50 * $mpp);
        }
        $menyimpang = $this->op(50, 50 * 3.5);   // masih "wajar" (< 2× median) tapi menyimpang
        $ws = app(WaktuStandarService::class);
        $lama = $ws->pelaksanaLama(2)->all();

        $u = $ws->usulan(2, now()->subDays(150), $lama)->get($this->produk->id);
        $this->assertSame(6, $u['n_wajar']);
        $this->assertEqualsWithDelta((10.2 + 3.5) / 6, $u['rata'], 0.01);

        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $url = route('production.waktu-standar.sampel.coret', ['stepId' => $menyimpang->id, 'buka' => $this->produk->id]);
        $this->actingAs($admin)->post($url, ['coret' => 1])->assertForbidden();
        $this->actingAs($owner)->from(route('production.waktu-standar.index'))->post($url, ['coret' => 1])
            ->assertRedirect(route('production.waktu-standar.index'))->assertSessionHas('buka', $this->produk->id);

        $u = $ws->usulan(2, now()->subDays(150), $lama)->get($this->produk->id);
        $this->assertSame(5, $u['n_wajar']);
        $this->assertSame(1, $u['n_coret']);
        $this->assertSame(2.04, $u['rata']);
        $this->assertTrue($u['catatan']->firstWhere('step_id', $menyimpang->id)['dicoret']);

        // Coretan standar tidak menyentuh coretan HPP/penilaian.
        $this->assertNull($menyimpang->fresh()->sample_excluded_reason);
        $this->actingAs($owner)->get(route('production.waktu-standar.index'))->assertOk()->assertSee('pulihkan')->assertSee('1 dicoret');

        $this->actingAs($owner)->post($url, ['coret' => 0]);
        $this->assertSame(0, $ws->usulan(2, now()->subDays(150), $lama)->get($this->produk->id)['n_coret']);
    }

    public function test_coret_dan_simpan_per_baris_lewat_ajax_mengembalikan_baris_sku(): void
    {
        foreach ([2.0, 2.2, 2.1, 1.9, 2.0] as $mpp) {
            $this->op(50, 50 * $mpp);
        }
        $menyimpang = $this->op(50, 50 * 3.5);
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $pid = $this->produk->id;

        $this->actingAs($owner)->postJson(route('production.waktu-standar.sampel.coret', $menyimpang->id),
            ['coret' => 1, 'buka' => $pid, 'divisi' => 2, 'hari' => 150])
            ->assertOk()->assertJsonPath('pesan', 'Sampel dicoret — tidak ikut hitungan standar.')
            ->assertSee('sku-' . $pid, false)->assertSee('2,04', false)->assertSee('pulihkan', false);

        $r = $this->actingAs($owner)->postJson(route('production.waktu-standar.simpan'), [
            'divisi' => 2, 'hari' => 150, 'berlaku_mulai' => '2026-11-01',
            'standar' => [$pid => ['menit' => '2,04', 'usulan' => '2,04', 'sampel' => 5]],
        ])->assertOk()->assertJsonPath('pesan', '1 waktu standar disimpan, berlaku mulai 01/11/2026.');
        $this->assertStringContainsString('sejak 01/11/2026', $r->json('html'));

        $this->assertSame('rata', ProductionStandardTime::sole()->source);
    }
}
