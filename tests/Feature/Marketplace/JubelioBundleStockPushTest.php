<?php

namespace Tests\Feature\Marketplace;

use App\Core\Inventory\BundleComponent;
use App\Core\Inventory\InventoryEngine;
use App\Core\Inventory\Product;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Models\JubelioSetting;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioStockSyncService;
use App\Services\BundleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Stok yang didorong ke Jubelio harus bersih dari komitmen BUNDLE.
 *
 * Jubelio menahan stok per ITEM yang dipesan pembeli; ERP mereservasi per produk yang
 * dipakai. Untuk penjualan biasa keduanya sama, untuk bundle BERBEDA: pembeli memesan item
 * bundle, ERP mereservasi komponennya. Aturan lama mengecualikan SELURUH reservasi pesanan
 * marketplace, sehingga reservasi komponen dari pesanan bundle ikut diabaikan padahal item
 * komponen di Jubelio tidak pernah ditahan — komponen tetap ditawarkan penuh dan barang yang
 * sama dijanjikan dua kali sampai stok tersedia minus dalam.
 *
 * Aturan sekarang: hanya kecualikan reservasi yang pasangannya benar-benar ditahan Jubelio
 * atas item yang sama (JubelioOrderLink::coveredReservationQty).
 */
class JubelioBundleStockPushTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM_KOMPONEN = 3001;
    private const ITEM_BUNDLE   = 3002;

    private int $warehouseId;
    private int $customerId;
    private Product $komponen;
    private Product $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouseId = Warehouse::firstOrCreate(
            ['name' => 'Gudang Test'],
            ['is_sellable' => true]
        )->id;

        $this->customerId = Customer::create([
            'code' => 'CUST-MP', 'name' => 'Shopee Official', 'is_marketplace' => true, 'is_active' => true,
        ])->id;

        $this->komponen = $this->product('AM-40x30x6', 'Frame Mahar 40x30x6 Instant', 'ready', self::ITEM_KOMPONEN);
        $this->bundle   = $this->product('AM-40x30x6-EX', 'Frame Mahar 40x30x6 Extra Buble', 'bundle', self::ITEM_BUNDLE);

        BundleComponent::create([
            'bundle_product_id'    => $this->bundle->id,
            'component_product_id' => $this->komponen->id,
            'qty'                  => 1,
        ]);

        // Stok fisik komponen = 10 (ledger + FIFO layer, agar plafon anti-oversell tidak menggigit).
        app(InventoryEngine::class)->purchase(
            $this->komponen->id, $this->warehouseId, 10, 50000, 'test-opening'
        );

        $s = JubelioSetting::find(1) ?: new JubelioSetting();
        $s->forceFill([
            'id'                  => 1,
            'username'            => 'a@b.com',
            'password'            => 'secret',
            'is_active'           => true,
            'base_url'            => JubelioSetting::DEFAULT_BASE_URL,
            'default_location_id' => 1,
        ])->save();
    }

    private function product(string $sku, string $name, string $saleType, int $jubelioItemId): Product
    {
        $p = Product::create([
            'sku'        => $sku,
            'name'       => $name,
            'sale_type'  => $saleType,
            'base_unit'  => 'Pcs',
            'base_price' => 100000,
            'is_active'  => true,
        ]);

        $p->forceFill([
            'sync_to_jubelio' => true,
            'jubelio_item_id' => $jubelioItemId,
        ])->save();

        return $p;
    }

    /**
     * Pesanan marketplace: baris pesanan + JubelioOrderLink + reservasi ERP.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines    baris yang DIPESAN pembeli
     * @param array<int, array{product_id:int, qty:float}> $reserves reservasi yang dibuat ERP
     */
    private function marketplaceOrder(int $soId, array $lines, array $reserves): void
    {
        $this->orderLines($soId, $lines);

        JubelioOrderLink::create([
            'jubelio_salesorder_id' => 900000 + $soId,
            'jubelio_salesorder_no' => 'MP-' . $soId,
            'sales_order_id'        => $soId,
        ]);

        $this->reserve($soId, $reserves);
    }

    /** Pesanan lokal (dibuat di ERP): baris + reservasi, TANPA JubelioOrderLink. */
    private function localOrder(int $soId, array $lines, array $reserves): void
    {
        $this->orderLines($soId, $lines);
        $this->reserve($soId, $reserves);
    }

    private function orderLines(int $soId, array $lines): void
    {
        // Baris pesanan punya FK ke sales_orders — cukup induk minimal, isinya tidak dipakai
        // perhitungan stok (yang dibaca hanya product_id & qty).
        DB::table('sales_orders')->insert([
            'id'                   => $soId,
            'order_number'         => 'SO-' . $soId,
            'customer_id'          => $this->customerId,
            'warehouse_id'         => $this->warehouseId,
            'order_date'           => now()->toDateString(),
            'global_discount_type' => 'nominal',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        foreach ($lines as $l) {
            DB::table('sales_order_items')->insert([
                'sales_order_id'     => $soId,
                'product_id'         => $l['product_id'],
                'qty'                => $l['qty'],
                'conversion_to_base' => 1,
                'unit_price'         => 100000,
                'discount_per_unit'  => 0,
                'net_unit_price'     => 100000,
                'line_subtotal'      => 100000 * $l['qty'],
                'line_discount'      => 0,
                'line_total'         => 100000 * $l['qty'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    private function reserve(int $soId, array $reserves): void
    {
        foreach ($reserves as $r) {
            StockReservation::create([
                'product_id'     => $r['product_id'],
                'warehouse_id'   => $this->warehouseId,
                'sales_order_id' => $soId,
                'qty'            => $r['qty'],
                'status'         => 'active',
            ]);
        }
    }

    /**
     * Jalankan pushProduct dgn client palsu, kembalikan angka yang berakhir di Jubelio.
     * $baseline = stok fisik (end_qty) Jubelio saat ini.
     */
    private function pushed(Product $product, float $baseline): float
    {
        $client = Mockery::mock(JubelioClient::class);
        $client->shouldReceive('isReady')->andReturn(true);
        $client->shouldReceive('getItemAvailable')->andReturn($baseline);
        $client->shouldReceive('getDefaultBin')->andReturn(['success' => true, 'data' => ['bin_id' => 1]]);
        $client->shouldReceive('postAdjustment')->andReturn(['success' => true, 'status' => 200, 'data' => [], 'error' => null]);
        $this->app->instance(JubelioClient::class, $client);

        $this->app->make(JubelioStockSyncService::class)->pushProduct($product, true);

        return (float) $product->fresh()->jubelio_synced_qty;
    }

    private function bundleStock(): int
    {
        return app(BundleService::class)->getBundleStock($this->bundle->id, null, false, true);
    }

    /**
     * Kasus yang dilaporkan: fisik 10, masuk pesanan marketplace 10 BUNDLE.
     * Jubelio memotong item bundle-nya sendiri, tapi item KOMPONEN di sana tidak tersentuh.
     * ERP wajib mendorong komponen ke 0, kalau tidak komponen masih bisa dipesan 10 lagi.
     */
    public function test_pesanan_bundle_marketplace_menghabiskan_stok_komponen_yang_didorong(): void
    {
        $this->marketplaceOrder(
            soId: 501,
            lines: [['product_id' => $this->bundle->id, 'qty' => 10]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 10]],
        );

        $this->assertSame(0.0, $this->pushed($this->komponen, 10),
            'komponen harus didorong ke 0 — Jubelio menahan item bundle, bukan item komponen');
    }

    /**
     * Kebalikannya tidak boleh ikut rusak: penjualan LANGSUNG komponen di marketplace sudah
     * ditahan Jubelio pada item yang sama, jadi tidak boleh dipotong dua kali.
     */
    public function test_pesanan_komponen_langsung_tidak_dipotong_dua_kali(): void
    {
        $this->marketplaceOrder(
            soId: 502,
            lines: [['product_id' => $this->komponen->id, 'qty' => 4]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 4]],
        );

        $this->assertSame(10.0, $this->pushed($this->komponen, 10),
            'Jubelio sudah menahan 4 di item komponen; ERP tidak boleh mengurangi lagi');
    }

    /** Pesanan lokal (SO dibuat di ERP) tidak diketahui Jubelio → tetap harus dikurangi. */
    public function test_pesanan_lokal_tetap_mengurangi_stok_yang_didorong(): void
    {
        $this->localOrder(
            soId: 503,
            lines: [['product_id' => $this->komponen->id, 'qty' => 4]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 4]],
        );

        $this->assertSame(6.0, $this->pushed($this->komponen, 10));
    }

    /**
     * Satu SO marketplace memuat bundle SEKALIGUS komponennya. Hanya baris langsungnya (3)
     * yang ditahan Jubelio di item komponen; 2 dari bundle tetap harus dikurangi.
     */
    public function test_pesanan_campuran_hanya_mengecualikan_baris_langsungnya(): void
    {
        $this->marketplaceOrder(
            soId: 504,
            lines: [
                ['product_id' => $this->bundle->id,   'qty' => 2],
                ['product_id' => $this->komponen->id, 'qty' => 3],
            ],
            reserves: [
                ['product_id' => $this->komponen->id, 'qty' => 2], // dari baris bundle
                ['product_id' => $this->komponen->id, 'qty' => 3], // dari baris langsung
            ],
        );

        $this->assertSame(8.0, $this->pushed($this->komponen, 10),
            'yang dikecualikan hanya 3 (baris langsung), bukan seluruh 5');
    }

    /**
     * Arah sebaliknya: komponen laku satuan di marketplace → bahan bundle berkurang, jadi
     * stok BUNDLE yang didorong harus ikut turun. Jubelio tak tahu kaitannya.
     */
    public function test_penjualan_komponen_menurunkan_stok_bundle_yang_didorong(): void
    {
        $this->marketplaceOrder(
            soId: 505,
            lines: [['product_id' => $this->komponen->id, 'qty' => 3]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 3]],
        );

        $this->assertSame(7, $this->bundleStock(),
            'bahan tinggal 7 → bundle yang boleh ditawarkan tinggal 7');
    }

    /**
     * Tapi pesanan atas BUNDLE ITU SENDIRI sudah ditahan Jubelio di item bundle-nya, jadi
     * stok bundle yang didorong tidak boleh ikut dipotong (nanti dobel).
     */
    public function test_pesanan_bundle_sendiri_tidak_memotong_stok_bundle_yang_didorong(): void
    {
        $this->marketplaceOrder(
            soId: 506,
            lines: [['product_id' => $this->bundle->id, 'qty' => 4]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 4]],
        );

        $this->assertSame(10, $this->bundleStock(),
            'Jubelio sudah menahan 4 di item bundle; memotong di sini = pengurangan ganda');
    }

    /**
     * Setelah Surat Jalan terbit reservasi hilang & stok fisik turun. Angka akhirnya harus
     * sama dengan sebelum SJ — tidak boleh turun dua kali.
     */
    public function test_setelah_surat_jalan_angka_tidak_turun_dua_kali(): void
    {
        $this->marketplaceOrder(
            soId: 507,
            lines: [['product_id' => $this->bundle->id, 'qty' => 10]],
            reserves: [['product_id' => $this->komponen->id, 'qty' => 10]],
        );

        $this->assertSame(0.0, $this->pushed($this->komponen, 10));

        // SJ terbit: reservasi dilepas, stok fisik terkonsumsi.
        StockReservation::where('sales_order_id', 507)->update(['status' => 'released']);
        app(InventoryEngine::class)->ship($this->komponen->id, $this->warehouseId, 10, 'test-sj', 1, 507);

        $this->assertSame(0.0, $this->pushed($this->komponen, 0),
            'fisik sudah 0 & reservasi hilang → tetap 0, bukan minus');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
