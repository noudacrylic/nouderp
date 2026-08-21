<?php

namespace Tests\Feature\Analysis;

use App\Modules\Analysis\Services\BundleHppService;
use App\Modules\Analysis\Services\ChannelPricingService;
use App\Modules\Analysis\Services\ProductHppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * HPP di halaman Harga Produk harus angka yang SAMA PERSIS dengan halaman HPP.
 *
 * Pernah tidak: kedua sumber HPP (Ready & Bundle) digabung dengan `merge()`, padahal
 * keduanya dikunci product_id — kunci integer — dan `merge()` menomori ulang kunci integer
 * seperti array_merge. Akibatnya HPP produk A mendarat di produk B tanpa error apa pun,
 * dan angka di halaman harga terlihat "masuk akal" walau salah total.
 *
 * Karena itu yang diuji di sini bukan besar HPP-nya (itu urusan ProductHppServiceTest),
 * melainkan bahwa angkanya mendarat di produk yang benar.
 */
class ChannelPricingHppSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hpp_tiap_produk_diambil_dari_sumbernya_masing_masing(): void
    {
        $ready  = $this->produk('KOIN-TPM', 'Koin Akrilik', 1_750, 'ready');
        $bundle = $this->produk('AM-30-EX', 'Frame Mahar Extra', 94_900, 'bundle');

        $this->instance(ProductHppService::class, Mockery::mock(ProductHppService::class, function ($m) use ($ready) {
            $m->shouldReceive('all')->andReturn(collect([$ready => ['hpp_per_unit' => 365.0]]));
        }));
        $this->instance(BundleHppService::class, Mockery::mock(BundleHppService::class, function ($m) use ($bundle) {
            $m->shouldReceive('all')->andReturn(collect([$bundle => ['hpp_per_unit' => 39_954.0]]));
        }));

        $rows = app(ChannelPricingService::class)->rows('website')->keyBy('product.sku');

        $this->assertEquals(365.0, $rows['KOIN-TPM']['hpp']);
        $this->assertEquals(39_954.0, $rows['AM-30-EX']['hpp']);

        // Dan untungnya ikut benar: 1.750 − 365, tanpa potongan di kanal Website.
        $this->assertEquals(1_385.0, $rows['KOIN-TPM']['satuan']['profit']);
    }

    public function test_produk_tanpa_hpp_ditandai_kosong_bukan_nol(): void
    {
        $this->produk('TANPA-HPP', 'Produk Tanpa Sampel', 50_000, 'ready');

        $this->instance(ProductHppService::class, Mockery::mock(ProductHppService::class, function ($m) {
            $m->shouldReceive('all')->andReturn(collect());
        }));
        $this->instance(BundleHppService::class, Mockery::mock(BundleHppService::class, function ($m) {
            $m->shouldReceive('all')->andReturn(collect());
        }));

        $row = app(ChannelPricingService::class)->rows('website')->firstWhere('product.sku', 'TANPA-HPP');

        $this->assertNull($row['hpp']);
        // Untung tidak boleh dilaporkan sebesar harga jual hanya karena HPP-nya belum ada.
        $this->assertNull($row['satuan']['profit']);
        $this->assertNull($row['satuan']['markup_percent']);
    }

    private function produk(string $sku, string $nama, float $harga, string $tipe): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => $harga, 'sale_type' => $tipe,
            'is_sellable' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
