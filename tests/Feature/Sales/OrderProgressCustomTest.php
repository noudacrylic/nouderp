<?php

namespace Tests\Feature\Sales;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\OrderProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesanan custom yang baru DP dan BELUM dibuatkan OP tetap berada di tahap
 * Produksi, bukan Packing.
 *
 * Dilaporkan 17 Okt 2026: SO/2026/09/00012 (CS3) tampil "Packing — Menunggu
 * pelunasan sebelum dikirim" di CRM & lacak pesanan padahal barangnya belum dibuat.
 */
class OrderProgressCustomTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;

    private function pesanan(string $saleType, bool $madeToOrder = true): SalesOrder
    {
        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $produk = Product::create(['sku' => 'CS3', 'name' => 'Box Charger', 'sale_type' => $saleType,
            'made_to_order' => $madeToOrder, 'base_unit' => 'pcs', 'base_price' => 200000,
            'is_active' => true, 'is_sellable' => true]);

        $pelanggan = \App\Models\Customer::create(['code' => 'C-UJI', 'name' => 'Adi']);

        $so = new SalesOrder();
        $so->forceFill(['order_number' => 'SO-UJI-1', 'status' => 'confirmed', 'order_date' => now()->toDateString(),
            'customer_id' => $pelanggan->id, 'warehouse_id' => $this->gudang, 'grand_total' => 200000,
            'delivery_method' => 'kurir', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $produk->id, 'qty' => 1, 'unit_price' => 200000,
            'discount_type' => 'nominal', 'net_unit_price' => 200000, 'line_subtotal' => 200000,
            'line_discount' => 0, 'line_total' => 200000])->save();

        // DP 50%.
        \App\Modules\Sales\Models\SalesAdvance::create(['advance_number' => 'DP-1', 'sales_order_id' => $so->id,
            'customer_id' => $pelanggan->id, 'bank_account_id' => 1, 'advance_date' => now()->toDateString(),
            'amount' => 100000, 'status' => 'posted']);

        return $so;
    }

    public function test_custom_tanpa_op_berada_di_tahap_produksi(): void
    {
        $p = app(OrderProgressService::class)->for($this->pesanan('preorder'));

        $this->assertSame('produksi', $p['current']);
        $this->assertContains('produksi', array_column($p['steps'], 'key'));
    }

    public function test_barang_ready_tetap_tanpa_tahap_produksi(): void
    {
        $p = app(OrderProgressService::class)->for($this->pesanan('ready'));

        $this->assertSame('packing', $p['current']);
        $this->assertNotContains('produksi', array_column($p['steps'], 'key'));
    }

    public function test_preorder_spesifikasi_tetap_tanpa_op_tidak_ditahan_di_produksi(): void
    {
        $p = app(OrderProgressService::class)->for($this->pesanan('preorder', madeToOrder: false));

        $this->assertSame('packing', $p['current']);
    }

    public function test_waiver_produksi_melewati_tahap_produksi(): void
    {
        $so = $this->pesanan('preorder');
        $so->forceFill(['production_waived_at' => now()])->save();

        $this->assertSame('packing', app(OrderProgressService::class)->for($so->fresh())['current']);
    }

    public function test_sudah_ber_surat_jalan_tanpa_op_tidak_kembali_ke_produksi(): void
    {
        $so = $this->pesanan('preorder');
        $sj = new SalesDelivery();
        $sj->forceFill(['delivery_number' => 'SJ-1', 'warehouse_id' => $this->gudang, 'delivery_date' => now()->toDateString(),
            'sales_order_id' => $so->id, 'status' => 'posted', 'tracking_number' => '201811307001'])->save();

        $this->assertNotSame('produksi', app(OrderProgressService::class)->for($so->fresh())['current']);
    }
}
