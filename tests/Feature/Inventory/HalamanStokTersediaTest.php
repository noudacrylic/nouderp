<?php

namespace Tests\Feature\Inventory;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\Services\LajuJualService;
use App\Core\Inventory\Warehouse;
use App\Http\Controllers\Inventory\StockController;
use App\Models\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Kolom Tersedia di halaman Stok menunjukkan barang, bukan izin jual. Kasus CS1: stok 104 milik
 * satu pesanan + kuota preorder 100 tampil "Tersedia 204, cukup 27 hari".
 */
class HalamanStokTersediaTest extends TestCase
{
    use RefreshDatabase;

    private function produk(int $kuota = 0): Product
    {
        return Product::create(['sku' => 'CS-STK', 'name' => 'Custom', 'sale_type' => 'preorder', 'preorder_stock' => $kuota, 'base_unit' => 'pcs', 'base_price' => 1000, 'is_active' => true, 'is_sellable' => true]);
    }

    public function test_tersedia_tidak_menghitung_kuota_preorder_dan_kuota_tampil_terpisah(): void
    {
        $produk = $this->produk(100);
        $gudang = Warehouse::create(['name' => 'Jual', 'is_sellable' => true]);
        ProductStock::create(['product_id' => $produk->id, 'warehouse_id' => $gudang->id, 'qty_on_hand' => 10]);

        $baris = app(StockController::class)->index(new Request(['search' => 'CS-STK']))->getData()['stocks']->first();

        $this->assertSame(10.0, (float) $baris->available);
        $this->assertSame(100.0, (float) $baris->kuota_preorder);
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
