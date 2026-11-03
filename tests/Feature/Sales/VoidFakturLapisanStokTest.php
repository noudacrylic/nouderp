<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\InventoryCostLayer;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Void faktur mengembalikan barang ke lapisan asalnya. Kasus OP/2026/10/00134: faktur di-void,
 * stok kembali sebagai lapisan baru tanpa ikatan OP — finalisasi tak bisa diedit dan faktur
 * ulang tertahan karena barang custom tak lagi terbaca milik pesanannya.
 */
class VoidFakturLapisanStokTest extends TestCase
{
    use RefreshDatabase;

    public function test_void_faktur_mengembalikan_barang_custom_ke_lapisan_op(): void
    {
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $produk = Product::create(['sku' => 'CS-VOID', 'name' => 'Custom', 'sale_type' => 'preorder', 'made_to_order' => true, 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create(['order_number' => 'SO-VOID-1', 'customer_id' => Customer::create(['code' => 'CV', 'name' => 'Hanif', 'is_active' => true])->id,
            'warehouse_id' => $gudang, 'order_date' => now()->toDateString(), 'status' => 'confirmed', 'global_discount_type' => 'nominal',
            'subtotal' => 253000, 'grand_total' => 253000, 'paid_amount' => 0]);
        $op = ProductionOrder::create(['order_number' => 'OP-VOID-1', 'type' => 'custom', 'sales_order_id' => $so->id, 'warehouse_id' => $gudang,
            'planned_cycles' => 1, 'planned_qty' => 11, 'production_date' => now()->toDateString(), 'status' => 'finalized']);
        $lapis = StockLayer::create(['product_id' => $produk->id, 'warehouse_id' => $gudang, 'qty_in' => 11, 'qty_remaining' => 11, 'unit_cost' => 4774.55,
            'source_type' => 'production_order', 'source_id' => $op->id]);

        $sj = SalesDelivery::create(['delivery_number' => 'DOINV-1', 'sales_order_id' => $so->id, 'warehouse_id' => $gudang, 'customer_id' => $so->customer_id,
            'delivery_date' => now()->toDateString(), 'status' => 'posted']);
        $sj->items()->create(['product_id' => $produk->id, 'qty' => 11]);

        $fifo = app(FifoService::class);
        $fifo->consume($produk->id, $gudang, 11, 'sales', $sj->id, $so->id, true);
        $this->assertSame(0.0, (float) $lapis->fresh()->qty_remaining);

        (new \ReflectionMethod(SalesInvoiceService::class, 'reverseDeliveryStock'))->invoke(app(SalesInvoiceService::class), $sj);

        $this->assertSame(11.0, (float) $lapis->fresh()->qty_remaining, 'Lapisan OP utuh lagi, jadi finalisasi bisa diedit.');
        $this->assertSame(0, StockLayer::where('source_type', 'sales_void')->count());
        $this->assertSame(11.0, $fifo->sisaMilikSo($produk->id, $gudang, $so->id), 'Faktur ulang menemukan barang milik pesanannya.');
        $this->assertSame(11.0, (float) InventoryCostLayer::where('reference_type', 'sales_void')->sum('qty_in'));
        $this->assertEqualsWithDelta(11 * 4774.55, $fifo->consume($produk->id, $gudang, 11, 'sales', $sj->id + 1, $so->id, true), 0.01);
    }
}
