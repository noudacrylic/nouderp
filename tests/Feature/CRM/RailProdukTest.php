<?php

namespace Tests\Feature\CRM;

use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rail Produk: mencari produk etalase untuk dikirim tautannya ke chat.
 *
 * Yang dijaga di sini satu hal yang tak bisa ditarik kembali: tautan yang
 * dikirim harus menuju halaman yang benar-benar TERBIT. Mengirim tautan produk
 * draf berarti pelanggan membuka 404, dan pesannya sudah telanjur sampai.
 */
class RailProdukTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function produk(string $nama, string $slug, string $status): StoreProduct
    {
        return StoreProduct::create([
            'name'   => $nama,
            'slug'   => $slug,
            'status' => $status,
        ]);
    }

    public function test_hanya_produk_terbit_yang_ditawarkan(): void
    {
        $this->produk('Box Mahar Akrilik', 'box-mahar-akrilik', 'published');
        $this->produk('Produk Rahasia', 'produk-rahasia', 'draft');

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari'))
            ->assertOk()
            ->assertJsonPath('produk.0.nama', 'Box Mahar Akrilik')
            ->assertJsonCount(1, 'produk');
    }

    public function test_tautan_dirangkai_dari_alamat_etalase(): void
    {
        config(['crm.storefront_url' => 'https://noudakrilik.com']);
        $this->produk('Plakat Akrilik', 'plakat-akrilik', 'published');

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'plakat']))
            ->assertOk()
            ->assertJsonPath('produk.0.url', 'https://noudakrilik.com/produk/plakat-akrilik');
    }

    public function test_pencarian_menyaring_berdasarkan_nama(): void
    {
        $this->produk('Box Mahar', 'box-mahar', 'published');
        $this->produk('Plakat Wisuda', 'plakat-wisuda', 'published');

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'mahar']))
            ->assertOk()
            ->assertJsonCount(1, 'produk')
            ->assertJsonPath('produk.0.nama', 'Box Mahar');
    }

    /**
     * Dicari lewat SKU juga: admin yang sedang membalas biasanya sudah memegang
     * SKU dari percakapan, bukan nama panjang produknya.
     */
    public function test_pencarian_juga_menemukan_lewat_sku(): void
    {
        $store = $this->produk('Box Mahar Akrilik Sliding', 'box-mahar-sliding', 'published');

        $produk = \App\Core\Inventory\Product::create([
            'sku'  => 'BMA-40X30X6',
            'name' => 'Box Mahar 40x30x6',
        ]);

        \App\Models\StoreProductVariant::create([
            'store_product_id' => $store->id,
            'product_id'       => $produk->id,
            'variant_label'    => '40x30x6',
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'BMA-40X30']))
            ->assertOk()
            ->assertJsonCount(1, 'produk')
            ->assertJsonPath('produk.0.sku', 'BMA-40X30X6');
    }
}
