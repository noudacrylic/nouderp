<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Jubelio\Models\JubelioStorePrice;
use App\Modules\Marketplace\Jubelio\Services\JubelioClient;
use App\Modules\Marketplace\Jubelio\Services\JubelioProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penarikan harga toko dari Jubelio (Analisa ▸ Harga Produk, kolom "Di marketplace").
 *
 * Yang dijaga di sini satu hal, dan itu hal yang paling penting: ketika bentuk respons
 * Jubelio TIDAK seperti dugaan, kolomnya harus kosong beserta alasannya — bukan berisi
 * angka hasil menebak. Halaman itu dipakai menetapkan harga jual; angka karangan di sana
 * lebih berbahaya daripada kolom kosong.
 *
 * Bentuk respons harga per toko memang belum pernah diverifikasi ke Jubelio hidup (lihat
 * JubelioClient::getStorePrices). Justru karena itu jaring pengamannya diuji.
 */
class JubelioPullPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_harga_per_toko_terbaca_dan_tersimpan(): void
    {
        $id = $this->produk('AM-60', 55);

        $this->pakaiKlienPalsu(['data' => [
            'product_skus' => [[
                'item_id' => 55, 'sell_price' => 200_000,
                'prices'  => [
                    ['store_id' => 71, 'price' => 250_000],
                    ['store_id' => 72, 'price' => 265_000],
                ],
            ]],
        ]]);

        $stats = app(JubelioProductSyncService::class)->pullStorePrices([71, 72]);

        $this->assertSame(1, $stats['terisi']);
        $this->assertEquals(250_000, JubelioStorePrice::where('product_id', $id)->where('store_id', 71)->value('price'));
        $this->assertEquals(265_000, JubelioStorePrice::where('product_id', $id)->where('store_id', 72)->value('price'));
    }

    /** Toko tanpa harga khusus memang dijual di harga dasar — itu jawaban, bukan kegagalan. */
    public function test_toko_tanpa_harga_khusus_jatuh_ke_harga_dasar(): void
    {
        $id = $this->produk('AM-60', 55);

        $this->pakaiKlienPalsu(['data' => [
            'product_skus' => [['item_id' => 55, 'sell_price' => 200_000, 'prices' => []]],
        ]]);

        app(JubelioProductSyncService::class)->pullStorePrices([71]);

        $this->assertEquals(200_000, JubelioStorePrice::where('product_id', $id)->value('price'));
    }

    /**
     * Inti keselamatan fitur ini: respons yang tidak dikenali TIDAK boleh melahirkan angka.
     */
    public function test_respons_tak_dikenali_menghasilkan_kosong_beserta_alasannya(): void
    {
        $id = $this->produk('AM-60', 55);

        $this->pakaiKlienPalsu(['data' => ['sesuatu_yang_lain' => true]]);

        $stats = app(JubelioProductSyncService::class)->pullStorePrices([71]);

        $baris = JubelioStorePrice::where('product_id', $id)->firstOrFail();

        $this->assertSame(1, $stats['gagal']);
        $this->assertNull($baris->price, 'Bentuk respons asing tidak boleh ditebak jadi angka.');
        $this->assertNotNull($baris->note, 'Kolom kosong harus punya sebab yang bisa dibaca.');
    }

    /** Produk yang belum ter-match ke item Jubelio dicatat, bukan didiamkan. */
    public function test_produk_belum_termatch_dicatat_tanpa_harga(): void
    {
        $id = $this->produk('AM-61', null);

        $this->pakaiKlienPalsu(['data' => []]);

        app(JubelioProductSyncService::class)->pullStorePrices([71]);

        $baris = JubelioStorePrice::where('product_id', $id)->firstOrFail();
        $this->assertNull($baris->price);
        $this->assertStringContainsString('belum ter-match', (string) $baris->note);
    }

    /**
     * Batas per panggilan ada supaya permintaan web tidak kehabisan waktu, dan yang paling
     * lama tidak diperbarui dikerjakan lebih dulu — kalau tidak, klik berikutnya akan
     * mengulang produk yang itu-itu saja dan sisanya tidak pernah kebagian.
     */
    public function test_penarikan_dibatasi_dan_mendahulukan_yang_paling_basi(): void
    {
        $lama = $this->produk('AM-60', 55);
        $baru = $this->produk('AM-61', 56);

        JubelioStorePrice::create([
            'product_id' => $baru, 'store_id' => 71, 'price' => 1, 'fetched_at' => now(),
        ]);
        JubelioStorePrice::create([
            'product_id' => $lama, 'store_id' => 71, 'price' => 1, 'fetched_at' => now()->subDays(9),
        ]);

        $this->pakaiKlienPalsu(['data' => [
            'product_skus' => [
                ['item_id' => 55, 'sell_price' => 200_000],
                ['item_id' => 56, 'sell_price' => 300_000],
            ],
        ]]);

        $stats = app(JubelioProductSyncService::class)->pullStorePrices([71], null, 1);

        $this->assertSame(1, $stats['sisa'], 'Sisa harus dilaporkan, bukan diam-diam dipotong.');
        $this->assertEquals(200_000, JubelioStorePrice::where('product_id', $lama)->value('price'));
        $this->assertEquals(1, JubelioStorePrice::where('product_id', $baru)->value('price'));
    }

    // ==========================================================

    /** Klien Jubelio yang selalu siap dan selalu menjawab dengan payload yang ditentukan. */
    private function pakaiKlienPalsu(array $respons): void
    {
        $this->app->bind(JubelioClient::class, fn () => new class($respons) extends JubelioClient {
            public function __construct(private array $respons)
            {
                // Sengaja TIDAK memanggil parent: tes ini tidak boleh menyentuh pengaturan
                // maupun jaringan.
            }

            public function isReady(): bool
            {
                return true;
            }

            public function getItem(int $itemId): array
            {
                return array_merge(['success' => true, 'error' => null], $this->respons);
            }
        });
    }

    private function produk(string $sku, ?int $itemId): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => 'Uji ' . $sku, 'base_price' => 100_000, 'sale_type' => 'ready',
            'is_sellable' => 1, 'is_active' => 1, 'sync_to_jubelio' => 1,
            'jubelio_item_id' => $itemId, 'jubelio_item_group_id' => $itemId ? $itemId * 10 : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
