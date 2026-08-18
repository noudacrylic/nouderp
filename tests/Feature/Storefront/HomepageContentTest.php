<?php

namespace Tests\Feature\Storefront;

use App\Models\StoreHomepageSetting;
use App\Models\StoreProduct;
use App\Models\StoreProductMedia;
use App\Models\StorefrontSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isi beranda etalase yang dikelola dari ERP (Store → Beranda).
 *
 * Yang dijaga di sini bukan tata letaknya, melainkan tiga hal yang kerusakannya
 * tidak terlihat dari halaman admin: beranda tidak pernah kehilangan H1-nya,
 * baris yang dihapus tidak meninggalkan lubang di etalase, dan janji "edit tanpa
 * coding" itu benar — teks yang disimpan di ERP memang yang keluar di API.
 */
class HomepageContentTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);
    }

    private function api()
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->key)
            ->getJson('/api/storefront/homepage');
    }

    private function admin(): User
    {
        // `is_active` wajib disetel eksplisit: nilai bawaannya ada di level kolom, jadi
        // model yang baru dibuat masih membacanya null — dan EnsureMenuAccess menendang
        // akun non-aktif ke halaman login.
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_baris_pertama_dibuat_sudah_terisi_teks_bawaan(): void
    {
        $this->assertSame(0, StoreHomepageSetting::count());

        $res = $this->api()->assertOk();

        // Halaman depan tanpa H1 adalah kerusakan senyap; teks bawaan harus sudah ada
        // sejak baris pertama dibuat, bukan menunggu seseorang mengisi 40 kotak.
        $this->assertSame(
            'Produsen Akrilik Semarang untuk Kantor, Usaha & Instansi',
            $res->json('data.hero.heading')
        );
        $this->assertCount(5, $res->json('data.advantages.items'));
        $this->assertCount(6, $res->json('data.segments.items'));
        $this->assertCount(4, $res->json('data.hero.badges'));
        $this->assertCount(6, $res->json('data.trust.items'));
        $this->assertCount(7, $res->json('data.faq.items'));
    }

    public function test_kata_kerajinan_tidak_dipakai_di_teks_bawaan(): void
    {
        // "Kerajinan" menempatkan Noud di rak suvenir, sedangkan pembeli terbesarnya
        // — kantor, sekolah, puskesmas — mengetik "produsen"/"supplier".
        $json = json_encode(StoreHomepageSetting::defaults(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('kerajinan', $json);
        $this->assertStringNotContainsStringIgnoringCase('gratis ongkir', $json);
        $this->assertStringNotContainsStringIgnoringCase('grosir', $json);

        // Semua branding logo memakai STIKER cetak. Menjanjikan grafir berarti
        // menjanjikan barang yang tidak dikerjakan; menjelekkan stiker berarti
        // menyerang metode sendiri. Keduanya sama-sama tak boleh ada.
        $this->assertStringNotContainsStringIgnoringCase('grafir', $json);
        $this->assertStringNotContainsStringIgnoringCase('engraving', $json);
        $this->assertStringNotContainsStringIgnoringCase('dicetak langsung', $json);
        $this->assertStringNotContainsStringIgnoringCase('bukan stiker', $json);
    }

    public function test_h1_dan_meta_jatuh_ke_bawaan_saat_dikosongkan(): void
    {
        StoreHomepageSetting::singleton()->update([
            'hero_heading' => '',
            'meta_title'   => null,
        ]);

        $res = $this->api()->assertOk();

        $this->assertNotSame('', $res->json('data.hero.heading'));
        $this->assertNotNull($res->json('data.meta.title'));
    }

    public function test_admin_menyimpan_teks_dan_etalase_langsung_membacanya(): void
    {
        $this->actingAs($this->admin())
            ->put('/erp/store/homepage', [
                'hero_heading'    => 'Judul Baru dari ERP',
                'hero_subheading' => 'Kalimat penjelas baru.',
                'featured_limit'  => 6,
                'show_faq'        => '1',
                'faqs'            => [['q' => 'Tanya?', 'a' => 'Jawab.']],
            ])
            ->assertRedirect();

        $res = $this->api()->assertOk();

        $this->assertSame('Judul Baru dari ERP', $res->json('data.hero.heading'));
        $this->assertSame(6, $res->json('data.featured.limit'));
        $this->assertSame([['q' => 'Tanya?', 'a' => 'Jawab.']], $res->json('data.faq.items'));
    }

    public function test_saklar_yang_tidak_dicentang_mematikan_bagiannya(): void
    {
        // Checkbox yang tak dicentang tidak dikirim browser sama sekali — kalau
        // dibaca dari payload begitu saja, bagian yang dimatikan akan tetap tampil.
        $this->actingAs($this->admin())
            ->put('/erp/store/homepage', ['hero_heading' => 'Tetap ada'])
            ->assertRedirect();

        $res = $this->api()->assertOk();

        $this->assertFalse($res->json('data.faq.show'));
        $this->assertFalse($res->json('data.segments.show'));
        $this->assertFalse($res->json('data.workshop.show'));
        $this->assertFalse($res->json('data.advantages.show'));
        $this->assertFalse($res->json('data.spotlight.show'));
        $this->assertFalse($res->json('data.gallery.show'));
        $this->assertFalse($res->json('data.trust.show'));
    }

    public function test_baris_kosong_dibuang_dan_indeksnya_disusun_ulang(): void
    {
        $this->actingAs($this->admin())
            ->put('/erp/store/homepage', [
                'advantages' => [
                    ['icon' => '🏭', 'title' => 'Satu', 'text' => 'Isi satu'],
                    ['icon' => '',   'title' => '',     'text' => ''],          // dikosongkan → dibuang
                    ['icon' => '📦', 'title' => 'Dua',  'text' => 'Isi dua'],
                ],
                'institution_bullets' => ['Poin A', '   ', 'Poin B'],
            ])
            ->assertRedirect();

        $data = $this->api()->assertOk()->json('data');

        // Indeks berlubang membuat JSON terbaca sebagai objek, bukan larik, dan
        // urutan kartu di etalase ikut kacau.
        $this->assertSame(['Satu', 'Dua'], array_column($data['advantages']['items'], 'title'));
        $this->assertSame([0, 1], array_keys($data['advantages']['items']));
        $this->assertSame(['Poin A', 'Poin B'], $data['institution']['bullets']);
    }

    public function test_reset_mengembalikan_teks_bawaan(): void
    {
        $homepage = StoreHomepageSetting::singleton();
        $homepage->update(['hero_heading' => 'Diubah dulu']);

        $this->actingAs($this->admin())
            ->post('/erp/store/homepage/reset')
            ->assertRedirect();

        $this->assertSame(
            StoreHomepageSetting::defaults()['hero_heading'],
            $homepage->fresh()->hero_heading
        );
    }

    public function test_galeri_mengambil_foto_showcase_produk_maksimal_dua_per_produk(): void
    {
        // Galeri instansi sengaja tidak punya unggahan sendiri: fotonya menumpang
        // media Showcase produk supaya satu foto cukup diunggah sekali. Yang dijaga
        // di sini adalah pembatas dua-foto-per-produk — tanpa itu satu produk yang
        // fotonya banyak akan menghabiskan seluruh galeri.
        $produk = StoreProduct::create([
            'slug' => 'kotak-saran-akrilik', 'name' => 'Kotak Saran Akrilik', 'status' => 'published',
        ]);

        foreach (['Puskesmas Kota Semarang', 'Kantor Kecamatan', 'Bank Jateng'] as $i => $caption) {
            StoreProductMedia::create([
                'store_product_id' => $produk->id,
                'group'            => 'showcase',
                'kind'             => 'image',
                'source'           => 'r2',
                'url'              => "https://contoh.test/showcase-{$i}.jpg",
                'caption'          => $caption,
                'sort_order'       => $i,
            ]);
        }

        $items = $this->api()->assertOk()->json('data.gallery.items');

        $this->assertCount(2, $items);
        $this->assertSame(['Puskesmas Kota Semarang', 'Kantor Kecamatan'], array_column($items, 'caption'));
        // Tiap foto menautkan ke produknya — bukti sosial sekaligus jalur internal link.
        $this->assertSame('kotak-saran-akrilik', $items[0]['product_slug']);
    }

    public function test_galeri_tidak_dihitung_saat_bagiannya_dimatikan(): void
    {
        StoreHomepageSetting::singleton()->update(['show_gallery' => false]);

        $this->assertSame([], $this->api()->assertOk()->json('data.gallery.items'));
    }

    public function test_endpoint_beranda_tetap_butuh_kunci_api(): void
    {
        $this->getJson('/api/storefront/homepage')->assertUnauthorized();
    }
}
