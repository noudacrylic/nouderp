<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeApiLog;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use App\Modules\Marketplace\Shopee\Services\ShopeeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 0 Shopee Open Platform: tanda tangan, OAuth, perpanjangan token, log tanpa rahasia.
 */
class ShopeeClientTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://partner.test-stable.shopeemobile.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
    }

    private function client(): ShopeeClient
    {
        return app(ShopeeClient::class);
    }

    private function toko(array $isi = []): ShopeeShop
    {
        return ShopeeShop::create($isi + [
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'akses-lama', 'refresh_token' => 'segar-lama',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
    }

    public function test_tanda_tangan_hmac_sha256_partner_key(): void
    {
        $this->assertSame(hash_hmac('sha256', '1000001/api/v2/shop/auth_partner1700000000', 'kunci-rahasia-uji'),
            $this->client()->tandaTangan('1000001/api/v2/shop/auth_partner1700000000'));
    }

    public function test_url_otorisasi_memuat_redirect_dan_tanda_tangan(): void
    {
        $url = $this->client()->urlOtorisasi('https://erp.noudakrilik.com/erp/integrasi/shopee/callback');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith(self::HOST . '/api/v2/shop/auth_partner?', $url);
        $this->assertSame('1000001', $q['partner_id']);
        $this->assertSame('https://erp.noudakrilik.com/erp/integrasi/shopee/callback', $q['redirect']);
        $this->assertSame($this->client()->tandaTangan('1000001/api/v2/shop/auth_partner' . $q['timestamp']), $q['sign']);
    }

    public function test_callback_menukar_kode_menyimpan_token_terenkripsi(): void
    {
        Http::fake([
            self::HOST . '/api/v2/auth/token/get*' => Http::response(['access_token' => 'akses-baru', 'refresh_token' => 'segar-baru', 'expire_in' => 14400, 'error' => '', 'request_id' => 'r1']),
            self::HOST . '/api/v2/shop/get_shop_info*' => Http::response(['shop_name' => 'noud_acrylic', 'error' => '', 'request_id' => 'r2']),
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('settings.integrations.shopee.callback', ['code' => 'kode-1', 'shop_id' => 777]))
            ->assertRedirect(route('settings.integrations.shopee.index'))->assertSessionHas('success');

        $shop = ShopeeShop::sole();
        $this->assertSame('noud_acrylic', $shop->nama);
        $this->assertSame('akses-baru', $shop->access_token);
        $this->assertNotSame('akses-baru', DB::table('shopee_shops')->value('access_token'), 'Token tersimpan terenkripsi.');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/auth/token/get') && $r['code'] === 'kode-1' && $r['shop_id'] === 777);
    }

    public function test_access_token_hampir_habis_diperbarui_dulu_dan_refresh_token_berganti(): void
    {
        Http::fake([
            self::HOST . '/api/v2/auth/access_token/get*' => Http::response(['access_token' => 'akses-2', 'refresh_token' => 'segar-2', 'expire_in' => 14400, 'error' => '']),
            self::HOST . '/api/v2/shop/get_shop_info*' => Http::response(['shop_name' => 'x', 'error' => '']),
        ]);
        $shop = $this->toko(['access_expires_at' => now()->addMinutes(2)]);

        $this->client()->panggil($shop, 'GET', '/api/v2/shop/get_shop_info');

        $shop->refresh();
        $this->assertSame('akses-2', $shop->access_token);
        $this->assertSame('segar-2', $shop->refresh_token);
        Http::assertSent(function (Request $r) {
            if (!str_contains($r->url(), 'get_shop_info')) return false;
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            return $q['access_token'] === 'akses-2'
                && $q['sign'] === hash_hmac('sha256', '1000001/api/v2/shop/get_shop_info' . $q['timestamp'] . 'akses-2' . '555', 'kunci-rahasia-uji');
        });
    }

    public function test_galat_shopee_dilempar_dan_dicatat_tanpa_token(): void
    {
        Http::fake([self::HOST . '/*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token.', 'request_id' => 'rq-9'], 403)]);

        try {
            $this->client()->panggil($this->toko(), 'GET', '/api/v2/shop/get_shop_info');
            $this->fail('Harus melempar ShopeeException.');
        } catch (ShopeeException $e) {
            $this->assertSame('error_auth', $e->kode);
        }

        $log = ShopeeApiLog::sole();
        $this->assertSame('/api/v2/shop/get_shop_info', $log->path);
        $this->assertSame(403, $log->http_status);
        $this->assertSame('rq-9', $log->request_id);
        $this->assertStringNotContainsString('akses-lama', json_encode($log->toArray()));
    }

    public function test_izin_kedaluwarsa_tidak_memanggil_api(): void
    {
        Http::fake();

        $this->expectException(ShopeeException::class);
        $this->client()->panggil($this->toko(['refresh_expires_at' => now()->subDay()]), 'GET', '/api/v2/shop/get_shop_info');
    }

    public function test_perintah_perbarui_token_hanya_yang_hampir_habis(): void
    {
        Http::fake([self::HOST . '/api/v2/auth/access_token/get*' => Http::response(['access_token' => 'akses-3', 'refresh_token' => 'segar-3', 'expire_in' => 14400, 'error' => ''])]);
        $habis = $this->toko(['shop_id' => 1, 'access_expires_at' => now()->addMinutes(30)]);
        $masih = $this->toko(['shop_id' => 2, 'access_expires_at' => now()->addHours(3)]);

        $this->artisan('shopee:perbarui-token')->assertSuccessful();

        $this->assertSame('akses-3', $habis->fresh()->access_token);
        $this->assertSame('akses-lama', $masih->fresh()->access_token);
    }

    public function test_halaman_pengaturan_hanya_super_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->get(route('settings.integrations.shopee.index'))->assertOk()->assertSee('Kunci app terbaca')->assertDontSee('kunci-rahasia-uji');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->get(route('settings.integrations.shopee.index'))->assertForbidden();
    }
}
