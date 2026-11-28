<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\Customer;
use App\Models\StoreProduct;
use App\Models\StoreProductMedia;
use App\Models\StoreProductVariant;
use App\Models\User;
use App\Modules\POS\Services\FulfillmentReadinessService;
use App\Modules\POS\Services\GambarProdukService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kartu Pemrosesan Pesanan menampilkan gambar produk dari produk WEB — supaya varian yang
 * bedanya cuma desain (Akrilik Sign Kiri vs Kanan) tak salah kirim. SKU AS-* tanpa gambar diberi
 * peringatan.
 */
class FulfillmentGambarProdukTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    private function produk(string $sku): Product
    {
        return Product::create(['sku' => $sku, 'name' => "Produk $sku", 'base_unit' => 'pcs', 'base_price' => 23900, 'is_active' => true, 'is_sellable' => true]);
    }

    /** Produk web + galeri; $varian = [product_id => indeks foto varian atau null]. */
    private function web(array $varian, array $foto, string $status = 'published'): array
    {
        $this->n++;
        $sp = StoreProduct::create(['name' => "Web {$this->n}", 'slug' => "web-{$this->n}", 'status' => $status]);
        $media = [];
        foreach ($foto as $i => [$url, $utama]) {
            $media[] = StoreProductMedia::create(['store_product_id' => $sp->id, 'group' => 'gallery', 'kind' => 'image', 'source' => 'r2',
                'url' => $url, 'is_primary' => $utama, 'sort_order' => $i]);
        }
        foreach (array_keys($varian) as $urut => $pid) {
            StoreProductVariant::create(['store_product_id' => $sp->id, 'product_id' => $pid, 'variant_label' => "V$urut", 'sort_order' => $urut,
                'image_media_id' => $varian[$pid] === null ? null : $media[$varian[$pid]]->id]);
        }
        return $media;
    }

    public function test_foto_varian_didahulukan_lalu_foto_utama_galeri(): void
    {
        $kiri  = $this->produk('AS-JE-3010-KI-STD');
        $kanan = $this->produk('AS-JE-3010-KA-STD');
        $atas  = $this->produk('AS-JE-3010-AT-STD');
        $this->web([$kiri->id => 1, $kanan->id => 2, $atas->id => null],
            [['https://g/utama.jpg', true], ['https://g/kiri.jpg', false], ['https://g/kanan.jpg', false]]);

        $g = app(GambarProdukService::class);
        $this->assertSame('https://g/kiri.jpg', $g->url($kiri->id));
        $this->assertSame('https://g/kanan.jpg', $g->url($kanan->id));
        $this->assertSame('https://g/utama.jpg', $g->url($atas->id), 'Varian tanpa foto sendiri → foto utama galeri.');
        $this->assertNull($g->url($this->produk('NM-1')->id));
    }

    public function test_draf_dipakai_dan_foto_terhapus_diabaikan(): void
    {
        $p = $this->produk('AS-EX-3010-KI-STD');
        $media = $this->web([$p->id => 1], [['https://g/utama.jpg', true], ['https://g/varian.jpg', false]], 'draft');
        $this->assertSame('https://g/varian.jpg', (new GambarProdukService)->url($p->id), 'Draf (belum tayang) tetap dipakai.');

        $media[1]->delete();
        $this->assertSame('https://g/utama.jpg', (new GambarProdukService)->url($p->id), 'Foto varian terhapus → kembali ke foto utama.');

        $media[0]->delete();
        $this->assertNull((new GambarProdukService)->url($p->id), 'Semua foto terhapus (menunggu GC) → tanpa gambar.');
    }

    public function test_wajib_gambar_hanya_akrilik_sign(): void
    {
        $this->assertTrue(GambarProdukService::wajib('AS-TK-3040-TP-STD'));
        $this->assertTrue(GambarProdukService::wajib('as-je-3010-ki-std'));
        $this->assertFalse(GambarProdukService::wajib('BAS-3010'));
        $this->assertFalse(GambarProdukService::wajib(null));
    }

    public function test_kartu_menampilkan_gambar_dan_peringatan_tanpa_gambar(): void
    {
        $kiri  = $this->produk('AS-JE-3010-KI-STD');
        $kanan = $this->produk('AS-JE-3010-KA-STD');
        $nm    = $this->produk('NM-1');
        $this->web([$kiri->id => 0], [['https://g/kiri.jpg', true]]);

        $so = SalesOrder::create(['order_number' => 'SO-G1', 'customer_id' => Customer::create(['code' => 'C1', 'name' => 'Budi', 'is_active' => true])->id,
            'warehouse_id' => Warehouse::create(['name' => 'Utama', 'is_sellable' => true, 'is_active' => true])->id,
            'order_date' => now()->toDateString(), 'status' => 'confirmed', 'subtotal' => 0, 'grand_total' => 0]);
        foreach ([$kiri, $kanan, $nm] as $p) {
            SalesOrderItem::create(['sales_order_id' => $so->id, 'product_id' => $p->id, 'description' => $p->name,
                'qty' => 1, 'unit_price' => 23900, 'net_unit_price' => 23900, 'line_subtotal' => 23900, 'line_discount' => 0, 'line_total' => 23900]);
        }

        $m = new \ReflectionMethod(FulfillmentReadinessService::class, 'rowsFor');
        $row = $m->invoke(app(FulfillmentReadinessService::class), collect([$so->fresh()]))->first();
        $html = view('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'perlu_diproses'])->render();

        $this->assertStringContainsString('src="https://g/kiri.jpg"', $html);
        $this->assertStringContainsString('w-24 h-24', $html, 'Akrilik Sign tampil besar.');
        $this->assertSame(1, substr_count($html, 'Tanpa gambar'), 'Hanya AS Kanan yang diperingatkan; NM-1 tidak.');
    }
}
