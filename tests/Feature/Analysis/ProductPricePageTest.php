<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Models\MarketplaceConfig;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Models\JubelioStorePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Analisa ▸ Harga Produk — halaman & jalur simpannya.
 *
 * Hitungannya sendiri sudah diuji telanjang di PricingMathTest. Yang dijaga di sini adalah
 * hal-hal yang tidak kelihatan dari rumus: kanal Website menulis ke master harga produk
 * (satu harga untuk web + ERP, bukan salinan), kanal marketplace menyimpan harganya sendiri
 * dan belum berlaku sebelum dikirim, serta sub-tab Afiliasi hanya memuat kanal yang memang
 * menyediakan program afiliasi.
 */
class ProductPricePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_harga_menampilkan_produk_beserta_potongan_kanalnya(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()
            ->assertSee('Frame Mahar Akrilik')
            ->assertSee('Harga Produk')
            // Rincian potongan tampil terurai, bukan satu angka gabungan.
            ->assertSee('Potongan marketplace')
            ->assertSee('Premi pengembalian')
            // Dua ukuran keuntungan berdampingan, sama seperti halaman HPP.
            ->assertSee('dari harga')
            ->assertSee('dari HPP')
            // Kanal lain tetap terjangkau dari deretan pill.
            ->assertSee('Lazada');
    }

    public function test_harga_website_ditulis_ke_master_harga_produk(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        DB::table('product_prices')->insert([
            'product_id' => $id, 'unit_name' => 'pcs', 'channel' => 'default',
            'price' => 200_000, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.save', $id), ['kanal' => 'website', 'price' => '235.000'])
            ->assertRedirect(route('analisa.harga.index'));

        // Satu baris harga saja — bukan baris kedua dengan satuan berbeda.
        $this->assertSame(1, DB::table('product_prices')->where('product_id', $id)->count());
        $this->assertEquals(235_000, DB::table('product_prices')->where('product_id', $id)->value('price'));
        // base_price ikut supaya halaman HPP tidak menampilkan harga basi.
        $this->assertEquals(235_000, DB::table('products')->where('id', $id)->value('base_price'));
        // Tidak menyisakan harga kanal — website memang tidak punya salinan.
        $this->assertDatabaseCount('product_channel_prices', 0);
    }

    public function test_harga_marketplace_disimpan_terpisah_dan_belum_berlaku_sebelum_dikirim(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.save', $id), ['kanal' => 'shopee', 'price' => '265.000'])
            ->assertRedirect(route('analisa.harga.index', ['kanal' => 'shopee']));

        $row = ProductChannelPrice::where('product_id', $id)->where('channel', 'shopee')->firstOrFail();
        $this->assertEquals(265_000, $row->price);
        $this->assertNull($row->pushed_at);
        $this->assertFalse($row->isPushed());

        // Harga jual asli produk tidak ikut berubah.
        $this->assertEquals(200_000, DB::table('products')->where('id', $id)->value('base_price'));
    }

    public function test_kirim_ditolak_selama_produk_masih_ikut_harga_dasar(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertRedirect(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertSessionHas('error');
    }

    public function test_sub_tab_afiliasi_hanya_memuat_kanal_yang_menyediakannya(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.afiliasi'))
            ->assertOk()
            ->assertSee('Shopee')
            ->assertSee('TikTok/Tokopedia')
            ->assertDontSee('Lazada')
            ->assertDontSee('Website');
    }

    public function test_grosir_menyimpan_harga_dan_minimum_belinya(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.grosir', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.grosir.save', $id), [
                'kanal' => 'shopee', 'wholesale_price' => '175.000', 'wholesale_min_qty' => 5,
            ])->assertSessionHas('success');

        $row = ProductChannelPrice::where('product_id', $id)->where('channel', 'shopee')->firstOrFail();
        $this->assertEquals(175_000, $row->wholesale_price);
        $this->assertSame(5, $row->wholesale_min_qty);
        // Harga satuan tidak ikut terisi hanya karena grosirnya diatur.
        $this->assertNull($row->price);
    }

    public function test_penyusun_potongan_bisa_ditambah_dan_dihapus(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('analisa.harga.component.save'), [
            'channel' => 'shopee', 'label' => 'Biaya iklan', 'percent' => 3, 'fixed' => '0',
        ])->assertSessionHas('success');

        $comp = PriceChannelFeeComponent::where('label', 'Biaya iklan')->firstOrFail();
        $this->assertEquals(3.0, $comp->percent);
        // Tanpa dicentang, penyusun baru tidak ikut dibandingkan ke akuntansi.
        $this->assertFalse($comp->include_accounting);

        $this->actingAs($admin)->delete(route('analisa.harga.component.destroy', $comp->id))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('price_channel_fee_components', ['id' => $comp->id]);
    }

    /**
     * Potongan andaian harus BERTAHAN, karena hilangnya tidak kelihatan.
     *
     * Dulu andaian cuma menempel di query string, dan form cari/urut tidak ikut membawanya:
     * sekali seseorang mengetik nama produk, tabelnya diam-diam kembali ke potongan asli —
     * persis saat orangnya sedang membandingkan harga.
     */
    public function test_potongan_andaian_bertahan_saat_mencari_dan_mengurutkan(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '25', 'fee_rp' => '5.000']))
            ->assertOk()->assertSee('Total potongan (andaian)');

        // Permintaan berikutnya TANPA fee_pct — persis yang dikirim form pencarian.
        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'search' => 'Frame']))
            ->assertOk()
            ->assertSee('Total potongan (andaian)')
            ->assertSee('aslinya 14% + Rp1.850');
    }

    public function test_andaian_tidak_menular_ke_kanal_lain(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '25']))->assertOk();

        // Lazada tidak pernah diandaikan apa-apa; potongannya harus tetap apa adanya.
        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'lazada']))
            ->assertOk()
            ->assertDontSee('Total potongan (andaian)');
    }

    public function test_andaian_bisa_dikembalikan_ke_potongan_asli(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '25']))->assertOk();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_reset' => 1]))
            ->assertOk()->assertDontSee('Total potongan (andaian)');

        // Dan tidak bangkit lagi pada kunjungan berikutnya.
        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertDontSee('Total potongan (andaian)');
    }

    /** Mengosongkan kedua kolom lalu menerapkan = memakai potongan sebenarnya lagi. */
    public function test_mengosongkan_kolom_andaian_menghapus_andaiannya(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '25']))->assertOk();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '', 'fee_rp' => '']))
            ->assertOk()->assertDontSee('Total potongan (andaian)');
    }

    /**
     * Mengetik angka yang sama persis dengan potongan asli bukan andaian — dan tidak boleh
     * tersimpan diam-diam, kalau tidak ia akan bangun jadi "andaian" pada hari potongan
     * aslinya diubah, tanpa seorang pun memintanya.
     */
    public function test_mengetik_angka_yang_sama_dengan_aslinya_bukan_andaian(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '14', 'fee_rp' => '1.850']))
            ->assertOk()->assertDontSee('Total potongan (andaian)');

        PriceChannelFeeComponent::where('channel', 'shopee')
            ->where('label', 'Potongan marketplace')->update(['percent' => 18]);

        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertDontSee('Total potongan (andaian)');
    }

    /**
     * Harga yang dipegang Jubelio tampil berdampingan dengan harga hasil hitungan.
     *
     * Ini menutup lubang `pushed_at`: kolom itu hanya mencatat bahwa kita PERNAH mengirim,
     * dan buta terhadap harga yang diubah orang lain langsung di Jubelio atau seller center.
     */
    public function test_harga_marketplace_tampil_beserta_selisihnya(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        ProductChannelPrice::create([
            'product_id' => $id, 'channel' => 'shopee', 'price' => 250_000,
        ]);
        foreach ($this->petakanTokoShopee([71]) as $storeId) {
            JubelioStorePrice::create([
                'product_id' => $id, 'store_id' => $storeId,
                'price' => 265_000, 'fetched_at' => now(),
            ]);
        }

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()
            ->assertSee('Di<br>marketplace', false)
            ->assertSee('Rp265.000')
            // Selisih terhadap harga kita sendiri — itu yang sebenarnya dicari orang.
            ->assertSee('Rp15.000');
    }

    /**
     * Satu kanal bisa punya lebih dari satu toko (TikTok & Tokopedia). Kalau keduanya
     * berharga beda, meratakannya akan menyembunyikan justru temuan yang paling penting.
     */
    public function test_toko_yang_berbeda_harga_dilaporkan_bukan_dilebur(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        [$a, $b] = $this->petakanTokoShopee([71, 72]);

        JubelioStorePrice::create(['product_id' => $id, 'store_id' => $a, 'price' => 250_000, 'fetched_at' => now()]);
        JubelioStorePrice::create(['product_id' => $id, 'store_id' => $b, 'price' => 265_000, 'fetched_at' => now()]);

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertSee('beda antar-toko');

        // Dan tidak pernah dilebur jadi satu angka yang menyesatkan.
        $ringkas = JubelioStorePrice::ringkasUntuk([$a, $b])->get($id);
        $this->assertFalse($ringkas['seragam']);
        $this->assertNull($ringkas['price']);
    }

    /** Belum pernah ditarik bukan berarti nol — kolomnya harus kosong, bukan Rp0. */
    public function test_produk_yang_belum_pernah_ditarik_tidak_menampilkan_angka(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([71]);
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertDontSee('Rp0<');
    }

    /** Kanal Website tidak lewat Jubelio — menariknya tidak masuk akal. */
    public function test_tarik_harga_ditolak_untuk_kanal_website(): void
    {
        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.tarik'), ['kanal' => 'website'])
            ->assertSessionHas('error');
    }

    /**
     * "Terapkan ke marketplace" — satu tombol dari kanal Website untuk pekerjaan yang
     * selama ini diulang per kanal: ketik harga, simpan, kirim; pindah kanal, ulangi.
     *
     * Yang dijaga di sini: harga yang dipakai adalah yang DIKIRIM FORMNYA (angka yang
     * sedang diketik), bukan yang tersimpan — kalau tidak, marketplace disamakan dengan
     * harga lama tanpa ada tanda apa pun.
     */
    public function test_terapkan_menyalin_harga_dasar_ke_semua_kanal_marketplace(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.terapkan', $id), ['price' => '235.000'])
            ->assertRedirect(route('analisa.harga.index'));

        // Harga dasarnya ikut tersimpan, persis seperti menekan ✓ di kolom harga.
        $this->assertEquals(235_000, DB::table('products')->where('id', $id)->value('base_price'));
        $this->assertEquals(235_000, DB::table('product_prices')->where('product_id', $id)->value('price'));

        // Semua kanal marketplace — bukan cuma yang kebetulan sudah punya baris harga.
        foreach (['shopee', 'tiktok_tokopedia', 'lazada'] as $kanal) {
            $row = ProductChannelPrice::where('product_id', $id)->where('channel', $kanal)->firstOrFail();
            $this->assertEquals(235_000, $row->price, "kanal {$kanal}");
            // Jubelio mati di lingkungan tes: harganya tersimpan, tapi belum berlaku di toko.
            $this->assertNull($row->pushed_at, "kanal {$kanal}");
        }
    }

    /** Jubelio mati bukan berarti "berhasil" — harganya tersimpan, tapi belum berlaku. */
    public function test_terapkan_mengaku_saat_jubelio_belum_tersambung(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.terapkan', $id), ['price' => '235.000'])
            ->assertSessionHas('warning', fn ($pesan) => str_contains($pesan, 'Jubelio belum tersambung'));

        $this->assertNull(session('success'));
    }

    /**
     * Harga khusus kanal yang sengaja dibuat berbeda memang ditimpa — itu maunya tombol
     * ini. Tapi angka lamanya harus ikut dilaporkan: sesudah ini ia tidak ada di mana pun.
     */
    public function test_terapkan_melaporkan_harga_khusus_yang_ditimpanya(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.terapkan', $id), ['price' => '235.000'])
            ->assertSessionHas('warning', fn ($pesan) => str_contains($pesan, 'Shopee (Rp250.000)'));

        $this->assertEquals(235_000, ProductChannelPrice::where('product_id', $id)
            ->where('channel', 'shopee')->value('price'));
    }

    /** Tanpa harga tidak ada yang bisa disalin — dan jangan sampai marketplace jadi Rp0. */
    public function test_terapkan_ditolak_kalau_harga_dasarnya_kosong(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 0);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index'))
            ->post(route('analisa.harga.terapkan', $id), ['price' => ''])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('product_channel_prices', 0);
    }

    /** Kanal yang harganya sudah menyimpang ditandai — supaya tombolnya tidak ditekan buta. */
    public function test_kanal_yang_berbeda_dari_harga_dasar_ditandai_di_halaman_website(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);

        $this->actingAs($this->admin())->get(route('analisa.harga.index'))
            ->assertOk()
            ->assertSee('Terapkan ke marketplace')
            ->assertSee('1 kanal beda harga');
    }

    /**
     * Petakan toko Jubelio ke kanal Shopee — tanpa ini `store_ids` kosong dan kolom
     * "Di marketplace" memang sengaja tidak muncul.
     *
     * @param  array<int,int> $storeIds
     * @return array<int,int>
     */
    private function petakanTokoShopee(array $storeIds): array
    {
        $customerId = DB::table('customers')->insertGetId([
            'code' => 'C-SHOPEE', 'name' => 'Shopee',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        MarketplaceConfig::create([
            'customer_id' => $customerId, 'admin_fee_percent' => 14,
            'admin_fee_fixed' => 1_850, 'is_active' => true,
        ]);

        foreach ($storeIds as $storeId) {
            JubelioChannelMap::create([
                'store' => (string) $storeId, 'customer_id' => $customerId, 'is_active' => true,
            ]);
        }

        return $storeIds;
    }

    private function potonganShopee(): void
    {
        PriceChannelFeeComponent::where('channel', 'shopee')->delete();

        foreach ([
            ['Potongan marketplace', 14, 0, true],
            ['Proses pesanan', 0, 1_250, true],
            ['Premi pengembalian', 0, 350, false],
            ['Biaya Jubelio per pesanan', 0, 250, false],
        ] as $i => [$label, $percent, $fixed, $akuntansi]) {
            PriceChannelFeeComponent::create([
                'channel' => 'shopee', 'label' => $label, 'percent' => $percent,
                'fixed' => $fixed, 'include_accounting' => $akuntansi, 'sort_order' => $i + 1,
            ]);
        }
    }

    private function produk(string $sku, string $nama, float $harga): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => $harga, 'sale_type' => 'ready',
            'is_sellable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }
}
