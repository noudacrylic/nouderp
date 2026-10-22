<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ambil di toko: "Siap Diambil" baru menyala setelah ditandai siap di Pemrosesan
 * Pesanan (`ready_at`), bukan begitu pembeli membayar.
 *
 * Dilaporkan 22 Okt 2026: SO/2026/10/00027 baru DP sudah tampil "Siap Diambil",
 * karena pickup_status 'pending' terisi saat pembayaran.
 */
class OrderProgressAmbilTokoTest extends TestCase
{
    use RefreshDatabase;

    private function pesananAmbilToko(): SalesOrder
    {
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'RD-01', 'name' => 'Frame', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true]);
        $pelanggan = \App\Models\Customer::create(['code' => 'C-UJI', 'name' => 'Pak Budi']);

        $so = new SalesOrder();
        $so->forceFill(['order_number' => 'SO-UJI-1', 'status' => 'confirmed', 'order_date' => now()->toDateString(),
            'customer_id' => $pelanggan->id, 'warehouse_id' => $gudang, 'grand_total' => 100000,
            'delivery_method' => 'ambil_toko', 'pickup_status' => 'pending', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 1, 'unit_price' => 100000,
            'discount_type' => 'nominal', 'net_unit_price' => 100000, 'line_subtotal' => 100000,
            'line_discount' => 0, 'line_total' => 100000])->save();

        // DP, seperti pesanan yang dilaporkan.
        \App\Modules\Sales\Models\SalesAdvance::create(['advance_number' => 'DP-1', 'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id, 'bank_account_id' => 1, 'advance_date' => now()->toDateString(),
            'amount' => 50000, 'status' => 'posted']);

        return $so;
    }

    public function test_baru_dibayar_masih_disiapkan(): void
    {
        $so = $this->pesananAmbilToko();

        $this->assertSame('packing', app(OrderProgressService::class)->for($so)['current']);
    }

    public function test_setelah_ditandai_siap_baru_siap_diambil(): void
    {
        $so = $this->pesananAmbilToko();
        $so->forceFill(['ready_at' => now()])->save();

        $this->assertSame('ambil', app(OrderProgressService::class)->for($so->fresh())['current']);
    }

    public function test_sudah_diambil_selesai(): void
    {
        $so = $this->pesananAmbilToko();
        $so->forceFill(['ready_at' => now(), 'pickup_status' => 'picked_up', 'picked_up_at' => now()])->save();

        $this->assertSame('selesai', app(OrderProgressService::class)->for($so->fresh())['current']);
    }
}
