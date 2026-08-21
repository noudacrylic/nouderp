<?php

namespace Tests\Feature\Analysis;

use App\Models\User;
use App\Modules\Analysis\Models\PriceChannelFeeComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Simulasi promo — dua bentuknya (keranjang & per produk).
 *
 * Hitungan diskonnya sendiri diuji di PricingMathTest; yang dijaga di sini hal yang cuma
 * kelihatan dari luar: halamannya terpasang, batas diskon aman muncul per baris, dan tombol
 * "pakai promo yang aktif" benar-benar memanggil PromotionService yang dipakai penjualan
 * sungguhan — bukan menghitung diskon versinya sendiri yang kebetulan mirip.
 */
class PromoSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_simulasi_transaksi_memuat_katalog_produk(): void
    {
        $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())->get(route('analisa.harga.promo'))
            ->assertOk()
            ->assertSee('Simulasi Promo')
            ->assertSee('Frame Mahar Akrilik')
            ->assertSee('Diskon ongkir')
            // Biaya admin bisa diandaikan, tidak dikunci ke setting.
            ->assertSee('Biaya admin (%)');
    }

    public function test_per_produk_menampilkan_batas_diskon_sebelum_rugi(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $this->hargaMaster($id, 200_000);

        $this->actingAs($this->admin())
            ->get(route('analisa.harga.promo.produk', ['kanal' => 'website', 'diskon' => 15]))
            ->assertOk()
            ->assertSee('Diskon<br>maks', false)
            ->assertSee('Frame Mahar Akrilik');
    }

    public function test_potongan_kanal_ikut_menekan_untung_di_simulasi_per_produk(): void
    {
        // Migrasi menaburkan biaya Jubelio Rp250 untuk tiap kanal marketplace; di tes ini
        // potongannya dibuat bersih supaya angka yang diuji cuma satu hal.
        PriceChannelFeeComponent::where('channel', 'shopee')->delete();
        PriceChannelFeeComponent::create([
            'channel' => 'shopee', 'label' => 'Potongan marketplace', 'percent' => 14, 'fixed' => 0,
            'include_accounting' => true, 'sort_order' => 1,
        ]);

        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $this->hargaMaster($id, 200_000);

        $lihat = fn (string $kanal) => $this->actingAs($this->admin())
            ->get(route('analisa.harga.promo.produk', ['kanal' => $kanal, 'diskon' => 10]))
            ->assertOk();

        // Website tanpa potongan: harga setelah diskon 10% = 180.000, potongan Rp0.
        $lihat('website')->assertSee('Rp180.000');
        // Shopee: harga bersihnya sama, tapi ada potongan 14% × 180.000 = Rp25.200.
        $lihat('shopee')->assertSee('Rp25.200');
    }

    public function test_tombol_promo_aktif_memakai_promosi_yang_benar_benar_berjalan(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        DB::table('promotions')->insert([
            'name' => 'Diskon beli di toko', 'type' => 'item', 'discount_type' => 'percent',
            'discount_value' => 9, 'applies_to_all' => 1, 'is_voucher' => 0, 'is_active' => 1,
            'priority' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('analisa.harga.promo.aktif'), [
                'items'    => [['product_id' => $id, 'qty' => 2, 'unit_price' => 200_000]],
                'shipping' => 0,
            ])
            ->assertOk()
            // 9% dari 2 × 200.000 — nilai SATU BARIS, bukan per unit.
            ->assertJsonPath('items.0.amount', 36_000)
            ->assertJsonPath('items.0.product_id', $id)
            ->assertJsonPath('names.0', 'Diskon beli di toko');
    }

    public function test_tanpa_promo_aktif_hasilnya_kosong_bukan_error(): void
    {
        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);

        $this->actingAs($this->admin())
            ->postJson(route('analisa.harga.promo.aktif'), [
                'items' => [['product_id' => $id, 'qty' => 1, 'unit_price' => 200_000]],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('shipping', 0)
            ->assertJsonPath('cart', 0);
    }

    public function test_potongan_kanal_bisa_diandaikan_lewat_url(): void
    {
        PriceChannelFeeComponent::where('channel', 'shopee')->delete();
        PriceChannelFeeComponent::create([
            'channel' => 'shopee', 'label' => 'Potongan marketplace', 'percent' => 14, 'fixed' => 0,
            'include_accounting' => true, 'sort_order' => 1,
        ]);

        $id = $this->produk('AM-60', 'Frame Mahar Akrilik', 200_000);
        $this->hargaMaster($id, 200_000);

        // Andaikan potongannya naik jadi 20% + Rp2.000: 200.000 × 20% + 2.000 = Rp42.000.
        $this->actingAs($this->admin())
            ->get(route('analisa.harga.index', ['kanal' => 'shopee', 'fee_pct' => 20, 'fee_rp' => 2000]))
            ->assertOk()
            ->assertSee('Rp42.000')
            // Angka aslinya tetap terlihat supaya tidak ada yang mengira ini kenyataan.
            ->assertSee('Total potongan (andaian)')
            ->assertSee('kembali ke potongan asli');

        // Tanpa parameter, halaman kembali ke potongan sebenarnya (14% → Rp28.000).
        $this->actingAs($this->admin())
            ->get(route('analisa.harga.index', ['kanal' => 'shopee']))
            ->assertOk()
            ->assertSee('Rp28.000')
            ->assertDontSee('Total potongan (andaian)');
    }

    private function produk(string $sku, string $nama, float $harga): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => $harga, 'sale_type' => 'ready',
            'is_sellable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hargaMaster(int $productId, float $harga): void
    {
        DB::table('product_prices')->insert([
            'product_id' => $productId, 'unit_name' => 'pcs', 'channel' => 'default',
            'price' => $harga, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }
}
