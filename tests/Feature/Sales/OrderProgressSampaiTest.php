<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Selesai — Pesanan sudah diterima" baru boleh tampil setelah paketnya
 * SAMPAI (delivered_at), bukan begitu surat jalan penuh terbit.
 *
 * Dilaporkan 8 Okt 2026: lacak pesanan SO/2026/10/00001 menyorot Selesai
 * padahal perjalanan paketnya masih "Resi pengiriman dibuat — menunggu kurir".
 */
class OrderProgressSampaiTest extends TestCase
{
    use RefreshDatabase;

    private function pesananTerkirim(): array
    {
        $gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'RD-01', 'name' => 'Frame', 'sale_type' => 'ready',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true]);

        $pelanggan = \App\Models\Customer::create(['code' => 'C-UJI', 'name' => 'Pak Budi']);

        $so = new SalesOrder();
        $so->forceFill(['order_number' => 'SO-UJI-1', 'status' => 'confirmed', 'order_date' => now()->toDateString(),
            'customer_id' => $pelanggan->id,
            'warehouse_id' => $gudang, 'grand_total' => 100000, 'delivery_method' => 'kurir', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 1, 'unit_price' => 100000,
            'discount_type' => 'nominal', 'net_unit_price' => 100000, 'line_subtotal' => 100000,
            'line_discount' => 0, 'line_total' => 100000])->save();

        // Lunas, supaya tahapnya tidak tertahan di Pembayaran.
        \App\Modules\Sales\Models\SalesAdvance::create(['advance_number' => 'DP-1', 'sales_order_id' => $so->id,
            'customer_id' => $so->customer_id ?? 0, 'bank_account_id' => 1, 'advance_date' => now()->toDateString(),
            'amount' => 100000, 'status' => 'posted']);

        $sj = new SalesDelivery();
        $sj->forceFill(['delivery_number' => 'SJ-1', 'warehouse_id' => $gudang, 'delivery_date' => now()->toDateString(),
            'sales_order_id' => $so->id, 'status' => 'posted', 'tracking_number' => '201811307001'])->save();
        SalesDeliveryItem::create(['sales_delivery_id' => $sj->id, 'sales_order_item_id' => $item->id,
            'product_id' => $produk->id, 'qty' => 1]);

        return [$so, $sj];
    }

    public function test_surat_jalan_penuh_tapi_belum_sampai_masih_tahap_kirim(): void
    {
        [$so] = $this->pesananTerkirim();

        $this->assertSame('delivered', $so->getDeliveryStatus(), 'Prasyarat: semua barang sudah ber-SJ');
        $this->assertSame('kirim', app(OrderProgressService::class)->for($so)['current']);
    }

    public function test_setelah_ditandai_sampai_baru_selesai(): void
    {
        [$so, $sj] = $this->pesananTerkirim();

        $sj->markDelivered();

        $this->assertSame('selesai', app(OrderProgressService::class)->for($so->fresh())['current']);
    }
}
