<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Models\MaterialPriceAssumption;
use App\Modules\Analysis\Services\MaterialRecipeService;
use App\Modules\Analysis\Services\ProductionTimeAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Asumsi harga bahan — "kalau akrilik naik jadi Rp360.000, HPP saya jadi berapa".
 *
 * Rantai yang diuji sengaja berjenjang, meniru keadaan sebenarnya:
 *
 *     Akrilik lembaran (beli, Rp340.000)
 *        └─ OP 501 → 100 Bahan Setengah Jadi   (2 lembar akrilik)
 *              └─ OP 502 → 50 Produk Jadi      (50 bahan setengah jadi)
 *
 * Bahan LANGSUNG produk jadi bukan akrilik. Kalau penelusurannya berhenti satu tingkat,
 * menaikkan harga akrilik akan tampak berdampak nol — itulah yang dijaga tes ini.
 */
class MaterialAssumptionTest extends TestCase
{
    use RefreshDatabase;

    private int $akrilik;
    private int $setengahJadi;
    private int $produkJadi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akrilik      = $this->produk('LBR-2MM', 'Akrilik Lembaran 2 mm');
        $this->setengahJadi = $this->produk('BNM-7x30', 'Bahan Nama Meja');
        $this->produkJadi   = $this->produk('NM-7x30', 'Nama Meja Akrilik');

        // Akrilik dibeli Rp340.000/lembar.
        $this->layer($this->akrilik, 10, 340_000, 'purchase', null);
        // OP 501 menghasilkan 100 bahan setengah jadi dari 2 lembar akrilik.
        $this->layer($this->setengahJadi, 100, 6_800, 'production_order', 501);
        $this->bahan(501, $this->akrilik, 2);
        // OP 502 menghasilkan 50 produk jadi dari 50 bahan setengah jadi.
        $this->layer($this->produkJadi, 50, 6_500, 'production_order', 502);
        $this->bahan(502, $this->setengahJadi, 50);

        // OP 502 adalah sampel HPP produk jadi.
        $this->instance(ProductionTimeAnalysisService::class, Mockery::mock(ProductionTimeAnalysisService::class, function ($m) {
            $m->shouldReceive('perProductSampleOrderIds')->andReturn([$this->produkJadi => [502]]);
            // Halaman HPP memanggil jalur lain dari service yang sama; di tes ini isinya
            // tidak relevan — yang diuji pita mode asumsinya, bukan barisnya.
            $m->shouldReceive('perProduct')->andReturn(collect());
            $m->shouldReceive('mergedSampleCount')->andReturn(0);
            $m->shouldReceive('departmentsForFilter')->andReturn(collect());
        }));
    }

    public function test_kenaikan_harga_bahan_menembus_bahan_setengah_jadi(): void
    {
        $svc = app(MaterialRecipeService::class);

        // Harga hari ini: 2 lembar ÷ 100 = 0,02 lembar per bahan setengah jadi × Rp340.000.
        $this->assertEqualsWithDelta(6_800, $svc->costs([], withAssumption: false)[$this->produkJadi], 0.01);

        MaterialPriceAssumption::create(['product_id' => $this->akrilik, 'price' => 360_000]);

        // Dan naik ikut akriliknya, walau akrilik bukan bahan langsungnya.
        $this->assertEqualsWithDelta(7_200, $svc->costs([], withAssumption: true)[$this->produkJadi], 0.01);
    }

    public function test_hanya_bahan_beli_yang_bisa_diandaikan(): void
    {
        $rows = app(MaterialRecipeService::class)->assumptionRows();

        $this->assertSame([$this->akrilik], $rows->pluck('product.id')->all());
        $this->assertEquals(340_000, $rows->first()['price']);
        // Dipakai oleh satu produk jadi — lewat bahan setengah jadinya.
        $this->assertSame(1, $rows->first()['used_by']);
    }

    public function test_asumsi_pada_sebuah_barang_menghentikan_penelusuran_isinya(): void
    {
        // Kalau seseorang menuliskan harga untuk bahan setengah jadi, itu harganya —
        // bukan undangan menghitung ulang akrilik di dalamnya.
        MaterialPriceAssumption::create(['product_id' => $this->setengahJadi, 'price' => 10_000]);
        MaterialPriceAssumption::create(['product_id' => $this->akrilik, 'price' => 999_999]);

        $this->assertEqualsWithDelta(
            10_000,
            app(MaterialRecipeService::class)->costs([], withAssumption: true)[$this->produkJadi],
            0.01,
        );
    }

    public function test_hpp_ikut_asumsi_hanya_saat_mode_asumsi_dinyalakan(): void
    {
        MaterialPriceAssumption::create(['product_id' => $this->akrilik, 'price' => 360_000]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        // Tanpa ?asumsi=1 halaman tetap memakai angka sebenarnya…
        $this->actingAs($user)->get(route('analisa.hpp.index'))
            ->assertOk()
            ->assertDontSee('Memakai harga bahan asumsi');

        // …dan dengan mode asumsi, pita peringatannya wajib muncul.
        $this->actingAs($user)->get(route('analisa.hpp.index', ['asumsi' => 1]))
            ->assertOk()
            ->assertSee('Memakai harga bahan asumsi');
    }

    public function test_naikkan_semua_dan_kosongkan_semua(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)
            ->from(route('analisa.hpp.asumsi.index'))
            ->post(route('analisa.hpp.asumsi.bulk'), ['percent' => 10])
            ->assertSessionHas('success');

        $this->assertEquals(374_000, MaterialPriceAssumption::where('product_id', $this->akrilik)->value('price'));

        $this->actingAs($user)->delete(route('analisa.hpp.asumsi.clear'))->assertSessionHas('success');
        $this->assertDatabaseCount('material_price_assumptions', 0);
    }

    // ==========================================================

    private function produk(string $sku, string $nama): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => 0, 'sale_type' => 'ready',
            'is_sellable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function layer(int $productId, float $qty, float $cost, string $source, ?int $sourceId): void
    {
        DB::table('stock_layers')->insert([
            'product_id'   => $productId,
            'warehouse_id' => 1,
            'qty_in'       => $qty,
            'qty_remaining' => $qty,
            'unit_cost'    => $cost,
            'source_type'  => $source,
            'source_id'    => $sourceId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function bahan(int $orderId, int $productId, float $qty): void
    {
        // Barisnya berkaki pada OP sungguhan (ada foreign key-nya), jadi OP-nya dibuat dulu.
        DB::table('production_orders')->insertOrIgnore([
            'id'              => $orderId,
            'order_number'    => 'OP-' . $orderId,
            'type'            => 'ready_stock',
            'warehouse_id'    => 1,
            'production_date' => now()->toDateString(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('production_order_materials')->insert([
            'production_order_id' => $orderId,
            'product_id'          => $productId,
            'qty_required'        => $qty,
            'qty_consumed'        => $qty,
            'unit'                => 'pcs',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}
