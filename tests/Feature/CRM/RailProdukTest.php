<?php

namespace Tests\Feature\CRM;

use App\Core\Inventory\Product;
use App\Core\Inventory\ProductLink;
use App\Core\Inventory\ProductStock;
use App\Core\Inventory\StockReservation;
use App\Core\Inventory\Warehouse;
use App\Models\ProductPrice;
use App\Models\StoreProduct;
use App\Models\StoreProductVariant;
use App\Models\User;
use App\Modules\CRM\Models\CrmMarketplaceLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel Produk di rail kanan.
 *
 * Dasarnya SKU gudang (7 Sep 2026) — pertanyaan yang datang di chat hampir
 * selalu "stoknya ada?" dan "harganya berapa?", dan dua angka itu hidup di SKU,
 * bukan di halaman etalase.
 *
 * Sejak 9 Sep 2026 panelnya punya TIGA sub-tab (Web / Market Place / Custom),
 * dan pemisahan itu ikut dijaga di sini: sub-tab Web hanya boleh memuat barang
 * yang halamannya TERBIT (tombol utamanya mengirim tautan), sub-tab Custom
 * hanya barang buatan, dan daftar marketplace berdiri sendiri tanpa SKU.
 *
 * Yang dijaga di sini hal-hal yang tak bisa ditarik kembali setelah pesan
 * terkirim: (1) tautan web hanya boleh menunjuk halaman yang benar-benar
 * TERBIT, (2) angka stoknya harus sama persis dengan yang dilihat pembeli di
 * web, dan (3) harganya harga SETELAH promo — admin tak boleh menjanjikan
 * barang yang di web sudah habis, atau menyebut angka yang beda dengan
 * etalase.
 */
class RailProdukTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function sku(string $sku, string $nama, array $lain = []): Product
    {
        return Product::create(array_merge([
            'sku'         => $sku,
            'name'        => $nama,
            'is_active'   => true,
            'is_sellable' => true,
        ], $lain));
    }

    /** Tempelkan SKU ke sebuah halaman etalase sebagai varian. */
    private function etalase(Product $p, string $nama, string $slug, string $status): StoreProduct
    {
        $store = StoreProduct::create(['name' => $nama, 'slug' => $slug, 'status' => $status]);

        StoreProductVariant::create([
            'store_product_id' => $store->id,
            'product_id'       => $p->id,
            'variant_label'    => 'std',
        ]);

        return $store;
    }

    private function stok(Product $p, float $qty): void
    {
        $gudang = Warehouse::create(['name' => 'Gudang Jual', 'is_active' => true, 'is_sellable' => true]);

        ProductStock::create([
            'product_id'   => $p->id,
            'warehouse_id' => $gudang->id,
            'qty_on_hand'  => $qty,
        ]);
    }

    public function test_pencarian_menemukan_lewat_nama_maupun_sku(): void
    {
        $a = $this->sku('BMA-40', 'Box Mahar 40');
        $this->etalase($a, 'Box Mahar 40', 'box-mahar-40', 'published');

        $b = $this->sku('PLK-A4', 'Plakat Wisuda A4');
        $this->etalase($b, 'Plakat Wisuda A4', 'plakat-wisuda-a4', 'published');

        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('crm.produk.cari', ['q' => 'mahar']))
            ->assertOk()->assertJsonCount(1, 'grup')
            ->assertJsonPath('grup.0.varian.0.sku', 'BMA-40');

        // Cocok lewat SKU varian, walau nama halamannya tidak mengandung 'PLK'.
        $this->actingAs($admin)->getJson(route('crm.produk.cari', ['q' => 'PLK']))
            ->assertOk()->assertJsonCount(1, 'grup')
            ->assertJsonPath('grup.0.nama', 'Plakat Wisuda A4');
    }

    /**
     * Beberapa SKU di bawah SATU halaman etalase datang sebagai satu kelompok,
     * bukan tiga kartu berdiri sendiri.
     *
     * Yang ditanya pembeli justru perbandingannya — mana yang lebih murah, mana
     * yang stoknya ada. Sebagai kartu terpisah, perbandingan itu hilang dan
     * ketiganya tampak seperti barang yang tak berhubungan.
     */
    public function test_varian_satu_halaman_dikelompokkan_jadi_satu(): void
    {
        $a = $this->sku('KS-K1-M3', 'Kotak Saran 1 Kotak');
        $b = $this->sku('KS-K1-M3-Inst', 'Kotak Saran 1 Kotak Instant');

        $store = $this->etalase($a, 'Kotak Saran 1 Kotak', 'kotak-saran-1', 'published');
        StoreProductVariant::create([
            'store_product_id' => $store->id,
            'product_id'       => $b->id,
            'variant_label'    => 'Instant',
        ]);

        $r = $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'KS-K1']))
            ->assertOk()
            ->assertJsonCount(1, 'grup')
            ->assertJsonCount(2, 'grup.0.varian');

        $this->assertSame(['KS-K1-M3', 'KS-K1-M3-Inst'], array_column($r->json('grup.0.varian'), 'sku'));
    }

    /**
     * Varian saudara ikut terbawa walau tidak cocok kata pencarian.
     *
     * Kelompok yang cuma memuat sebagian variannya menyesatkan: admin
     * menyimpulkan "cuma ada satu pilihan" padahal ada dua, dan pembeli
     * kehilangan pilihan yang sebenarnya tersedia.
     */
    public function test_varian_saudara_ikut_walau_tak_cocok_pencarian(): void
    {
        $a = $this->sku('KS-K1-M3', 'Kotak Saran 1 Kotak');
        $b = $this->sku('ZZZ-9', 'Varian Lain');

        $store = $this->etalase($a, 'Kotak Saran 1 Kotak', 'kotak-saran-1', 'published');
        StoreProductVariant::create([
            'store_product_id' => $store->id,
            'product_id'       => $b->id,
            'variant_label'    => 'Lain',
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'KS-K1-M3']))
            ->assertOk()
            ->assertJsonCount(2, 'grup.0.varian');
    }

    public function test_produk_yang_tidak_dijual_tidak_ditawarkan(): void
    {
        $mati = $this->sku('INT-01', 'Bahan Internal', ['is_sellable' => false]);
        $this->etalase($mati, 'Bahan Internal', 'bahan-internal', 'published');

        $nonaktif = $this->sku('MTI-01', 'Produk Mati', ['is_active' => false]);
        $this->etalase($nonaktif, 'Produk Mati', 'produk-mati', 'published');

        /*
         * Halamannya terbit, tapi SEMUA variannya mati/tidak dijual → kelompok
         * tanpa isi. Ia dibuang seluruhnya, bukan ditampilkan sebagai kartu
         * kosong yang tak bisa diapa-apakan.
         */
        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari'))
            ->assertOk()
            ->assertJsonCount(0, 'grup');
    }

    public function test_tautan_web_hanya_untuk_halaman_terbit(): void
    {
        config(['crm.storefront_url' => 'https://noudakrilik.com']);

        $terbit = $this->sku('PLK-A4', 'Plakat Wisuda A4');
        $this->etalase($terbit, 'Plakat Wisuda', 'plakat-wisuda', 'published');

        $draf = $this->sku('RHS-01', 'Produk Rahasia');
        $this->etalase($draf, 'Produk Rahasia', 'produk-rahasia', 'draft');

        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('crm.produk.cari', ['q' => 'PLK-A4']))
            ->assertOk()
            ->assertJsonPath('grup.0.url', 'https://noudakrilik.com/produk/plakat-wisuda');

        /*
         * Halaman draf TIDAK boleh muncul di sub-tab Web sama sekali: tombol
         * utamanya mengirim tautan, dan baris tanpa tautan di situ cuma jadi
         * jebakan yang berujung pelanggan membuka 404.
         */
        $this->actingAs($admin)->getJson(route('crm.produk.cari', ['q' => 'RHS-01']))
            ->assertOk()
            ->assertJsonCount(0, 'grup');
    }

    /** Sub-tab Custom hanya memuat barang buatan; yang ready tidak ikut. */
    public function test_sub_tab_custom_hanya_barang_buatan(): void
    {
        $ready = $this->sku('BMA-40', 'Box Mahar 40');
        $this->etalase($ready, 'Box Mahar 40', 'box-mahar-40', 'published');

        $this->sku('CS2', 'Custom Sedang', ['sale_type' => 'preorder', 'made_to_order' => true]);

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['mode' => 'custom']))
            ->assertOk()
            ->assertJsonCount(1, 'produk')
            ->assertJsonPath('produk.0.sku', 'CS2');
    }

    /** Angka stok panel = rumus etalase: on_hand gudang jual − reservasi aktif. */
    public function test_stok_yang_ditampilkan_dikurangi_reservasi(): void
    {
        $p = $this->sku('BMA-40', 'Box Mahar 40');
        $this->etalase($p, 'Box Mahar 40', 'box-mahar-40', 'published');
        $this->stok($p, 10);

        StockReservation::create([
            'product_id'   => $p->id,
            'warehouse_id' => Warehouse::first()->id,
            'qty'            => 3,
            'status'         => 'active',
            'sales_order_id' => 0,   // reservasi lepas; kolomnya wajib terisi
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('crm.produk.cari', ['q' => 'BMA-40']))
            ->assertOk()
            ->assertJsonPath('grup.0.varian.0.stok', 7);
    }

    public function test_tautan_luar_bisa_ditambah_diubah_dan_dihapus(): void
    {
        $p = $this->sku('CS2', 'Custom Sedang', ['sale_type' => 'preorder', 'made_to_order' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('crm.produk.tautan.simpan', $p), [
                'judul' => 'Shopee CS2',
                'url'   => 'https://shopee.co.id/product/1/2',
            ])
            ->assertOk()
            ->assertJsonPath('tautan.judul', 'Shopee CS2');

        $this->actingAs($admin)->getJson(route('crm.produk.cari', ['mode' => 'custom', 'q' => 'CS2']))
            ->assertOk()
            ->assertJsonPath('produk.0.tautan.0.url', 'https://shopee.co.id/product/1/2');

        $tautan = ProductLink::firstOrFail();

        // Salah ketik alamat lapak baru ketahuan setelah pembeli mengeluh —
        // harus bisa diperbaiki, bukan cuma dihapus lalu diketik ulang.
        $this->actingAs($admin)
            ->postJson(route('crm.produk.tautan.ubah', $tautan), [
                'judul' => 'Shopee',
                'url'   => 'https://shopee.co.id/product/9/9',
            ])
            ->assertOk()
            ->assertJsonPath('tautan.url', 'https://shopee.co.id/product/9/9');

        $this->actingAs($admin)
            ->deleteJson(route('crm.produk.tautan.hapus', $tautan))
            ->assertOk();

        $this->assertSame(0, ProductLink::count());
    }

    public function test_alamat_tautan_wajib_masuk_akal(): void
    {
        $p = $this->sku('CS2', 'Custom Sedang');

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.tautan.simpan', $p), ['judul' => 'Shopee', 'url' => 'shopee'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------ daftar marketplace bebas */

    public function test_tautan_marketplace_berdiri_sendiri_tanpa_sku(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('crm.pasar.store'), [
                'nama' => 'Paket Hemat Box Mahar (Shopee)',
                'url'  => 'https://shopee.co.id/product/5/6',
            ])
            ->assertOk()
            ->assertJsonPath('tautan.nama', 'Paket Hemat Box Mahar (Shopee)');

        $this->actingAs($admin)->getJson(route('crm.pasar.index'))
            ->assertOk()
            ->assertJsonCount(1, 'tautan');

        $baris = CrmMarketplaceLink::firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('crm.pasar.update', $baris), [
                'nama' => 'Paket Hemat Box Mahar',
                'url'  => 'https://shopee.co.id/product/7/8',
            ])
            ->assertOk()
            ->assertJsonPath('tautan.url', 'https://shopee.co.id/product/7/8');

        $this->actingAs($admin)
            ->deleteJson(route('crm.pasar.destroy', $baris))
            ->assertOk();

        $this->assertSame(0, CrmMarketplaceLink::count());
    }

    public function test_pencarian_marketplace_menyaring_lewat_nama(): void
    {
        $admin = $this->admin();

        CrmMarketplaceLink::create(['nama' => 'Box Mahar Shopee', 'url' => 'https://shopee.co.id/a']);
        CrmMarketplaceLink::create(['nama' => 'Plakat Tokopedia', 'url' => 'https://tokopedia.com/b']);

        $this->actingAs($admin)->getJson(route('crm.pasar.index', ['q' => 'plakat']))
            ->assertOk()
            ->assertJsonCount(1, 'tautan')
            ->assertJsonPath('tautan.0.nama', 'Plakat Tokopedia');
    }

    /* ------------------------------------------------------- harga & berat custom */

    /**
     * Harga produk berstok hidup di baris product_prices, bukan di kolom
     * produk. Kalau tersimpan ke tempat yang salah, display_price tidak pernah
     * berubah dan admin mengira harganya sudah diperbarui padahal belum.
     */
    public function test_harga_dan_berat_custom_tersimpan_di_tempat_yang_dibaca(): void
    {
        $p = $this->sku('CS2', 'Custom Sedang', ['sale_type' => 'preorder', 'made_to_order' => true, 'unit' => 'pcs']);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.harga', $p), ['harga' => 275000, 'berat' => 1400])
            ->assertOk()
            ->assertJsonPath('harga', 275000)
            ->assertJsonPath('berat', 1400);

        $this->assertSame(1, ProductPrice::where('product_id', $p->id)->count());
        $this->assertEquals(275000, $p->fresh()->display_price);
        $this->assertSame(1400, $p->fresh()->weight_gram);

        // Menyimpan lagi MENIMPA baris yang sama, tidak menumpuk baris baru —
        // display_price membaca baris pertama, jadi baris kedua akan diam-diam
        // tidak berlaku.
        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.harga', $p), ['harga' => 300000])
            ->assertOk();

        $this->assertSame(1, ProductPrice::where('product_id', $p->id)->count());
        $this->assertEquals(300000, $p->fresh()->display_price);
        // Berat yang tidak dikirim tidak boleh ikut terhapus.
        $this->assertSame(1400, $p->fresh()->weight_gram);
    }

    public function test_harga_wajib_angka_tak_negatif(): void
    {
        $p = $this->sku('CS2', 'Custom Sedang', ['sale_type' => 'preorder', 'made_to_order' => true]);

        $this->actingAs($this->admin())
            ->postJson(route('crm.produk.harga', $p), ['harga' => -1])
            ->assertStatus(422);
    }
}
