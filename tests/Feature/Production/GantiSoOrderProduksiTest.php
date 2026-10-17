<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Models\User;
use App\Modules\Production\Models\ProductionFinalization;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderOutput;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ganti SO pada order produksi (17 Okt 2026): pelanggan Risa pesan via Shopee,
 * produksi jalan, lalu pindah ke pesanan manual — SO Shopee void, OP harus bisa
 * dipindah ke SO baru.
 */
class GantiSoOrderProduksiTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private int $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->produk = Product::create(['sku' => 'BC-3x4', 'name' => 'Box Charger 3x4', 'sale_type' => 'preorder',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
    }

    private function so(string $nomor, string $status, ?int $produkId = null): SalesOrder
    {
        $pelanggan = \App\Models\Customer::create(['code' => 'C-' . $nomor, 'name' => 'Risa']);
        $so = new SalesOrder();
        $so->forceFill(['order_number' => $nomor, 'status' => $status, 'order_date' => now()->toDateString(),
            'customer_id' => $pelanggan->id, 'warehouse_id' => $this->gudang, 'grand_total' => 300000,
            'delivery_method' => 'kurir', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $produkId ?? $this->produk, 'qty' => 3,
            'unit_price' => 100000, 'discount_type' => 'nominal', 'net_unit_price' => 100000,
            'line_subtotal' => 300000, 'line_discount' => 0, 'line_total' => 300000])->save();

        return $so;
    }

    private function op(SalesOrder $so, string $status = 'in_progress'): ProductionOrder
    {
        $order = ProductionOrder::create(['order_number' => 'OP-' . uniqid(), 'type' => 'custom',
            'sales_order_id' => $so->id, 'warehouse_id' => $this->gudang, 'planned_cycles' => 3, 'planned_qty' => 3,
            'production_date' => now()->toDateString(), 'status' => $status]);
        ProductionOrderOutput::create(['production_order_id' => $order->id, 'product_id' => $this->produk,
            'qty_planned' => 3, 'qty_produced' => 0, 'output_type' => 'main', 'percentage' => 0]);

        return $order;
    }

    public function test_op_dan_stok_hasil_pindah_ke_so_baru(): void
    {
        $lama  = $this->so('SP-SHOPEE-1', 'void');
        $baru  = $this->so('SO/2026/10/00021', 'confirmed');
        $order = $this->op($lama, 'partial');

        $batch = ProductionFinalization::create(['production_order_id' => $order->id, 'sequence' => 1,
            'is_closing' => false, 'wip_released' => 100000, 'wip_total_snapshot' => 300000]);
        $layer = new StockLayer();
        $layer->forceFill(['product_id' => $this->produk, 'warehouse_id' => $this->gudang, 'qty_in' => 1,
            'qty_remaining' => 1, 'unit_cost' => 100000, 'source_type' => 'production_order',
            'production_finalization_id' => $batch->id, 'sales_order_id' => $lama->id])->save();

        app(ProductionOrderService::class)->gantiSalesOrder($order->id, $baru->id, 'Shopee batal, pindah manual');

        $this->assertSame($baru->id, $order->fresh()->sales_order_id);
        $this->assertSame($baru->id, (int) $layer->fresh()->sales_order_id);
        $this->assertDatabaseHas('production_order_so_changes', ['production_order_id' => $order->id,
            'from_sales_order_id' => $lama->id, 'to_sales_order_id' => $baru->id]);
    }

    public function test_so_lama_yang_masih_aktif_ditolak(): void
    {
        $order = $this->op($this->so('SO-AKTIF', 'confirmed'));
        $baru  = $this->so('SO-BARU', 'confirmed');

        $this->expectExceptionMessage('masih aktif');
        app(ProductionOrderService::class)->gantiSalesOrder($order->id, $baru->id, 'x');
    }

    public function test_so_baru_tanpa_produk_hasil_ditolak(): void
    {
        $lain  = Product::create(['sku' => 'TO-8', 'name' => 'Lain', 'sale_type' => 'preorder', 'base_unit' => 'pcs',
            'base_price' => 1, 'is_active' => true, 'is_sellable' => true])->id;
        $order = $this->op($this->so('SP-VOID', 'void'));
        $baru  = $this->so('SO-LAIN', 'confirmed', $lain);

        $this->expectExceptionMessage('tidak memuat produk');
        app(ProductionOrderService::class)->gantiSalesOrder($order->id, $baru->id, 'x');
    }

    public function test_tombol_dan_aksi_lewat_halaman(): void
    {
        $lama  = $this->so('SP-SHOPEE-2', 'void');
        $baru  = $this->so('SO-BARU-2', 'confirmed');
        $order = $this->op($lama);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('production.orders.show', $order->id))
            ->assertOk()->assertSee('Ganti SO');

        $this->actingAs($admin)->post(route('production.orders.ganti-so', $order->id),
            ['sales_order_id' => $baru->id, 'reason' => 'Shopee batal'])
            ->assertSessionHas('success');

        $this->actingAs($admin)->get(route('production.orders.show', $order->id))
            ->assertOk()->assertSee('SP-SHOPEE-2')->assertSee('SO-BARU-2')->assertDontSee('buka-ganti-so');
        $this->assertSame(1, DB::table('production_order_so_changes')->count());
    }
}
