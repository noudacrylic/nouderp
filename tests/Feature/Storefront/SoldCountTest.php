<?php

namespace Tests\Feature\Storefront;

use App\Core\Inventory\Product;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jumlah terjual yang dipamerkan di halaman produk etalase.
 *
 * Angka ini dibaca calon pembeli sebagai janji, jadi yang dijaga di sini adalah
 * kejujurannya: faktur yang dibatalkan tidak boleh ikut menaikkan angka.
 */
class SoldCountTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);
    }

    private function invoiceWith(string $status, int $productId, float $qty): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'code'       => 'CUST-UJI-' . uniqid(),
            'name'       => 'Pembeli Uji',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = DB::table('warehouses')->insertGetId([
            'name'       => 'Gudang Uji ' . uniqid(),
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'INV-UJI-' . uniqid(),
            'customer_id'    => $customerId,
            'warehouse_id'   => $warehouseId,
            'invoice_date'   => now()->toDateString(),
            'status'         => $status,
            'grand_total'    => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('sales_invoice_items')->insert([
            'sales_invoice_id' => $invoiceId,
            'product_id'       => $productId,
            'description'      => 'Baris uji',
            'qty'              => $qty,
            'unit_price'       => 1000,
            'subtotal'         => 1000 * $qty,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_terjual_dihitung_dari_faktur_posted_dan_mengabaikan_yang_void(): void
    {
        $product = Product::create([
            'sku'         => 'UJI-TERJUAL',
            'name'        => 'Produk Uji Terjual',
            'sale_type'   => 'ready',
            'base_unit'   => 'pcs',
            'is_active'   => true,
            'is_sellable' => true,
        ]);

        $this->invoiceWith('posted', $product->id, 7);
        $this->invoiceWith('posted', $product->id, 5);
        // Faktur batal: barangnya tidak pernah jadi terjual.
        $this->invoiceWith('void', $product->id, 100);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'])
            ->getJson('/api/storefront/stock?product_ids=' . $product->id);

        $res->assertOk();
        $this->assertSame(12, $res->json('data.0.sold'));
    }

    public function test_produk_tanpa_penjualan_mengembalikan_nol(): void
    {
        $product = Product::create([
            'sku'         => 'UJI-BELUM-LAKU',
            'name'        => 'Produk Belum Laku',
            'sale_type'   => 'ready',
            'base_unit'   => 'pcs',
            'is_active'   => true,
            'is_sellable' => true,
        ]);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'])
            ->getJson('/api/storefront/stock?product_ids=' . $product->id);

        $res->assertOk();
        $this->assertSame(0, $res->json('data.0.sold'));
    }
}
