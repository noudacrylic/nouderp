<?php

namespace Tests\Feature\Production;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pencarian Sales Order di Buat Order Produksi.
 *
 * Dilaporkan 17 Okt 2026: SO/2026/09/00012 tak muncul di daftar karena query
 * mengambil 25 SO terbaru dulu lalu membuang yang sudah berfaktur di PHP —
 * SO lama yang masih menunggu OP terdesak keluar oleh SO baru yang sudah selesai.
 */
class CariSalesOrderUntukOpTest extends TestCase
{
    use RefreshDatabase;

    private int $gudang;
    private int $pelanggan;
    private int $produk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gudang    = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->pelanggan = \App\Models\Customer::create(['code' => 'C-UJI', 'name' => 'Adi'])->id;
        $this->produk    = Product::create(['sku' => 'CS3', 'name' => 'Box Charger', 'sale_type' => 'preorder',
            'base_unit' => 'pcs', 'base_price' => 100000, 'is_active' => true, 'is_sellable' => true])->id;
    }

    private function so(string $nomor, string $tanggal, bool $berfaktur): SalesOrder
    {
        $so = new SalesOrder();
        $so->forceFill(['order_number' => $nomor, 'status' => 'confirmed', 'order_date' => $tanggal,
            'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang, 'grand_total' => 100000,
            'delivery_method' => 'kurir', 'global_discount_type' => 'nominal'])->save();
        $item = new \App\Modules\Sales\Models\SalesOrderItem();
        $item->forceFill(['sales_order_id' => $so->id, 'product_id' => $this->produk, 'qty' => 1, 'unit_price' => 100000,
            'discount_type' => 'nominal', 'net_unit_price' => 100000, 'line_subtotal' => 100000,
            'line_discount' => 0, 'line_total' => 100000])->save();

        if ($berfaktur) {
            SalesInvoice::create(['invoice_number' => 'INV-' . $nomor, 'sales_order_id' => $so->id,
                'customer_id' => $this->pelanggan, 'warehouse_id' => $this->gudang, 'invoice_date' => $tanggal,
                'delivery_method' => 'kurir', 'status' => 'posted', 'subtotal' => 100000, 'grand_total' => 100000]);
        }

        return $so;
    }

    public function test_so_lama_tanpa_op_tetap_muncul_walau_banyak_so_baru_sudah_berfaktur(): void
    {
        $this->so('SO/2026/09/00012', '2026-09-20', berfaktur: false);
        for ($i = 1; $i <= 30; $i++) {
            $this->so(sprintf('SO/2026/10/%05d', $i), '2026-10-' . sprintf('%02d', min(28, $i)), berfaktur: true);
        }

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $hasil = $this->actingAs($admin)->getJson(route('production.ajax.sales-orders'))->assertOk()->json();

        $this->assertSame(['SO/2026/09/00012'], array_column($hasil, 'order_number'));
    }

    public function test_so_berfaktur_tidak_muncul(): void
    {
        $this->so('SO/2026/10/00001', '2026-10-01', berfaktur: true);

        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->assertSame([], $this->actingAs($admin)->getJson(route('production.ajax.sales-orders'))->json());
    }
}
