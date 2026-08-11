<?php

namespace Tests\Unit\Shipping;

use App\Modules\Shipping\CourierBrand;
use Tests\TestCase;

/**
 * Tanpa database — hanya butuh app aktif karena logo dikembalikan lewat asset().
 * Jalankan: php artisan test --filter=CourierBrandTest
 */
class CourierBrandTest extends TestCase
{
    private function file(?string $url): ?string
    {
        return $url ? basename(parse_url($url, PHP_URL_PATH)) : null;
    }

    public function test_kode_kurir_dicocokkan_apa_adanya(): void
    {
        $this->assertSame('jne.png', $this->file(CourierBrand::logo('jne', 'JNE JNE')));
        $this->assertSame('tiki.png', $this->file(CourierBrand::logo('tiki', 'TIKI Reguler')));
    }

    public function test_pemisah_dan_huruf_kecil_diabaikan_saat_mencocokkan(): void
    {
        // Provider menulis kode kurir beda-beda; semuanya harus jatuh ke logo yang sama.
        $this->assertSame('jnt-cargo.png', $this->file(CourierBrand::logo('jt_cargo', null)));
        $this->assertSame('jnt-cargo.png', $this->file(CourierBrand::logo(null, 'J&T Cargo')));
    }

    public function test_pola_terpanjang_menang_agar_cargo_tidak_jadi_jnt_biasa(): void
    {
        $this->assertSame('jnt-cargo.png', $this->file(CourierBrand::logo('JTCARGO REG')));
        $this->assertSame('jnt.png', $this->file(CourierBrand::logo('JT EZ')));
    }

    public function test_kandidat_dicoba_berurutan_sampai_ada_yang_cocok(): void
    {
        // Kode kurir kosong/tidak dikenal → jatuh ke nama layanan.
        $this->assertSame('lion-parcel.png', $this->file(CourierBrand::logo(null, 'Lion Parcel JAGOPACK')));
        $this->assertSame('gosend.png', $this->file(CourierBrand::logo('gojek', 'GOJEK Instant')));
    }

    public function test_kurir_tanpa_logo_mengembalikan_null_bukan_logo_asal(): void
    {
        $this->assertNull(CourierBrand::logo('lalamove', 'LALAMOVE Lalamove'));
        $this->assertNull(CourierBrand::logo('diambil_sendiri', 'Diambil Sendiri'));
        $this->assertNull(CourierBrand::logo(null, null));
        $this->assertNull(CourierBrand::logo('', '   '));
    }
}
