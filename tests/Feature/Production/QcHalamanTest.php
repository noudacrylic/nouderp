<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Models\QcProfile;
use App\Modules\Production\Services\QcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Layar QC Timbang, Sampel Acuan & Toleransi. */
class QcHalamanTest extends TestCase
{
    use RefreshDatabase;

    private int $produk;
    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'tb-13-t1-m3', 'name' => 'Tempat Brosur', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true])->id;

        app(QcService::class)->simpanProfil(['product_id' => $this->produk, 'packaging_gram' => 15,
            'samples' => [855], 'components' => [['name' => 'Samping', 'qty' => 2, 'unit_gram' => 120]]], null);

        $this->order = ProductionOrder::create(['order_number' => 'OP/2026/10/00099', 'type' => 'ready_stock',
            'warehouse_id' => $gudang, 'planned_cycles' => 5, 'planned_qty' => 5,
            'production_date' => now()->toDateString(), 'status' => 'in_progress']);
        ProductionOrderOutput::create(['production_order_id' => $this->order->id, 'product_id' => $this->produk,
            'qty_planned' => 5, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 0]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** Petugas QC: role user dengan centang menu QC saja. */
    private function petugas(): User
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        DB::table('user_menu_permissions')->insert(['user_id' => $u->id, 'menu_key' => 'production.qc',
            'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    public function test_layar_timbang_tampil_dengan_op_dan_acuan(): void
    {
        $this->actingAs($this->admin())->get(route('production.qc.index', ['op' => $this->order->id]))
            ->assertOk()->assertSee('OP/2026/10/00099')->assertSee('855,0 g')->assertSee('timbangan.js');
    }

    public function test_timbang_lewat_json_mengembalikan_hasil_dan_ringkasan(): void
    {
        $this->actingAs($this->petugas())->postJson(route('production.qc.timbang'), [
            'production_order_id' => $this->order->id, 'product_id' => $this->produk,
            'gram' => 735, 'mode' => 'normal', 'source' => 'simulasi',
        ])->assertOk()
          ->assertJsonPath('check.result', 'kurang')
          ->assertJsonPath('check.kandidat.0.label', '1× Samping')
          ->assertJsonPath('ringkasan.menunggu', 1);
    }

    public function test_petugas_tidak_bisa_membuka_acuan_dan_melepas(): void
    {
        $petugas = $this->petugas();

        $this->actingAs($petugas)->get(route('production.qc-acuan.index'))->assertForbidden();
        $this->actingAs($petugas)->post(route('production.qc.lepas'), [
            'production_order_id' => $this->order->id, 'product_id' => $this->produk, 'qty' => 1, 'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_admin_melepas_dengan_alasan(): void
    {
        $this->actingAs($this->admin())->post(route('production.qc.lepas'), [
            'production_order_id' => $this->order->id, 'product_id' => $this->produk, 'qty' => 2, 'reason' => 'Timbangan rusak',
        ])->assertSessionHas('success');

        $this->assertSame(2.0, app(QcService::class)->ringkasan($this->order->fresh('outputs'), $this->produk)['boleh']);
    }

    public function test_halaman_acuan_dan_toleransi_tampil(): void
    {
        $admin = $this->admin();
        $profil = QcProfile::first();

        $this->actingAs($admin)->get(route('production.qc-acuan.index'))->assertOk()->assertSee('tb-13-t1-m3');
        $this->actingAs($admin)->get(route('production.qc-acuan.create'))->assertOk();
        $this->actingAs($admin)->get(route('production.qc-acuan.edit', $profil->id))->assertOk()->assertSee('Samping');
        $this->actingAs($admin)->get(route('production.qc-acuan.toleransi'))->assertOk();
    }

    public function test_simpan_profil_baru_lewat_form(): void
    {
        $lain = Product::create(['sku' => 'tb-13-t1-m4', 'name' => 'Tempat Brosur M4', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 1, 'is_active' => true, 'is_sellable' => true])->id;

        $this->actingAs($this->admin())->post(route('production.qc-acuan.store'), [
            'product_id' => $lain, 'packaging_gram' => 12, 'samples' => [900, 902], 'is_active' => 1,
            'components' => [['name' => 'Depan', 'qty' => 1, 'samples' => [220, 222]]],
        ])->assertRedirect();

        $p = QcProfile::where('product_id', $lain)->with('components')->first();
        $this->assertSame(901.0, $p->target_gram);
        $this->assertSame(221.0, $p->components->first()->unit_gram);
    }

    public function test_simpan_toleransi_bawaan(): void
    {
        $this->actingAs($this->admin())->post(route('production.qc-acuan.toleransi.save'), ['mode' => 'gram', 'value' => 5])
            ->assertSessionHas('success');

        $this->assertSame(['mode' => 'gram', 'value' => 5.0], app(QcService::class)->toleransiBawaan());
    }

    public function test_tab_qc_muncul_di_menu_produksi(): void
    {
        $this->actingAs($this->admin())->get(route('production.orders.index'))->assertOk()->assertSee('/erp/production/qc', false);
    }
}
