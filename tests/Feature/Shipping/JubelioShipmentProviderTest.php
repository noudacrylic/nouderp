<?php

namespace Tests\Feature\Shipping;

use App\Models\ShippingSetting;
use App\Modules\Shipping\Providers\JubelioShipmentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adapter Jubelio Shipment — diuji dengan HTTP palsu karena client_id/secret sungguhan
 * belum ada (menunggu onboarding dari PIC).
 *
 * Yang dijaga di sini adalah hal-hal yang tidak akan ketahuan sampai transaksi pertama:
 * bentuk terjemahan payload, rumus tanda tangan webhook yang tidak lazim, dan bahwa
 * token 24 jam benar-benar dipakai ulang alih-alih diminta tiap panggilan.
 */
class JubelioShipmentProviderTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-uji-123';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        ShippingSetting::for('jubelio_shipment')->update([
            'is_enabled'    => true,
            'is_production' => false,
            'api_key'       => 'secret-uji',
            'webhook_token' => 'rahasia-webhook',
            'config'        => ['client_id' => 'client-uji', 'categories' => []],
        ]);
    }

    private function provider(): JubelioShipmentProvider
    {
        return new JubelioShipmentProvider(ShippingSetting::for('jubelio_shipment')->fresh());
    }

    private function fakeToken(): array
    {
        return ['token' => self::TOKEN, 'expires_in' => 86400];
    }

    public function test_memakai_base_url_sandbox_saat_mode_produksi_mati(): void
    {
        $this->assertSame(JubelioShipmentProvider::SANDBOX_BASE_URL, $this->provider()->baseUrl());

        ShippingSetting::for('jubelio_shipment')->update(['is_production' => true]);

        $this->assertSame(JubelioShipmentProvider::PRODUCTION_BASE_URL, $this->provider()->baseUrl());
    }

    public function test_token_diambil_sekali_lalu_dipakai_ulang(): void
    {
        Http::fake([
            '*/auth/generate-token'   => Http::response($this->fakeToken()),
            '*/services/categories'   => Http::response([['service_category_id' => 6, 'name' => 'CARGO']]),
        ]);

        $provider = $this->provider();
        $provider->serviceCategories();
        $provider->serviceCategories();

        // Token 24 jam: dua panggilan API hanya boleh sekali minta token.
        $tokenCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/auth/generate-token'))
            ->count();

        $this->assertSame(1, $tokenCalls);
    }

    public function test_rates_menerjemahkan_payload_dan_menormalkan_hasil(): void
    {
        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates/all'           => Http::response([[
                'courier_id'               => 13,
                'courier_name'             => 'J&T Cargo',
                'courier_service_id'       => 1327,
                'courier_service_name'     => 'JTR',
                'courier_service_category' => 'CARGO',
                'service_category_id'      => 6,
                'rates'                    => 90000,
                'final_rates'              => 85000,
            ]]),
        ]);

        $res = $this->provider()->rates([
            'origin_jubelio_id'       => '3174021003',
            'origin_postal_code'      => '50123',
            'destination_jubelio_id'  => '3174041003',
            'destination_postal_code' => '12560',
            'items'                   => [['name' => 'Paket', 'weight' => 2500, 'quantity' => 2, 'value' => 100000]],
        ]);

        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['rates']);

        $rate = $res['rates'][0];
        $this->assertSame('jubelio_shipment', $rate['provider']);
        $this->assertSame('13', $rate['courier_code']);
        $this->assertSame('1327', $rate['service_code']);
        // Harga yang dipakai adalah final_rates (sesudah diskon), bukan rates kotor.
        $this->assertSame(85000.0, $rate['price']);

        $sent = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/rates/all'));
        $body = $sent[0]->data();

        $this->assertSame('3174021003', $body['origin']['area_id']);
        $this->assertSame('12560', $body['destination']['zipcode']);
        // Berat total = 2500 g × 2 item.
        $this->assertSame(5000, $body['weight']);
    }

    /**
     * Diuji langsung ke API produksi 1 Agu 2026: item tanpa kunci dimensi ditolak
     * `VAL_ERR` di `/items/0` — bahkan ketika package_detail sudah dikirim. Nilai 0
     * diterima, jadi produk yang belum diukur tetap bisa dicek ongkirnya.
     */
    public function test_setiap_item_selalu_membawa_ketiga_dimensi(): void
    {
        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates/all'           => Http::response([]),
        ]);

        $this->provider()->rates([
            'origin_postal_code'      => '50241',
            'destination_postal_code' => '12830',
            // Produk tanpa dimensi sama sekali — kasus paling umum di master barang.
            'items'                   => [['name' => 'Paket', 'weight' => 5000, 'quantity' => 1]],
        ]);

        $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/rates/all'))[0]->data();

        foreach (['length', 'width', 'height'] as $dim) {
            $this->assertArrayHasKey($dim, $body['items'][0], "Kunci {$dim} hilang — Jubelio akan menolak VAL_ERR.");
        }
    }

    /**
     * Etalase tidak mengenal gudang — ia hanya mengirim tujuan. Kalau asal tidak diisi
     * sendiri oleh provider, checkout toko online mati total dengan pesan "kode pos asal
     * wajib diisi", persis seperti yang terjadi saat peralihan dari RajaOngkir.
     */
    public function test_asal_ongkir_jatuh_ke_gudang_penjualan_bila_tidak_disebut(): void
    {
        $warehouse = \App\Core\Inventory\Warehouse::create([
            'name'            => 'Utama',
            'postal_code'     => '50267',
            'jubelio_area_id' => '3374071005',
            'is_active'       => true,
            'is_sellable'     => true,
        ]);

        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates/all'           => Http::response([]),
        ]);

        $res = $this->provider()->rates([
            'destination_postal_code' => '12810',
            'items'                   => [['weight' => 1000, 'quantity' => 1]],
        ]);

        $this->assertTrue($res['success']);

        $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/rates/all'))[0]->data();
        $this->assertSame($warehouse->postal_code, $body['origin']['zipcode']);
        $this->assertSame($warehouse->jubelio_area_id, $body['origin']['area_id']);
    }

    public function test_rates_menolak_bila_kode_pos_kosong(): void
    {
        Http::fake(['*' => Http::response($this->fakeToken())]);

        $res = $this->provider()->rates([
            'origin_jubelio_id'      => '3174021003',
            'destination_jubelio_id' => '3174041003',
            'items'                  => [['weight' => 1000, 'quantity' => 1]],
        ]);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Kode pos', $res['error']);
    }

    public function test_kategori_yang_tidak_dicentang_disaring(): void
    {
        ShippingSetting::for('jubelio_shipment')->update([
            'config' => ['client_id' => 'client-uji', 'categories' => [6]],   // hanya CARGO
        ]);

        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates/all'           => Http::response([
                ['courier_id' => 11, 'courier_service_id' => 1101, 'service_category_id' => 1, 'courier_service_category' => 'REGULER', 'rates' => 10000],
                ['courier_id' => 13, 'courier_service_id' => 1327, 'service_category_id' => 6, 'courier_service_category' => 'CARGO', 'rates' => 90000],
            ]),
        ]);

        $res = $this->provider()->rates([
            'origin_postal_code'      => '50123',
            'destination_postal_code' => '12560',
            'items'                   => [['weight' => 1000, 'quantity' => 1]],
        ]);

        $this->assertCount(1, $res['rates']);
        $this->assertSame('13', $res['rates'][0]['courier_code']);
    }

    public function test_mode_instant_meminta_kategori_instant_saja(): void
    {
        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates'               => Http::response([]),
        ]);

        $this->provider()->rates([
            'mode'                    => 'instant',
            'origin_postal_code'      => '50267',
            'destination_postal_code' => '50133',
            'items'                   => [['weight' => 1000, 'quantity' => 1]],
        ]);

        $sent = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/rates'));
        $this->assertSame(JubelioShipmentProvider::CATEGORY_INSTANT, $sent[0]->data()['service_category_id']);
    }

    /**
     * Tarif instant berkali lipat tarif reguler (mis. Rp36.000 vs Rp6.500 untuk rute
     * yang sama) dan butuh titik lokasi. Tercampur di daftar yang sama, operator bisa
     * memilihnya tanpa sadar.
     */
    public function test_mode_reguler_menyembunyikan_kurir_instant(): void
    {
        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/rates/all'           => Http::response([
                ['courier_id' => 11, 'courier_service_id' => 1101, 'service_category_id' => 1, 'courier_service_category' => 'REGULER', 'rates' => 8000],
                ['courier_id' => 40, 'courier_service_id' => 4001, 'service_category_id' => 4, 'courier_service_category' => 'INSTANT', 'rates' => 36000],
            ]),
        ]);

        $res = $this->provider()->rates([
            'mode'                    => 'regular',
            'origin_postal_code'      => '50267',
            'destination_postal_code' => '50133',
            'items'                   => [['weight' => 1000, 'quantity' => 1]],
        ]);

        $this->assertCount(1, $res['rates']);
        $this->assertSame('11', $res['rates'][0]['courier_code']);
    }

    public function test_create_order_mengirim_id_kurir_dan_mengembalikan_resi(): void
    {
        Http::fake([
            '*/auth/generate-token' => Http::response($this->fakeToken()),
            '*/shipments/create'    => Http::response([
                'shipment_id'  => 1281,
                'awb'          => 'LSAJ8933UJFCCN0',
                'tracking_url' => 'https://track.example/1281',
                'price'        => 85000,
            ]),
        ]);

        $res = $this->provider()->createOrder([
            'reference'                 => 'SJ/2026/08/0001',
            'courier_code'              => '13',
            'service_code'              => '1327',
            'origin_contact_name'       => 'Gudang Utama',
            'origin_contact_phone'      => '081200000000',
            'origin_address'            => 'Jl. Pengirim',
            'origin_postal_code'        => '50123',
            'destination_contact_name'  => 'Budi',
            'destination_contact_phone' => '081211111111',
            'destination_address'       => 'Jl. Penerima',
            'destination_postal_code'   => '12560',
            'items'                     => [['name' => 'Akrilik A3', 'description' => 'SKU-1', 'weight' => 2000, 'quantity' => 1, 'value' => 150000]],
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('LSAJ8933UJFCCN0', $res['tracking_id']);
        $this->assertSame('1281', $res['order_id']);

        $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/shipments/create'))[0]->data();
        $this->assertSame(13, $body['courier_id']);
        $this->assertSame(1327, $body['courier_service_id']);
        $this->assertSame('SJ/2026/08/0001', $body['ref_no']);
        // Saat terbit resi, item wajib membawa identitas (kode & nama), bukan cuma berat.
        $this->assertSame('SKU-1', $body['items'][0]['item_code']);
    }

    public function test_create_order_menolak_id_kurir_tidak_valid(): void
    {
        Http::fake(['*' => Http::response($this->fakeToken())]);

        $res = $this->provider()->createOrder(['courier_code' => 'jne', 'service_code' => 'REG']);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('tidak valid', $res['error']);
    }

    public function test_tanda_tangan_webhook_memakai_secret_dua_kali(): void
    {
        $provider = $this->provider();
        $body     = '{"event":"awb","awb":"XYZ"}';
        $secret   = 'rahasia-webhook';

        // Rumus kontrak v1.8: HMAC-SHA256(key = secret, message = payload + secret).
        $expected = hash_hmac('sha256', $body . $secret, $secret);

        $this->assertSame($expected, $provider->webhookSignature($body));
        $this->assertTrue($provider->verifyWebhook($body, $expected));

        // Rumus "wajar" (tanpa secret di ekor payload) harus DITOLAK — kalau ini lolos,
        // berarti implementasinya menyimpang dari kontrak.
        $this->assertFalse($provider->verifyWebhook($body, hash_hmac('sha256', $body, $secret)));
    }

    public function test_webhook_menolak_tanda_tangan_salah(): void
    {
        $this->postJson(route('shipping.jubelio.webhook'), ['awb' => 'XYZ', 'latest_status' => 'DELIVERED'], [
            'x-jubelio-signature' => 'ngawur',
        ])->assertStatus(403);
    }

    public function test_webhook_sah_dengan_resi_tak_dikenal_dijawab_200(): void
    {
        $payload = ['event' => 'awb', 'awb' => 'TIDAK-ADA', 'latest_status' => 'DELIVERED'];
        $raw     = json_encode($payload);

        // 200 supaya Jubelio berhenti mengulang kiriman yang memang bukan milik ERP.
        $this->call('POST', route('shipping.jubelio.webhook'), [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'HTTP_X_JUBELIO_SIGNATURE' => $this->provider()->webhookSignature($raw),
        ], $raw)->assertOk()->assertJsonPath('ok', false);
    }

    public function test_status_jubelio_dipetakan_ke_status_internal(): void
    {
        $this->assertSame('delivered', JubelioShipmentProvider::internalStatus('DELIVERED'));
        $this->assertSame('in_transit', JubelioShipmentProvider::internalStatus('ON_DELIVERY'));
        $this->assertSame('cancelled', JubelioShipmentProvider::internalStatus('CANCELED'));
        $this->assertNull(JubelioShipmentProvider::internalStatus('STATUS-BARU-YANG-BELUM-DIKENAL'));
    }

    public function test_tidak_siap_bila_salah_satu_kredensial_kosong(): void
    {
        ShippingSetting::for('jubelio_shipment')->update(['config' => ['client_id' => '']]);
        $this->assertFalse($this->provider()->isReady());

        ShippingSetting::for('jubelio_shipment')->update(['config' => ['client_id' => 'client-uji'], 'api_key' => '']);
        $this->assertFalse($this->provider()->isReady());
    }
}
