<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Models\ShopeeOrder;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeKatalogService;
use App\Modules\Marketplace\Shopee\Services\ShopeePesananService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 2 Shopee (bayangan, baca saja): peta SKU dari katalog, salinan pesanan, dan banding
 * dengan SO Jubelio ('SP-<order_sn>').
 */
class ShopeeBayanganTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://openplatform.sandbox.test-stable.shopee.sg';

    private ShopeeShop $shop;
    private int $frame;
    private int $plakat;
    private int $gudang;
    private int $mp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        $this->shop = ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        $this->frame  = $this->produk('FR-A4');
        $this->plakat = $this->produk('PL-01');
        $this->gudang = Warehouse::create(['name' => 'Gudang', 'is_sellable' => true, 'is_active' => true])->id;
        $this->mp     = Customer::create(['code' => 'MP', 'name' => 'Shopee', 'is_marketplace' => true, 'is_active' => true])->id;
    }

    private function produk(string $sku): int
    {
        return Product::create(['sku' => $sku, 'name' => $sku, 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 10000, 'is_active' => true, 'is_sellable' => true])->id;
    }

    private function so(string $sn, array $baris, string $status = 'confirmed'): SalesOrder
    {
        $so = SalesOrder::create(['order_number' => "SP-$sn", 'customer_po_number' => "SP-$sn", 'customer_id' => $this->mp,
            'warehouse_id' => $this->gudang, 'order_date' => now()->toDateString(), 'status' => $status, 'subtotal' => 0, 'grand_total' => 0]);
        foreach ($baris as [$pid, $qty, $harga]) {
            SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $pid, 'qty' => $qty, 'unit_price' => $harga,
                'net_unit_price' => $harga, 'line_subtotal' => $qty * $harga, 'line_discount' => 0, 'line_total' => $qty * $harga]);
        }
        return $so;
    }

    private function detail(string $sn, array $item, string $status = 'READY_TO_SHIP'): array
    {
        return ['order_sn' => $sn, 'order_status' => $status, 'create_time' => now()->timestamp, 'update_time' => now()->timestamp,
            'total_amount' => 50000, 'buyer_username' => 'rahasia', 'item_list' => $item];
    }

    private function baris(int $itemId, int $modelId, string $sku, int $qty, float $harga): array
    {
        return ['item_id' => $itemId, 'model_id' => $modelId, 'model_sku' => $sku, 'item_name' => 'Barang', 'model_name' => '',
            'model_quantity_purchased' => $qty, 'model_original_price' => $harga, 'model_discounted_price' => $harga];
    }

    public function test_katalog_dipetakan_lewat_sku_dan_peta_manual_tidak_ditimpa(): void
    {
        Http::fake([
            self::HOST . '/api/v2/product/get_item_list*' => fn ($req) => Http::response(['response' => [
                'item' => str_contains($req->url(), 'item_status=NORMAL') ? [['item_id' => 11], ['item_id' => 12]] : [],
                'has_next_page' => false], 'error' => '']),
            self::HOST . '/api/v2/product/get_item_base_info*' => Http::response(['response' => ['item_list' => [
                ['item_id' => 11, 'item_name' => 'Frame', 'item_sku' => 'FR-A4', 'has_model' => false, 'item_status' => 'NORMAL',
                 'price_info' => [['current_price' => 25000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]]],
                ['item_id' => 12, 'item_name' => 'Plakat', 'item_sku' => '', 'has_model' => true, 'item_status' => 'NORMAL'],
            ]], 'error' => '']),
            self::HOST . '/api/v2/product/get_model_list*' => Http::response(['response' => [
                'tier_variation' => [['name' => 'Ukuran', 'option_list' => [['option' => 'A5'], ['option' => 'A4']]]],
                'model' => [
                    ['model_id' => 91, 'model_sku' => 'PL-01', 'tier_index' => [0], 'price_info' => [['current_price' => 40000]]],
                    ['model_id' => 92, 'model_sku' => 'TIDAK-ADA', 'tier_index' => [1], 'price_info' => [['current_price' => 60000]]],
                ],
            ], 'error' => '']),
        ]);

        $hasil = app(ShopeeKatalogService::class)->tarik($this->shop);

        $this->assertSame(['item' => 2, 'varian' => 3, 'terpeta' => 2, 'belum' => 1], $hasil);
        $this->assertSame($this->frame, ShopeeItem::where('item_id', 11)->value('product_id'));
        $a4 = ShopeeItem::where('model_id', 92)->first();
        $this->assertSame('A4', $a4->nama_model);
        $this->assertNull($a4->product_id);

        // Peta manual bertahan saat katalog ditarik lagi.
        $a4->update(['product_id' => $this->plakat, 'peta_sumber' => ShopeeItem::PETA_MANUAL]);
        app(ShopeeKatalogService::class)->tarik($this->shop);
        $this->assertSame($this->plakat, $a4->fresh()->product_id);
    }

    public function test_pesanan_dibandingkan_dengan_so_jubelio(): void
    {
        $svc = app(ShopeePesananService::class);
        $this->so('AAA', [[$this->frame, 2, 25000]]);
        $this->so('BBB', [[$this->frame, 1, 25000]]);
        $this->so('CCC', [[$this->frame, 1, 25000]]);

        $cocok   = $svc->simpan($this->shop, $this->detail('AAA', [$this->baris(11, 0, 'FR-A4', 2, 25000)]));
        $qty     = $svc->simpan($this->shop, $this->detail('BBB', [$this->baris(11, 0, 'FR-A4', 3, 25000)]));
        $status  = $svc->simpan($this->shop, $this->detail('CCC', [$this->baris(11, 0, 'FR-A4', 1, 25000)], 'CANCELLED'));
        $hilang  = $svc->simpan($this->shop, $this->detail('DDD', [$this->baris(11, 0, 'FR-A4', 1, 25000)]));
        $belum   = $svc->simpan($this->shop, $this->detail('EEE', [$this->baris(13, 0, 'ASING', 1, 25000)], 'UNPAID'));

        $this->assertSame('cocok', $cocok->hasil_banding);
        $this->assertSame('beda_barang', $qty->hasil_banding);
        $this->assertStringContainsString('FR-A4: Shopee 3, SO 1', $qty->catatan_banding);
        $this->assertSame('beda_status', $status->hasil_banding);
        $this->assertSame('so_tidak_ada', $hilang->hasil_banding);
        $this->assertSame('belum_dibayar', $belum->hasil_banding);
        $this->assertArrayNotHasKey('buyer_username', $cocok->raw, 'Data pembeli tidak disimpan.');
        $this->assertSame(0, SalesOrder::where('order_number', 'SP-DDD')->count(), 'Bayangan tidak membuat SO.');
    }

    public function test_sku_belum_dipeta_lalu_cocok_setelah_peta_dipasang(): void
    {
        $svc = app(ShopeePesananService::class);
        $this->so('FFF', [[$this->plakat, 1, 40000]]);
        $o = $svc->simpan($this->shop, $this->detail('FFF', [$this->baris(12, 92, 'TIDAK-ADA', 1, 40000)]));
        $this->assertSame('sku_belum_dipeta', $o->hasil_banding);

        ShopeeItem::create(['env' => 'test', 'shop_id' => 555, 'item_id' => 12, 'model_id' => 92, 'nama' => 'Plakat',
            'model_sku' => 'TIDAK-ADA', 'product_id' => $this->plakat, 'peta_sumber' => ShopeeItem::PETA_MANUAL]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->from(route('settings.integrations.shopee.pesanan'))
            ->post(route('settings.integrations.shopee.pesanan.banding-ulang'))->assertRedirect();

        $this->assertSame('cocok', $o->fresh()->hasil_banding);
    }

    public function test_halaman_peta_dan_pesanan_terbuka_untuk_super_admin_saja(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $staf  = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($admin)->get(route('settings.integrations.shopee.peta-sku'))->assertOk()->assertSee('Peta SKU Shopee');
        $this->actingAs($admin)->get(route('settings.integrations.shopee.pesanan'))->assertOk()->assertSee('Pesanan Bayangan Shopee');
        $this->actingAs($staf)->get(route('settings.integrations.shopee.pesanan'))->assertForbidden();
    }
}
