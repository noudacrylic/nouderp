<?php

namespace Tests\Feature\Inventory;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Services\LajuJualService;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Inventory\StockController;
use App\Models\InventoryLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Kolom Tersedia di halaman Stok = stok gudang jual - dipesan + kuota preorder; kuotanya juga
 * tampil di kolom sendiri supaya terlihat berapa bagian yang bukan barang.
 */
class HalamanStokTersediaTest extends TestCase
{
    use RefreshDatabase;

    private function produk(int $kuota = 0): Product
    {
        return Product::create(['sku' => 'CS-STK', 'name' => 'Custom', 'sale_type' => 'preorder', 'preorder_stock' => $kuota, 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
    }

    public function test_tersedia_menjumlahkan_stok_fisik_dan_kuota_preorder(): void
    {
        $produk = $this->produk(100);
        $gudang = Warehouse::create(['name' => 'Jual', 'is_sellable' => true]);
        ProductStock::create(['product_id' => $produk->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 10]);

        $baris = app(StockController::class)->index(new Request(['search' => 'CS-STK']))->getData()['stocks']->first();

        $this->assertSame(110.0, (float) $baris->available);
        $this->assertSame(100.0, (float) $baris->kuota_preorder);
    }

    public function test_kolom_cukup_hanya_tampil_untuk_produk_non_preorder(): void
    {
        $gudang = Warehouse::create(['name' => 'Jual', 'is_sellable' => true]);
        $halaman = function (string $tipe) use ($gudang) {
            $produk = Product::create(['sku' => 'STK-' . $tipe, 'name' => 'Produk', 'sale_type' => $tipe, 'preorder_stock' => 5, 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
            ProductStock::create(['product_id' => $produk->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 10]);
            InventoryLedger::create(['product_id' => $produk->id, 'warehouse_id' => $gudang->id, 'transaction_type' => 'sale', 'transaction_id' => 1, 'qty_in' => 0, 'qty_out' => 30, 'balance' => 0]);

            return app(StockController::class)->index(new Request(['search' => $produk->sku]))->render();
        };

        $this->actingAs(User::factory()->create());
        view()->share('errors', new ViewErrorBag);

        $this->assertStringNotContainsString('pcs ÷ 30', $halaman('preorder'));
        $this->assertStringContainsString('pcs ÷ 30', $halaman('ready'));
    }

    public function test_laku_per_hari_mengurangkan_penjualan_yang_di_void(): void
    {
        $produk = $this->produk();
        $gudang = Warehouse::create(['name' => 'Jual', 'is_sellable' => true]);
        $catat = fn (string $jenis, int $masuk, int $keluar) => InventoryLedger::create(['product_id' => $produk->id, 'warehouse_id' => $gudang->id,
            'transaction_type' => $jenis, 'transaction_id' => 1, 'qty_in' => $masuk, 'qty_out' => $keluar, 'balance' => 0]);

        $catat('sale', 0, 30);
        $catat('sale', 0, 60);
        $catat('sales_void', 60, 0);

        $this->assertEqualsWithDelta(1.0, app(LajuJualService::class)->perHari([$produk->id])[$produk->id], 0.001);
    }
}
