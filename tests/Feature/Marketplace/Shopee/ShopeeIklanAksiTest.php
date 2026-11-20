<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tombol "Uji jeda & lanjut": perintah tulis ke iklan Shopee + baca balik status. */
class ShopeeIklanAksiTest extends TestCase
{
    use RefreshDatabase;

    private string $status = 'ongoing';

    private array $aksi = [];

    private bool $tolakResume = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        ShopeeIklan::create(['env' => 'test', 'shop_id' => 555, 'campaign_id' => 11, 'nama' => 'Iklan kecil', 'status' => 'ongoing', 'modal_harian' => 5000]);

        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/v2/ads/edit_manual_product_ads') {
                $a = $r->data()['edit_action'];
                $this->aksi[] = $a;
                if ($a === 'resume' && $this->tolakResume) {
                    return Http::response(['error' => 'error_param', 'message' => 'invalid edit_action']);
                }
                $this->status = $a === 'pause' ? 'paused' : 'ongoing';

                return Http::response(['response' => ['campaign_id' => 11]]);
            }
            if ($path === '/api/v2/ads/get_product_level_campaign_setting_info') {
                return Http::response(['response' => ['campaign_list' => [['campaign_id' => 11, 'common_info' => ['campaign_status' => $this->status]]]]]);
            }

            return Http::response(['response' => []]);
        });
    }

    public function test_uji_jeda_lalu_lanjut_dicatat_berhasil(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])
            ->assertSessionHas('success');

        $this->assertSame(['pause', 'resume'], $this->aksi);
        $this->assertSame(['berhasil', 'berhasil'], DB::table('shopee_iklan_aksi')->orderBy('id')->pluck('hasil')->all());
        $this->assertSame('ongoing', ShopeeIklan::where('campaign_id', 11)->value('status'));
    }

    public function test_resume_ditolak_dicoba_start(): void
    {
        $this->tolakResume = true;
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])->assertSessionHas('success');

        $this->assertSame(['pause', 'resume', 'start'], $this->aksi);
    }

    public function test_iklan_yang_tidak_berjalan_tidak_diuji(): void
    {
        $this->status = 'paused';
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])->assertSessionHas('error');

        $this->assertSame([], $this->aksi);
    }
}
