<?php

namespace Tests\Feature\Shipping;

use App\Models\PaymentSetting;
use App\Models\ShippingSetting;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Peta checkout etalase (kurir instant): titik pin → alamat + area kurir, lalu ongkir.
 *
 * Yang dijaga di sini adalah dua hal yang baru ketahuan saat pembeli sungguhan mencoba:
 * kode pos hasil pembacaan titik harus ikut (Jubelio menolak tanpa itu), dan pesanan
 * instant tanpa koordinat harus ditolak SEBELUM SO terlanjur dibuat.
 */
class StorefrontInstantPointTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->key = StorefrontSetting::generateKey();
        StorefrontSetting::singleton()->update(['is_active' => true, 'api_key' => $this->key]);

        // Checkout menolak lebih dulu (503) bila belum ada cara bayar; satu rekening
        // transfer sudah cukup untuk sampai ke pemeriksaan yang sedang diuji.
        PaymentSetting::singleton()->update([
            'is_active'     => true,
            'bank_accounts' => [[
                'bank_name'       => 'BCA',
                'account_number'  => '1234567890',
                'account_holder'  => 'Noud Acrylic',
                'cash_account_id' => 1,
            ]],
        ]);

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

    public function test_titik_peta_diterjemahkan_jadi_alamat_dan_area_kurir(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'display_name' => 'Jl. Pemuda, Sekayu, Semarang Tengah, Kota Semarang',
                'address'      => [
                    'road'          => 'Jl. Pemuda',
                    'village'       => 'Sekayu',
                    'city_district' => 'Semarang Tengah',
                    'city'          => 'Kota Semarang',
                    'postcode'      => '50132',
                ],
            ]),
            '*/auth/generate-token' => Http::response(['token' => 'token-uji', 'expires_in' => 86400]),
            '*/regions*'            => Http::response(['data' => [[
                'area_id'  => '4823',
                'name'     => 'Semarang Tengah, Kota Semarang',
                'zipcode'  => '50132',
            ]]]),
        ]);

        $res = $this->withHeaders($this->headers())
            ->postJson('/api/storefront/shipping/point', ['latitude' => -6.9834, 'longitude' => 110.4093]);

        $res->assertOk();
        $this->assertSame('50132', $res->json('data.postal_code'));
        $this->assertStringContainsString('Semarang', (string) $res->json('data.address'));
        // Area kurir ikut terbawa: tanpa ini pesanan kurir biasa dari titik peta tidak
        // bisa dihitung ongkirnya.
        $this->assertSame('4823', $res->json('data.area_id'));
    }

    public function test_ongkir_instant_boleh_tanpa_area_id_tapi_wajib_kode_pos_dan_koordinat(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['token' => 'token-uji', 'expires_in' => 86400]),
            '*'            => Http::response(['data' => []]),
        ]);

        // Kode pos & koordinat lengkap, area_id kosong → lolos validasi (koordinat yang
        // menentukan tarif kurir instant; area_id cuma pelengkap).
        $this->withHeaders($this->headers())->postJson('/api/storefront/shipping/rates', [
            'mode'                    => 'instant',
            'destination_postal_code' => '50132',
            'destination_latitude'    => -6.9834,
            'destination_longitude'   => 110.4093,
            'items'                   => [['product_id' => 1, 'qty' => 1]],
        ])->assertOk();

        // Tanpa koordinat → ditolak; kurir instant tidak punya titik jemput.
        $this->withHeaders($this->headers())->postJson('/api/storefront/shipping/rates', [
            'mode'                    => 'instant',
            'destination_postal_code' => '50132',
            'items'                   => [['product_id' => 1, 'qty' => 1]],
        ])->assertStatus(422);

        // Mode reguler tetap mewajibkan area_id seperti sebelumnya.
        $this->withHeaders($this->headers())->postJson('/api/storefront/shipping/rates', [
            'destination_postal_code' => '50132',
            'items'                   => [['product_id' => 1, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_pesanan_instant_tanpa_titik_lokasi_ditolak(): void
    {
        $res = $this->withHeaders($this->headers())->postJson('/api/storefront/checkout', [
            'customer'        => ['name' => 'Budi', 'phone' => '08123456789', 'pin' => '1234'],
            'items'           => [['product_id' => 1, 'qty' => 1]],
            'delivery_method' => 'instant',
            'payment_method'  => 'midtrans',
            'shipping'        => ['courier_code' => '1', 'service_name' => 'Grab Instant', 'price' => 37000],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Titik lokasi', (string) $res->json('message'));
    }
}
