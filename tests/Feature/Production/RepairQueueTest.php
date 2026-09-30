<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Production\Services\RepairQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Produksi › Barang Perbaikan: barang retur berkondisi Perbaikan menunggu di Gudang Perbaikan
 * sampai dibuatkan OP. Daftar ini harus menunjukkan asalnya (retur mana), dan unit yang sudah
 * dipesan OP aktif tidak boleh terhitung dua kali sebagai "belum masuk OP".
 */
class RepairQueueTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private Product $produk;
    private int $returId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::where('is_repair', true)->value('id')
            ?? Warehouse::create(['name' => 'Perbaikan', 'is_repair' => true])->id;

        $this->produk = Product::firstOrCreate(['sku' => 'TBKD-A5-T1'], [
            'name' => 'Tempat Brosur A5', 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 50000, 'is_active' => true, 'is_sellable' => true,
        ]);

        $cust = Customer::create(['code' => 'CUST-MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true]);
        $this->returId = DB::table('sales_returns')->insertGetId([
            'return_number' => 'SR/TES/001', 'customer_id' => $cust->id, 'return_date' => now()->subDays(40)->toDateString(),
            'grand_total' => 150000, 'status' => 'posted', 'stage' => 'selesai',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3 unit masuk Gudang Perbaikan dari retur itu (HPP 20.000/unit).
        ProductStock::create(['product_id' => $this->produk->id, 'warehouse_id' => $this->gudang, 'qty_on_hand' => 3]);
        StockLayer::create([
            'product_id' => $this->produk->id, 'warehouse_id' => $this->gudang,
            'qty_in' => 3, 'qty_remaining' => 3, 'unit_cost' => 20000,
            'source_type' => 'sales_return', 'source_id' => $this->returId,
        ]);
    }

    public function test_barang_di_gudang_perbaikan_tampil_dengan_asal_returnya(): void
    {
        $d = app(RepairQueueService::class)->data();
        $m = $d['menunggu']->firstWhere('product_id', $this->produk->id);

        $this->assertNotNull($m);
        $this->assertEqualsWithDelta(3, $m['qty'], 0.001);
        $this->assertEqualsWithDelta(3, $m['tersedia'], 0.001);
        $this->assertEqualsWithDelta(60000, $m['nilai'], 0.01);
        $this->assertSame('SR/TES/001', $m['asal'][0]['nomor']);
        $this->assertSame($this->returId, $m['asal'][0]['id']);
    }

    public function test_unit_yang_sudah_dipesan_op_aktif_tidak_dihitung_dua_kali(): void
    {
        $op = DB::table('production_orders')->insertGetId([
            'order_number' => 'OP-PRB-1', 'type' => 'perbaikan', 'status' => 'confirmed',
            'warehouse_id' => $this->gudang, 'planned_cycles' => 1, 'planned_qty' => 2,
            'production_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_order_materials')->insert([
            'production_order_id' => $op, 'product_id' => $this->produk->id,
            'qty_required' => 2, 'qty_consumed' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $d = app(RepairQueueService::class)->data();
        $m = $d['menunggu']->firstWhere('product_id', $this->produk->id);

        $this->assertEqualsWithDelta(2, $m['dipesan'], 0.001);
        $this->assertEqualsWithDelta(1, $m['tersedia'], 0.001, 'tinggal 1 unit yang belum masuk OP');
        $this->assertCount(1, $d['diproses']);
        $this->assertSame('OP-PRB-1', $d['diproses'][0]['nomor']);
    }

    public function test_halaman_barang_perbaikan_terbuka(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('production.perbaikan.index'))
            ->assertOk()
            ->assertSee('Barang Perbaikan')
            ->assertSee('SR/TES/001');
    }
}
