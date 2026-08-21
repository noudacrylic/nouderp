<?php

namespace Tests\Unit\Analysis;

use App\Modules\Analysis\Support\PricingMath;
use PHPUnit\Framework\TestCase;

/**
 * Aritmetika harga jual. Yang dijaga di sini dua hal yang paling gampang salah:
 * potongan dipungut dari HARGA (bukan HPP), dan biaya tetap ditanggung SEKALI per pesanan.
 */
class PricingMathTest extends TestCase
{
    public function test_potongan_dihitung_dari_harga_jual_bukan_dari_hpp(): void
    {
        // HPP 10.000, dijual 20.000 di kanal 14% + Rp1.850.
        $s = PricingMath::scenario(10_000, 20_000, 14, 1_850);

        $this->assertEquals(4_650, $s['deduction']);          // 2.800 + 1.850
        $this->assertEquals(5_350, $s['profit']);             // 20.000 − 4.650 − 10.000
        $this->assertEqualsWithDelta(53.5, $s['markup_percent'], 0.01);
        $this->assertEqualsWithDelta(26.75, $s['margin_percent'], 0.01);
    }

    public function test_biaya_tetap_ditanggung_sekali_per_pesanan_bukan_per_barang(): void
    {
        $satuan = PricingMath::scenario(10_000, 15_000, 14, 1_850);
        $grosir = PricingMath::scenario(10_000, 15_000, 14, 1_850, qty: 5);

        // Persentasenya sama persis; yang membuat grosir terasa ringan cuma biaya tetap
        // yang terbagi lima.
        $this->assertEqualsWithDelta(26.33, $satuan['deduction_percent'], 0.01);
        $this->assertEqualsWithDelta(16.47, $grosir['deduction_percent'], 0.01);

        $this->assertEqualsWithDelta(12_350, $grosir['deduction'], 0.01);    // 10.500 + 1.850 (sekali)
        $this->assertEqualsWithDelta(12_650, $grosir['profit'], 0.01);       // 75.000 − 12.350 − 50.000
    }

    public function test_harga_dari_target_markup_menghasilkan_markup_itu_lagi(): void
    {
        $harga = PricingMath::priceForMarkup(10_000, 60, 14, 1_850);

        $this->assertEqualsWithDelta(60.0, PricingMath::scenario(10_000, $harga, 14, 1_850)['markup_percent'], 0.01);
    }

    public function test_harga_grosir_dari_target_markup_memperhitungkan_biaya_tetap_terbagi(): void
    {
        $harga = PricingMath::priceForMarkup(10_000, 60, 14, 1_850, qty: 5);

        $this->assertEqualsWithDelta(60.0, PricingMath::scenario(10_000, $harga, 14, 1_850, qty: 5)['markup_percent'], 0.01);
        // Per unit lebih murah daripada satuan karena biaya tetapnya dibagi lima.
        $this->assertLessThan(PricingMath::priceForMarkup(10_000, 60, 14, 1_850), $harga);
    }

    public function test_potongan_seratus_persen_tidak_pernah_punya_harga_yang_untung(): void
    {
        $this->assertNull(PricingMath::priceForMarkup(10_000, 30, 100, 0));
        $this->assertNull(PricingMath::priceForMarkup(null, 30, 14, 1_850));
    }

    public function test_pembulatan_selalu_ke_atas(): void
    {
        $this->assertEquals(20_000, PricingMath::roundUp(19_901));
        $this->assertEquals(19_900, PricingMath::roundUp(19_900));
        $this->assertNull(PricingMath::roundUp(null));
    }

    public function test_diskon_maksimum_berhenti_tepat_di_titik_impas(): void
    {
        // HPP 10.000, dijual 20.000, potongan 14% + Rp1.850.
        $maks = PricingMath::maxDiscountPercent(10_000, 20_000, 14, 1_850);

        $this->assertEqualsWithDelta(31.1, $maks, 0.05);

        // Didiskon persis sebesar itu, untungnya nol — bukan minus, bukan masih tebal.
        $harga = 20_000 * (1 - $maks / 100);
        $this->assertEqualsWithDelta(0, PricingMath::scenario(10_000, $harga, 14, 1_850)['profit'], 1);
    }

    public function test_harga_yang_sudah_rugi_tidak_punya_diskon_aman(): void
    {
        $this->assertNull(PricingMath::maxDiscountPercent(20_000, 20_000, 14, 1_850));
        $this->assertNull(PricingMath::maxDiscountPercent(null, 20_000, 14, 1_850));
        $this->assertNull(PricingMath::maxDiscountPercent(10_000, null, 14, 1_850));
    }

    public function test_tanpa_harga_semuanya_kosong_bukan_nol(): void
    {
        $s = PricingMath::scenario(10_000, null, 14, 1_850);

        $this->assertNull($s['profit']);
        $this->assertNull($s['deduction']);
        $this->assertNull($s['markup_percent']);
    }
}
