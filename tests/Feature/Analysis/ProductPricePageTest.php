<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Models\MarketplaceConfig;
use App\Models\User;
use App\Modules\Marketplace\Jubelio\Models\JubelioChannelMap;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
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

    /**
     * Kirim = kirim LALU tanya balik. "ok" dari Jubelio cuma berarti permintaannya diterima;
     * yang menjawab "harganya memang sudah berganti" hanya pertanyaan balik itu — dan
     * jawabannya sekalian mengisi kolom "Di marketplace" untuk produk ini.
     */
    public function test_kirim_mengecek_balik_harganya_ke_jubelio(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([71]);
        $id = $this->produkJubelio('AM-60', 'Frame Mahar Akrilik', 200_000, 55);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);

        $this->pakaiKlienPalsu([['store_id' => 71, 'price' => 250_000]]);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertSessionHas('success');

        $this->assertEquals(250_000,
            JubelioStorePrice::where('product_id', $id)->where('store_id', 71)->value('price'),
            'Hasil pengecekan harus ikut terekam — itu yang membuat kolomnya hidup.');
    }

    /**
     * Menulis daftar harga Jubelio saja tidak mengubah harga di lapak (14 Okt 2026): harga
     * berhenti di Jubelio sampai "Sync Harga ke Online" ditekan. Kirim harus memintanya
     * sekalian, untuk item-group produk itu.
     */
    public function test_kirim_meminta_jubelio_meneruskan_harga_ke_marketplace(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([71]);
        $id = $this->produkJubelio('AM-60', 'Frame Mahar Akrilik', 200_000, 55);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);

        $this->pakaiKlienPalsu([['store_id' => 71, 'price' => 250_000]]);
        $klien = app(JubelioClient::class);
        $klien::$sinkron = [];

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertSessionHas('success');

        $this->assertSame([[550]], $klien::$sinkron, 'item_group_id produk (55 × 10) harus diminta sinkron.');
    }

    /**
     * Pengecekan yang selalu setuju tidak ada gunanya. Kalau Jubelio ternyata masih
     * memegang harga lama, pengirimannya tidak boleh terbaca "beres" — walau API-nya
     * menjawab ok.
     */
    public function test_kirim_memperingatkan_saat_jubelio_masih_memegang_harga_lama(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([71]);
        $id = $this->produkJubelio('AM-60', 'Frame Mahar Akrilik', 200_000, 55);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);

        $this->pakaiKlienPalsu([['store_id' => 71, 'price' => 240_000]]);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertSessionHas('warning')
            ->assertSessionMissing('success');
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
     * Kanal yang belum punya penyusun TIDAK boleh dianggap potongan 0%.
     *
     * Itu yang terjadi di server: tabel penyusunnya kosong, halaman melaporkan "tidak ada
     * potongan", dan tiap harga yang disusun dari situ untungnya semu — padahal Shopee tetap
     * memotong 14% + Rp1.250 seperti yang tercatat di akuntansi. Selagi belum diurai jadi
     * penyusun, angka akuntansi itulah potongan yang berlaku.
     */
    public function test_kanal_tanpa_penyusun_memakai_potongan_akuntansi(): void
    {
        PriceChannelFeeComponent::where('channel', 'shopee')->delete();
        $customerId = DB::table('customers')->insertGetId([
            'code' => 'C-SHOPEE', 'name' => 'Shopee', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MarketplaceConfig::create([
            'customer_id' => $customerId, 'admin_fee_percent' => 14,
            'admin_fee_fixed' => 1_250, 'is_active' => true,
        ]);
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()
            ->assertSee('Belum ada penyusun')
            ->assertSee('14%')
            ->assertSee('Rp1.250')
            // Angkanya sama dengan akuntansi, jadi tidak ada yang "sudah basi".
            ->assertDontSee('Satu di antaranya sudah basi')
            ->assertDontSee('Tidak ada potongan');
    }

    /** Andaian tetap menang atas potongan akuntansi — itu memang gunanya. */
    public function test_andaian_masih_bisa_dipasang_di_kanal_tanpa_penyusun(): void
    {
        PriceChannelFeeComponent::where('channel', 'shopee')->delete();
        $customerId = DB::table('customers')->insertGetId([
            'code' => 'C-SHOPEE', 'name' => 'Shopee', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MarketplaceConfig::create([
            'customer_id' => $customerId, 'admin_fee_percent' => 14,
            'admin_fee_fixed' => 1_250, 'is_active' => true,
        ]);
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '20']))
            ->assertOk()
            ->assertSee('Total potongan (Andaian)')
            ->assertSee('dasarnya 14% + Rp1.250');
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
            ->assertOk()->assertSee('Total potongan (Andaian)');

        // Permintaan berikutnya TANPA fee_pct — persis yang dikirim form pencarian.
        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'search' => 'Frame']))
            ->assertOk()
            ->assertSee('Total potongan (Andaian)')
            ->assertSee('dasarnya 14% + Rp1.850');
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
            ->assertDontSee('Total potongan (Andaian)');
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
            ->assertOk()->assertDontSee('Total potongan (Andaian)');

        // Dan tidak bangkit lagi pada kunjungan berikutnya.
        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertDontSee('Total potongan (Andaian)');
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
            ->assertOk()->assertDontSee('Total potongan (Andaian)');
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
            ->assertOk()->assertDontSee('Total potongan (Andaian)');

        PriceChannelFeeComponent::where('channel', 'shopee')
            ->where('label', 'Potongan marketplace')->update(['percent' => 18]);

        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()->assertDontSee('Total potongan (Andaian)');
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
    // ───────────── Sumber potongan: dasar / real / andaian (13 Okt 2026) ─────────────

    /** Andaian TETAP tersimpan saat sumber dipindah ke Dasar, dan baru hilang lewat Lepas. */
    public function test_andaian_tersimpan_saat_pindah_sumber_dan_hilang_lewat_lepas(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_pct' => '25']))
            ->assertOk()->assertSee('Total potongan (Andaian)');

        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee', 'fee_mode' => 'dasar']))
            ->assertOk()
            ->assertSee('Total potongan (Dasar)')
            ->assertSee('name="fee_pct"' . "\n" . '                   value="25"', false)
            ->assertSee('>Lepas<', false);

        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee', 'fee_mode' => 'andaian']))
            ->assertOk()->assertSee('Total potongan (Andaian)');

        $this->actingAs($admin)->get(route('analisa.harga.index', ['kanal' => 'shopee', 'fee_reset' => 1]))
            ->assertOk()
            ->assertSee('Total potongan (Dasar)')
            ->assertDontSee('>Lepas<', false);
    }

    /** Biaya pre-order andaian hanya menambah potongan produk berjenis preorder. */
    public function test_biaya_preorder_hanya_untuk_produk_preorder(): void
    {
        $this->potonganShopee();
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $po = $this->produk('TO-8', 'Tempat Obat', 22_000);
        DB::table('products')->where('id', $po)->update(['sale_type' => 'preorder']);

        $this->actingAs($this->admin())->get(route('analisa.harga.index',
            ['kanal' => 'shopee', 'fee_form' => 1, 'fee_po' => '3']))
            ->assertOk()
            ->assertSee('khusus produk preorder')
            ->assertSee('data-percent="17"', false)   // preorder: 14% + 3%
            ->assertSee('data-percent="14"', false);  // ready: tetap 14%
    }

    /**
     * Sumber REAL dari pesanan selesai: penyusun yang nominalnya sama di semua pesanan jadi
     * biaya tetap, sisanya persen dari NILAI BARANG.
     */
    public function test_potongan_real_dihitung_dari_pesanan_selesai(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([]);
        $customerId = (int) DB::table('customers')->where('name', 'Shopee')->value('id');
        $produk = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $gudang = \App\Core\Inventory\Warehouse::firstOrCreate(['name' => 'Gudang Test'], ['is_sellable' => true]);

        // Nilai pesanan bervariasi (seperti aslinya): komisi 10% ikut berubah, biaya proses tidak.
        foreach (range(1, 12) as $i) {
            $nilai = 50_000 + $i * 10_000;
            $so = \App\Modules\Sales\Models\SalesOrder::create([
                'order_number' => 'SP-REAL-' . $i, 'customer_id' => $customerId,
                'warehouse_id' => $gudang->id, 'order_date' => now()->subDays($i)->toDateString(),
                'status' => 'confirmed', 'grand_total' => $nilai, 'paid_amount' => $nilai,
            ]);
            \App\Modules\Sales\Models\SalesOrderItem::create([
                'sales_order_id' => $so->id, 'product_id' => $produk, 'qty' => 1, 'conversion_to_base' => 1,
                'unit_price' => $nilai, 'net_unit_price' => $nilai, 'line_subtotal' => $nilai,
                'line_discount' => 0, 'line_total' => $nilai,
            ]);
            DB::table('marketplace_order_fees')->insert([
                'sales_order_id' => $so->id, 'kotor' => $nilai, 'admin' => $nilai / 10 + 1_250,
                'admin_rincian' => json_encode(['Komisi' => $nilai / 10, 'Biaya proses pesanan' => 1_250]),
                'saldo_masuk' => $nilai - $nilai / 10 - 1_250, 'final' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee', 'fee_mode' => 'real']))
            ->assertOk()
            ->assertSee('Total potongan (Real)')
            ->assertSee('10% + Rp1.250 · 12 pesanan')
            ->assertSee('Biaya proses pesanan Rp1.250/pesanan');
    }

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

    /**
     * Tahap 3 Shopee: kanal Shopee membaca harga langsung dari katalog API Shopee
     * (shopee_items), bukan rekaman Jubelio. Yang dibandingkan harga normal; promo
     * tertulis di bawahnya; dua listing berharga beda tidak dilebur.
     */
    public function test_kanal_shopee_membaca_harga_dari_katalog_shopee(): void
    {
        $this->potonganShopee();
        config(['services.shopee' => ['partner_id' => '1', 'partner_key' => 'k', 'env' => 'test']]);
        \App\Modules\Marketplace\Shopee\Models\ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        $frame  = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $plakat = $this->produk('PL-01', 'Plakat Akrilik', 90_000);
        $item = fn (int $item, int $model, int $pid, float $harga, float $asli, string $status = 'NORMAL') =>
            \App\Modules\Marketplace\Shopee\Models\ShopeeItem::create([
                'env' => 'test', 'shop_id' => 555, 'item_id' => $item, 'model_id' => $model, 'nama' => "Listing $item",
                'harga' => $harga, 'harga_asli' => $asli, 'status' => $status, 'product_id' => $pid, 'disinkron_at' => now(),
            ]);
        $item(11, 0, $frame, 239_000, 265_000);           // promo berjalan
        $item(21, 1, $plakat, 95_000, 95_000);
        $item(22, 0, $plakat, 99_000, 99_000);            // listing kedua, harga beda
        $item(23, 0, $plakat, 10_000, 10_000, 'UNLIST');  // tak dihitung

        // Rekaman Jubelio diabaikan untuk kanal Shopee.
        JubelioStorePrice::create(['product_id' => $frame, 'store_id' => 1, 'price' => 111_111, 'fetched_at' => now()]);

        $this->actingAs($this->admin())->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()
            ->assertSee('dibaca langsung dari Shopee')
            ->assertSee('Rp265.000')
            ->assertSee('promo Rp239.000')
            ->assertSee('beda antar-listing')
            ->assertDontSee('Rp111.111')
            ->assertDontSee('Rp10.000');
    }

    /** Toko Shopee + dua listing (satu ber-varian) yang terpeta ke satu produk ERP. */
    private function tokoShopeeDenganListing(int $pid): void
    {
        config(['services.shopee' => ['partner_id' => '1', 'partner_key' => 'k', 'env' => 'test']]);
        \App\Modules\Marketplace\Shopee\Models\ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        foreach ([[11, 0], [12, 91]] as [$item, $model]) {
            \App\Modules\Marketplace\Shopee\Models\ShopeeItem::create([
                'env' => 'test', 'shop_id' => 555, 'item_id' => $item, 'model_id' => $model, 'nama' => "Listing $item",
                'harga' => 200_000, 'harga_asli' => 200_000, 'status' => 'NORMAL', 'product_id' => $pid,
            ]);
        }
    }

    /** Shopee palsu: update_price dicatat; harga yang dibaca balik = $hargaTerbaca. */
    private function shopeePalsu(float $hargaTerbaca): \ArrayObject
    {
        $kiriman = new \ArrayObject();
        \Illuminate\Support\Facades\Http::fake(function ($req) use ($kiriman, $hargaTerbaca) {
            $p = ['current_price' => $hargaTerbaca, 'original_price' => $hargaTerbaca];
            if (str_contains($req->url(), 'update_price')) {
                $kiriman[] = $req->data();
                return \Illuminate\Support\Facades\Http::response(['response' => ['success_list' => [], 'failure_list' => []], 'error' => '']);
            }
            if (str_contains($req->url(), 'get_item_base_info')) {
                return \Illuminate\Support\Facades\Http::response(['response' => ['item_list' => [
                    ['item_id' => 11, 'has_model' => false, 'price_info' => [$p]],
                    ['item_id' => 12, 'has_model' => true],
                ]], 'error' => '']);
            }
            if (str_contains($req->url(), 'get_model_list')) {
                return \Illuminate\Support\Facades\Http::response(['response' => ['model' => [['model_id' => 91, 'price_info' => [$p]]]], 'error' => '']);
            }
            return \Illuminate\Support\Facades\Http::response(['error' => 'tak_dikenal', 'message' => $req->url()], 400);
        });

        return $kiriman;
    }

    /**
     * Tahap 3b: Kirim kanal Shopee mengubah harga normal LANGSUNG di Shopee untuk semua
     * listing/varian yang terpeta, dicek balik ke Shopee — dan Jubelio tetap ikut dikirimi
     * supaya tak menimpa balik.
     */
    public function test_kirim_kanal_shopee_langsung_ke_shopee_dan_jubelio_ikut(): void
    {
        $this->potonganShopee();
        $this->petakanTokoShopee([71]);
        $id = $this->produkJubelio('AM-60', 'Frame Mahar Akrilik', 200_000, 55);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);
        $this->tokoShopeeDenganListing($id);
        $kiriman = $this->shopeePalsu(250_000);
        $this->pakaiKlienPalsu([['store_id' => 71, 'price' => 250_000]]);
        $klien = app(JubelioClient::class);
        $klien::$sinkron = [];

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertSessionHas('success');

        $this->assertEquals([
            ['item_id' => 11, 'price_list' => [['model_id' => 0, 'original_price' => 250000]]],
            ['item_id' => 12, 'price_list' => [['model_id' => 91, 'original_price' => 250000]]],
        ], $kiriman->getArrayCopy());
        $this->assertEquals([250_000, 250_000],
            \App\Modules\Marketplace\Shopee\Models\ShopeeItem::orderBy('item_id')->pluck('harga_asli')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([[550]], $klien::$sinkron, 'Jubelio tetap ikut dikirimi harga yang sama.');
        $this->assertNotNull(ProductChannelPrice::where('product_id', $id)->value('pushed_at'));
    }

    public function test_kirim_kanal_shopee_memperingatkan_saat_shopee_masih_harga_lama(): void
    {
        $this->potonganShopee();
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        ProductChannelPrice::create(['product_id' => $id, 'channel' => 'shopee', 'price' => 250_000]);
        $this->tokoShopeeDenganListing($id);
        $this->shopeePalsu(200_000);

        $this->actingAs($this->admin())
            ->from(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->post(route('analisa.harga.push', $id), ['kanal' => 'shopee'])
            ->assertSessionHas('warning')
            ->assertSessionMissing('success');
        $this->assertNull(ProductChannelPrice::where('product_id', $id)->value('pushed_at'));
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

    /** Produk yang sudah ter-match ke item Jubelio — syarat tombol Kirim. */
    private function produkJubelio(string $sku, string $nama, float $harga, int $itemId): int
    {
        $id = $this->produk($sku, $nama, $harga);

        DB::table('products')->where('id', $id)->update([
            'sync_to_jubelio'       => 1,
            'jubelio_item_id'       => $itemId,
            'jubelio_item_group_id' => $itemId * 10,
        ]);

        return $id;
    }

    /**
     * Klien Jubelio palsu: menerima setiap kiriman harga, dan menjawab pertanyaan balik
     * dengan harga yang ditentukan tes. Sengaja tidak memanggil parent — tes ini tidak
     * boleh menyentuh pengaturan maupun jaringan.
     *
     * @param array<int,array{store_id:int, price:float}> $hargaToko
     */
    private function pakaiKlienPalsu(array $hargaToko): void
    {
        $this->app->bind(JubelioClient::class, fn () => new class($hargaToko) extends JubelioClient {
            public function __construct(private array $hargaToko) {}

            public function isReady(): bool
            {
                return true;
            }

            public function updatePrices(array $items): array
            {
                return ['success' => true, 'error' => null, 'data' => []];
            }

            /** @var array<int,array<int,int>> groupIds tiap permintaan sinkron ke marketplace */
            public static array $sinkron = [];

            public function syncPricesToChannels(array $groupIds): array
            {
                static::$sinkron[] = $groupIds;

                return ['success' => true, 'error' => null, 'data' => ['status' => 'ok']];
            }

            public function getItem(int $itemId): array
            {
                return ['success' => true, 'error' => null, 'data' => [
                    'product_skus' => [[
                        'item_id' => 55, 'sell_price' => 200_000, 'prices' => $this->hargaToko,
                    ]],
                ]];
            }
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }
}
