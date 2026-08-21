<?php

namespace Tests\Feature\Analysis;

use App\Core\Inventory\BundleComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Halaman HPP Bundle benar-benar terpasang.
 *
 * Perhitungannya sudah diuji di BundleHppServiceTest — yang dijaga di sini adalah hal yang
 * tidak terlihat dari service: route-nya tidak ke-shadow `hpp/{productId}`, izin menunya
 * ikut ke `analisa.hpp` (satu izin untuk dua sub-tab), dan blade-nya kompilasi.
 */
class BundleHppPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_bundle_menampilkan_paket_beserta_isinya(): void
    {
        $bundle   = $this->produk('PKT-9', 'Paket Hampers', 250_000, 'bundle');
        $komponen = $this->produk('KOMP-9', 'Isi Hampers', 90_000);

        BundleComponent::create([
            'bundle_product_id'    => $bundle,
            'component_product_id' => $komponen,
            'qty'                  => 2,
        ]);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('analisa.hpp.bundle.index'))
            ->assertOk()
            ->assertSee('Paket Hampers')
            ->assertSee('Isi Hampers')
            // Sub-tab Ready & Bundle dirender dari config, bukan diketik di halamannya.
            ->assertSee('/erp/analisa/hpp/bundle', false)
            ->assertSee('HPP Bundle');

        // `hpp/bundle` tidak boleh ditelan route `hpp/{productId}`.
        $this->actingAs($user)->get(route('analisa.hpp.bundle.show', $bundle))
            ->assertOk()
            ->assertSee('Susunan HPP per Paket');
    }

    public function test_produk_ready_tidak_ikut_muncul_di_daftar_bundle(): void
    {
        $this->produk('RDY-9', 'Produk Ready Biasa', 50_000);

        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('analisa.hpp.bundle.index'))
            ->assertOk()
            ->assertDontSee('Produk Ready Biasa');
    }

    private function produk(string $sku, string $nama, float $harga, string $tipe = 'ready'): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku, 'name' => $nama, 'base_price' => $harga, 'sale_type' => $tipe,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
