<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use App\Modules\Analysis\Models\ProductChannelPrice;
use App\Models\User;
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
