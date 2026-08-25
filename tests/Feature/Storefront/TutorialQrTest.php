<?php

namespace Tests\Feature\Storefront;

use App\Models\StoreTutorial;
use App\Models\StorefrontSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tutorial pemasangan — tujuan QR pada stiker produk.
 *
 * Yang dijaga di sini bukan tampilan, melainkan hal-hal yang TIDAK BISA
 * diperbaiki setelah stiker menempel di barang yang sudah dikirim:
 *
 *   - kode harus ketemu apa pun besar-kecil hurufnya, karena QR menyandikan
 *     huruf besar (mode alfanumerik QR jauh lebih padat) sementara alamat yang
 *     tercetak untuk mata manusia huruf kecil;
 *   - tutorial draft tidak boleh bocor ke etalase;
 *   - penghitung scan harus terpisah dari penghitung kunjungan, sebab hanya
 *     angka scan yang menggantikan statistik bit.ly yang dilepas.
 */
class TutorialQrTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'];
    }

    private function tutorial(array $attrs = []): StoreTutorial
    {
        return StoreTutorial::create(array_merge([
            'code'       => 'tb1',
            'slug'       => 'cara-memasang-tempat-brosur',
            'title'      => 'Cara Memasang Tempat Brosur',
            'youtube_id' => 'abcdefghijk',
            'status'     => 'published',
        ], $attrs));
    }

    public function test_scan_dihitung_dan_tidak_peduli_besar_kecil_huruf(): void
    {
        $t = $this->tutorial();

        // Persis yang dikirim ponsel setelah membaca QR (disandikan huruf besar).
        $this->withHeaders($this->headers())
            ->postJson('/api/storefront/tutorials/scan/TB1')
            ->assertNoContent();

        // Dan yang mengetik alamatnya sendiri dari teks di bawah QR.
        $this->withHeaders($this->headers())
            ->postJson('/api/storefront/tutorials/scan/tb1')
            ->assertNoContent();

        $t->refresh();
        $this->assertSame(2, $t->scan_count);
        $this->assertSame(0, $t->view_count, 'scan tidak boleh ikut menaikkan kunjungan');
    }

    public function test_kunjungan_terpisah_dari_scan(): void
    {
        $t = $this->tutorial();

        $this->withHeaders($this->headers())
            ->postJson("/api/storefront/tutorials/{$t->slug}/view")
            ->assertNoContent();

        $t->refresh();
        $this->assertSame(1, $t->view_count);
        $this->assertSame(0, $t->scan_count, 'kunjungan biasa bukan scan stiker');
    }

    public function test_tutorial_draft_tidak_tampil_di_etalase(): void
    {
        $this->tutorial(['status' => 'draft']);

        $daftar = $this->withHeaders($this->headers())->getJson('/api/storefront/tutorials');
        $daftar->assertOk();
        $this->assertSame([], $daftar->json('data'));

        $this->withHeaders($this->headers())
            ->getJson('/api/storefront/tutorials/cara-memasang-tempat-brosur')
            ->assertNotFound();
    }

    public function test_daftar_membawa_kode_supaya_etalase_bisa_memetakan_qr(): void
    {
        $this->tutorial();

        $daftar = $this->withHeaders($this->headers())->getJson('/api/storefront/tutorials');

        // Tanpa `code` di daftar, etalase harus memanggil balik ERP tiap kali QR
        // di-scan — padahal yang men-scan sedang berdiri memegang kotaknya.
        $daftar->assertOk()->assertJsonPath('data.0.code', 'tb1');
    }

    #[DataProvider('bentukTautanYoutube')]
    public function test_id_youtube_dikenali_dari_bentuk_tautan_apa_pun(string $input, ?string $expected): void
    {
        $this->assertSame($expected, StoreTutorial::extractYoutubeId($input));
    }

    public static function bentukTautanYoutube(): array
    {
        // Admin menyalin dari tempat berbeda-beda: bilah alamat, tombol Bagikan,
        // aplikasi HP (yang menambahkan ?si=...). Menolak salah satunya hanya
        // membuat admin menebak-nebak kenapa videonya tidak muncul.
        return [
            'bilah alamat'   => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'tombol bagikan' => ['https://youtu.be/dQw4w9WgXcQ?si=xyz123', 'dQw4w9WgXcQ'],
            'embed'          => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts'         => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'id telanjang'   => ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'bukan tautan'   => ['sekadar teks', null],
        ];
    }

    public function test_alamat_qr_sama_persis_dengan_yang_tercetak(): void
    {
        config(['store.storefront_url' => 'https://noudakrilik.com']);
        $t = $this->tutorial();

        // Yang tercetak untuk mata manusia: huruf kecil.
        $this->assertSame('https://noudakrilik.com/t/tb1', $t->shortUrl());

        // Yang disandikan ke QR harus SAMA PERSIS. Pernah dibuat huruf besar
        // demi mode alfanumerik QR yang lebih padat, dan hasil scan-nya 404:
        // path alamat peduli besar-kecil huruf, hanya nama domain yang tidak.
        $this->assertSame($t->shortUrl(), $t->qrPayload());
        $this->assertStringContainsString('/t/tb1', $t->qrPayload());
    }
}
