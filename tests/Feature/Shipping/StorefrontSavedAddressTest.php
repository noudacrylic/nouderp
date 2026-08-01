<?php

namespace Tests\Feature\Shipping;

use App\Models\Customer;
use App\Models\ShippingSetting;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alamat tersimpan pembeli toko online (HP + PIN).
 *
 * Yang dijaga: jawabannya harus cukup untuk langsung menghitung ongkir — termasuk untuk
 * pelanggan lama yang kolom area kurirnya belum pernah terisi — dan alamat orang lain
 * tidak boleh terbuka hanya dengan menebak nomor HP.
 */
class StorefrontSavedAddressTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);

        ShippingSetting::for('jubelio_shipment')->update([
            'is_enabled'    => true,
            'is_production' => false,
            'api_key'       => 'secret-uji',
            'config'        => ['client_id' => 'client-uji', 'categories' => []],
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'];
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code'             => 'WEB-UJI-1',
            'name'             => 'Budi',
            'phone'            => '08123456789',
            'web_order_pin'    => Hash::make('1234'),
            'address'          => 'Jl. Pemuda No. 1',
            'shipping_address' => 'Jl. Pemuda No. 1',
            'postal_code'      => '50132',
            'city'             => 'Semarang Tengah',
            'jubelio_area_id'  => '4823',
            'latitude'         => -6.9834,
            'longitude'        => 110.4093,
            'is_active'        => true,
        ], $attributes));
    }

    public function test_alamat_tersimpan_dibuka_dengan_pin_yang_benar(): void
    {
        $this->customer();

        $res = $this->withHeaders($this->headers())
            ->postJson('/api/storefront/orders/profile', ['phone' => '08123456789', 'pin' => '1234']);

        $res->assertOk();
        $this->assertSame('Jl. Pemuda No. 1', $res->json('data.address'));
        $this->assertSame('50132', $res->json('data.postal_code'));
        $this->assertSame('4823', $res->json('data.area_id'));
        $this->assertSame(-6.9834, $res->json('data.latitude'));
    }

    public function test_pin_salah_tidak_membuka_alamat(): void
    {
        $this->customer();

        $this->withHeaders($this->headers())
            ->postJson('/api/storefront/orders/profile', ['phone' => '08123456789', 'pin' => '9999'])
            ->assertStatus(401);

        $this->withHeaders($this->headers())
            ->postJson('/api/storefront/orders/profile', ['phone' => '08999999999', 'pin' => '1234'])
            ->assertStatus(401);
    }

    /**
     * Pelanggan dari pesanan lama belum punya jubelio_area_id (kolomnya baru diisi sejak
     * checkout menyimpannya). Tanpa pencarian dari kode pos, tombol "Pakai alamat ini"
     * akan menawarkan alamat yang justru tidak bisa dihitung ongkirnya.
     */
    public function test_area_kurir_dicarikan_dari_kode_pos_bila_belum_tersimpan(): void
    {
        $this->customer(['jubelio_area_id' => null, 'city' => null]);

        Http::fake([
            '*/auth/generate-token' => Http::response(['token' => 'token-uji', 'expires_in' => 86400]),
            '*/regions*'            => Http::response(['data' => [[
                'area_id' => '3374071003',
                'name'    => 'Barusari, Semarang Selatan, KOTA SEMARANG',
                'zipcode' => '50132',
            ]]]),
        ]);

        $res = $this->withHeaders($this->headers())
            ->postJson('/api/storefront/orders/profile', ['phone' => '08123456789', 'pin' => '1234']);

        $res->assertOk();
        $this->assertSame('3374071003', $res->json('data.area_id'));
        $this->assertStringContainsString('Semarang', (string) $res->json('data.destination_label'));
    }
}
