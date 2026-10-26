<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeSetting;
use App\Modules\Marketplace\Shopee\Services\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kunci app Shopee diisi dari Pengaturan › Integrasi › Shopee; .env hanya cadangan.
 */
class ShopeeSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => null, 'partner_key' => null, 'env' => 'test']]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_simpan_kunci_test_dan_live_terenkripsi(): void
    {
        $this->actingAs($this->superAdmin())->put(route('settings.integrations.shopee.update'), [
            'env' => 'test',
            'test_partner_id' => '1250096', 'test_partner_key' => 'kunci-test',
            'live_partner_id' => '2000001', 'live_partner_key' => 'kunci-live',
        ])->assertRedirect(route('settings.integrations.shopee.index'));

        $s = ShopeeSetting::singleton();
        $this->assertSame(1250096, $s->partnerId());
        $this->assertSame('kunci-test', $s->partnerKey());
        $this->assertSame('kunci-live', $s->partnerKey('live'));
        $this->assertNotSame('kunci-test', DB::table('shopee_settings')->value('test_partner_key'));

        $client = app(ShopeeClient::class);
        $this->assertTrue($client->siap());
        $this->assertSame(hash_hmac('sha256', 'x', 'kunci-test'), $client->tandaTangan('x'));
    }

    public function test_kunci_kosong_tidak_menimpa_dan_ganti_lingkungan(): void
    {
        ShopeeSetting::singleton()->update(['test_partner_id' => 1250096, 'test_partner_key' => 'kunci-test', 'live_partner_id' => 2000001, 'live_partner_key' => 'kunci-live']);

        $this->actingAs($this->superAdmin())->put(route('settings.integrations.shopee.update'), [
            'env' => 'live', 'test_partner_id' => '1250096', 'test_partner_key' => '', 'live_partner_id' => '2000001', 'live_partner_key' => '',
        ]);

        $s = ShopeeSetting::singleton();
        $this->assertSame('live', $s->envAktif());
        $this->assertSame('kunci-test', $s->partnerKey('test'));
        $this->assertSame('kunci-live', $s->partnerKey());
        $this->assertSame('https://partner.shopeemobile.com', app(ShopeeClient::class)->host());
    }

    public function test_hapus_kunci_tersimpan(): void
    {
        ShopeeSetting::singleton()->update(['test_partner_id' => 1250096, 'test_partner_key' => 'kunci-test']);

        $this->actingAs($this->superAdmin())->put(route('settings.integrations.shopee.update'), [
            'env' => 'test', 'test_partner_id' => '1250096', 'hapus_test_key' => '1',
        ]);

        $this->assertFalse(ShopeeSetting::singleton()->siap());
    }

    public function test_env_jadi_cadangan_bila_kolom_kosong(): void
    {
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'dari-env', 'env' => 'test']]);

        $s = ShopeeSetting::singleton();
        $this->assertTrue($s->siap());
        $this->assertFalse($s->tersimpan('test'));
        $this->assertFalse($s->siap('live'));
    }

    public function test_hanya_super_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->put(route('settings.integrations.shopee.update'), ['env' => 'live'])
            ->assertForbidden();
    }

    public function test_halaman_tampil_tanpa_membocorkan_kunci(): void
    {
        ShopeeSetting::singleton()->update(['test_partner_id' => 1250096, 'test_partner_key' => 'kunci-rahasia-xyz']);

        $this->actingAs($this->superAdmin())->get(route('settings.integrations.shopee.index'))
            ->assertOk()->assertSee('1250096')->assertSee('(tersimpan)')->assertDontSee('kunci-rahasia-xyz');
    }
}
