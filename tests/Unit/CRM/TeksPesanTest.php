<?php

namespace Tests\Unit\CRM;

use App\Modules\CRM\Support\TeksPesan;
use PHPUnit\Framework\TestCase;

/**
 * Tautan di gelembung thread.
 *
 * Yang dijaga di sini bukan cuma "tautannya biru", tapi dua hal yang bisa
 * merugikan: isi pesan datang dari pelanggan, jadi markup titipan TIDAK BOLEH
 * lolos ke layar admin; dan tanda baca di ekor kalimat tidak boleh ikut
 * terbawa ke dalam alamat, karena tautan yang benar di HP pelanggan lalu
 * membuka 404 di layar kita membuat admin mengira ia salah kirim.
 */
class TeksPesanTest extends TestCase
{
    public function test_tautan_jadi_anchor_yang_bisa_diklik(): void
    {
        $html = (string) TeksPesan::tautkan('Lihat https://noudakrilik.com/produk/box-mahar ya');

        $this->assertStringContainsString('<a href="https://noudakrilik.com/produk/box-mahar"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_markup_titipan_pelanggan_tidak_pernah_lolos(): void
    {
        $html = (string) TeksPesan::tautkan('<script>alert(1)</script> <b>tebal</b>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** Titik penutup kalimat bukan bagian alamat. */
    public function test_tanda_baca_di_ekor_tidak_ikut_masuk_tautan(): void
    {
        $html = (string) TeksPesan::tautkan('Cek di https://noudakrilik.com/produk/a.');

        $this->assertStringContainsString('href="https://noudakrilik.com/produk/a"', $html);
        $this->assertStringEndsWith('.', $html);
    }

    /**
     * Alamat dengan query string lolos escape sebagai &amp; — kalau titik
     * komanya ikut dipangkas mentah-mentah, entitasnya patah jadi "&amp" dan
     * tautannya menuju alamat yang berbeda.
     */
    public function test_query_string_tidak_patah_karena_pemangkasan(): void
    {
        $html = (string) TeksPesan::tautkan('https://noudakrilik.com/cari?a=1&b=2');

        $this->assertStringContainsString('href="https://noudakrilik.com/cari?a=1&amp;b=2"', $html);
    }

    public function test_teks_tanpa_tautan_dibiarkan_apa_adanya(): void
    {
        $this->assertSame('Halo, apa kabar?', (string) TeksPesan::tautkan('Halo, apa kabar?'));
    }
}
