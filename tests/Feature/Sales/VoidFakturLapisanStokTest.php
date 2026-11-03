<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\FifoService;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockLayer;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\DebugInvoiceController;
use App\Models\Customer;
use App\Models\InventoryCostLayer;
use App\Models\SalesInvoice;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesDeliveryService;
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

    /** @return array{0:Product,1:SalesOrder,2:SalesDelivery,3:int} */
    private function pesananTerkirim(string $statusSo = 'confirmed'): array
    {
        $gudang = Warehouse::firstOrCreate(['name' => 'Gudang Test'])->id;
        $produk = Product::create(['sku' => 'CS-RSV', 'name' => 'Custom', 'sale_type' => 'preorder', 'made_to_order' => true, 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
        $so = SalesOrder::create(['order_number' => 'SO-RSV-1', 'customer_id' => Customer::create(['code' => 'CR', 'name' => 'Hanif', 'is_active' => true])->id,
            'warehouse_id' => $gudang, 'order_date' => now()->toDateString(), 'status' => $statusSo, 'global_discount_type' => 'nominal',
            'subtotal' => 100000, 'grand_total' => 100000, 'paid_amount' => 0]);
        $sj = SalesDelivery::create(['delivery_number' => 'DOINV-RSV', 'sales_order_id' => $so->id, 'warehouse_id' => $gudang, 'customer_id' => $so->customer_id,
            'delivery_date' => now()->toDateString(), 'status' => 'posted']);
        $sj->items()->create(['product_id' => $produk->id, 'qty' => 5, 'cogs_deferred' => true]);

        return [$produk, $so, $sj, $gudang];
    }

    public function test_void_mengembalikan_reservasi_pesanan_yang_masih_berjalan(): void
    {
        [$produk, $so, $sj] = $this->pesananTerkirim();

        app(SalesDeliveryService::class)->kembalikanStokItem($sj, $sj->items()->with('product')->first());

        $this->assertSame(5.0, (float) StockReservation::where(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'status' => 'active'])->sum('qty'));
    }

    public function test_void_menambah_baris_reservasi_yang_sudah_ada(): void
    {
        [$produk, $so, $sj, $gudang] = $this->pesananTerkirim();
        StockReservation::create(['product_id' => $produk->id, 'warehouse_id' => $gudang, 'sales_order_id' => $so->id, 'qty' => 3, 'status' => 'active']);

        app(SalesDeliveryService::class)->kembalikanStokItem($sj, $sj->items()->with('product')->first());

        $baris = StockReservation::where('sales_order_id', $so->id)->get();
        $this->assertCount(1, $baris);
        $this->assertSame(8.0, (float) $baris->first()->qty);
    }

    public function test_void_pesanan_yang_sudah_batal_tidak_membuat_reservasi(): void
    {
        [, $so, $sj] = $this->pesananTerkirim('void');

        app(SalesDeliveryService::class)->kembalikanStokItem($sj, $sj->items()->with('product')->first());

        $this->assertSame(0, StockReservation::where('sales_order_id', $so->id)->count());
    }

    private function fakturPesanan(string $status): array
    {
        [$produk, $so, , $gudang] = $this->pesananTerkirim();
        $baris = (new SalesOrderItem)->forceFill(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 5, 'qty_invoiced' => 5, 'unit_price' => 1000,
            'discount_type' => 'nominal', 'net_unit_price' => 1000, 'line_subtotal' => 5000, 'line_discount' => 0, 'line_total' => 5000]);
        $baris->save();
        $faktur = SalesInvoice::create(['invoice_number' => 'INV-HPS-1', 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $gudang,
            'invoice_date' => now()->toDateString(), 'subtotal' => 5000, 'grand_total' => 5000, 'status' => $status]);
        $faktur->items()->forceCreate(['sales_invoice_id' => $faktur->id, 'sales_order_item_id' => $baris->id, 'product_id' => $produk->id, 'description' => 'x', 'qty' => 5, 'unit_price' => 1000]);

        return [$baris, $faktur];
    }

    public function test_hapus_draft_faktur_mengembalikan_qty_invoiced_supaya_bisa_difaktur_ulang(): void
    {
        [$baris, $faktur] = $this->fakturPesanan('draft');

        app(DebugInvoiceController::class)->destroy($faktur->id);

        $this->assertNull(SalesInvoice::find($faktur->id));
        $this->assertSame(0.0, (float) $baris->fresh()->qty_invoiced);
    }

    public function test_hapus_faktur_void_tidak_mengurangi_qty_invoiced_dua_kali(): void
    {
        [$baris, $faktur] = $this->fakturPesanan('void');

        app(DebugInvoiceController::class)->destroy($faktur->id);

        $this->assertSame(5.0, (float) $baris->fresh()->qty_invoiced);
    }
}
