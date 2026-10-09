<?php

namespace Tests\Feature\POS;

use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;
use App\Models\StoreProduct;
use App\Models\StoreProductMedia;
use App\Models\StoreProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kasir (9 Okt 2026): foto produk dari toko online, dan varian yang hanya
 * dijual online bisa disembunyikan dari layar kasir oleh admin / staf yang
 * diberi izin `pos.kasir-atur-produk`.
 */
class KasirProdukTampilTest extends TestCase
{
    use RefreshDatabase;

    private function produk(string $sku, bool $tampil = true): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => 'Frame ' . $sku, 'sale_type' => 'ready', 'base_unit' => 'pcs',
            'base_price' => 100000, 'is_active' => true, 'is_sellable' => true, 'tampil_di_kasir' => $tampil,
        ]);
    }

    private function staf(array $izin = []): User
    {
        $u = User::factory()->create(['role' => 'user', 'is_active' => true]);
        foreach (['pos.kasir', ...$izin] as $key) {
            $u->menuPermissions()->create(['menu_key' => $key]);
        }

        return $u;
    }

    private function sku($res): array
    {
        return collect($res->json())->pluck('sku')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Warehouse::create(['name' => 'Toko', 'is_sellable' => true, 'is_active' => true]);
    }

    public function test_produk_disembunyikan_tidak_tampil_di_kasir(): void
    {
        $this->produk('AM-40-L');
        $this->produk('AM-40-PK', false);

        $res = $this->actingAs($this->staf())->getJson(route('pos.kasir.search', ['q' => 'Frame']))->assertOk();

        $this->assertSame(['AM-40-L'], $this->sku($res));
    }

    public function test_mode_atur_menampilkan_semua_hanya_untuk_yang_berizin(): void
    {
        $this->produk('AM-40-L');
        $this->produk('AM-40-PK', false);

        // Staf tanpa izin: ?semua=1 diabaikan.
        $res = $this->actingAs($this->staf())->getJson(route('pos.kasir.search', ['q' => 'Frame', 'semua' => 1]));
        $this->assertSame(['AM-40-L'], $this->sku($res));

        $res = $this->actingAs($this->staf(['pos.kasir-atur-produk']))
            ->getJson(route('pos.kasir.search', ['q' => 'Frame', 'semua' => 1]));
        $this->assertSame(['AM-40-L', 'AM-40-PK'], $this->sku($res));
        $this->assertFalse($res->json('1.tampil'));
    }

    public function test_sembunyikan_produk_butuh_izin(): void
    {
        $p = $this->produk('AM-40-PK');

        $this->actingAs($this->staf())
            ->postJson(route('pos.atur-produk-kasir', $p), ['tampil' => false])->assertForbidden();
        $this->assertTrue($p->fresh()->tampil_di_kasir);

        $this->actingAs($this->staf(['pos.kasir-atur-produk']))
            ->postJson(route('pos.atur-produk-kasir', $p), ['tampil' => false])->assertOk();
        $this->assertFalse($p->fresh()->tampil_di_kasir);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)
            ->postJson(route('pos.atur-produk-kasir', $p), ['tampil' => true])->assertOk();
        $this->assertTrue($p->fresh()->tampil_di_kasir);
    }

    public function test_foto_dari_varian_toko_lalu_sampul_produk(): void
    {
        $varianBerfoto = $this->produk('AM-40-L');
        $varianPolos   = $this->produk('AM-40-PK');
        $tanpaToko     = $this->produk('CS-01');

        $toko   = StoreProduct::create(['name' => 'Frame Mahar', 'slug' => 'frame-mahar']);
        $sampul = StoreProductMedia::create(['store_product_id' => $toko->id, 'group' => 'gallery', 'kind' => 'image',
            'url' => 'https://cdn.test/sampul.jpg', 'is_primary' => true, 'sort_order' => 1]);
        $khusus = StoreProductMedia::create(['store_product_id' => $toko->id, 'group' => 'gallery', 'kind' => 'image',
            'url' => 'https://cdn.test/landscape.jpg', 'is_primary' => false, 'sort_order' => 2]);

        StoreProductVariant::create(['store_product_id' => $toko->id, 'product_id' => $varianBerfoto->id, 'image_media_id' => $khusus->id]);
        StoreProductVariant::create(['store_product_id' => $toko->id, 'product_id' => $varianPolos->id]);

        $foto = collect($this->actingAs($this->staf())->getJson(route('pos.kasir.search', ['q' => '']))->json())
            ->pluck('foto', 'sku');

        $this->assertSame('https://cdn.test/landscape.jpg', $foto['AM-40-L']);
        $this->assertSame($sampul->url, $foto['AM-40-PK']);
        $this->assertNull($foto['CS-01']);
    }
}
