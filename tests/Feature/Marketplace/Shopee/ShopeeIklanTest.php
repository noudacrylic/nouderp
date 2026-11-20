<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Iklan Shopee tahap 1 (baca saja): tarik daftar + kinerja per jam, ringkasan halaman. */
class ShopeeIklanTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> path Ads API yang dipanggil */
    private array $dipanggil = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test']]);
        ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);

        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->dipanggil[] = $path;
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return match ($path) {
                '/api/v2/ads/get_product_level_campaign_id_list' => Http::response(['response' => [
                    'has_next_page' => false,
                    'campaign_list' => [['campaign_id' => 11, 'ad_type' => 'manual'], ['campaign_id' => 22, 'ad_type' => 'manual']],
                ]]),
                '/api/v2/ads/get_product_level_campaign_setting_info' => Http::response(['response' => ['campaign_list' => [
                    $this->setelan(11, 'ongoing', 10000, 'Tempat Brosur A4'),
                    $this->setelan(22, 'ended', 5000, 'Kotak Saran lama'),
                ]]]),
                '/api/v2/ads/get_total_balance' => Http::response(['response' => ['total_balance' => 1778509]]),
                '/api/v2/ads/get_all_cpc_ads_hourly_performance' => Http::response(['response' => [
                    $this->metrik(0, 7000, 0), $this->metrik(1, 4000, 50000), $this->metrik(14, 2000, 60000),
                ]]),
                '/api/v2/ads/get_product_campaign_hourly_performance' => Http::response(['response' => ['campaign_list' => [[
                    'campaign_id'  => 11,
                    'metrics_list' => [$this->metrik(0, 6000, 0), $this->metrik(1, 4000, 50000), $this->metrik(14, 0, 0)],
                ]]]]),
                default => Http::response(['response' => []]),
            };
        });
    }

    private function setelan(int $id, string $status, int $modal, string $nama): array
    {
        return ['campaign_id' => $id, 'common_info' => [
            'ad_type' => 'manual', 'ad_name' => $nama, 'campaign_status' => $status, 'bidding_method' => 'auto',
            'campaign_placement' => 'all', 'campaign_budget' => $modal,
            'campaign_duration' => ['start_time' => 1732467600, 'end_time' => 0], 'item_id_list' => [9001],
        ], 'auto_bidding_info' => ['roas_target' => 20]];
    }

    private function metrik(int $jam, int $biaya, int $gmv): array
    {
        return ['hour' => $jam, 'date' => now()->format('d-m-Y'), 'impression' => 100, 'clicks' => 5, 'expense' => $biaya,
                'broad_order' => $gmv ? 1 : 0, 'broad_gmv' => $gmv, 'direct_order' => 0, 'direct_gmv' => 0];
    }

    public function test_tarik_menyimpan_iklan_dan_kinerja_per_jam(): void
    {
        $this->artisan('shopee:tarik-iklan', ['--hari' => 1])->assertSuccessful();

        $a = ShopeeIklan::where('campaign_id', 11)->first();
        $this->assertSame('ongoing', $a->status);
        $this->assertEquals(10000, (float) $a->modal_harian);
        $this->assertEquals(20, (float) $a->roas_target);
        $this->assertSame([9001], $a->item_ids);
        $this->assertSame('ended', ShopeeIklan::where('campaign_id', 22)->value('status'));

        // 2 hari × (3 baris toko + 3 baris iklan 11). Iklan yang sudah selesai tidak ditanya per jam.
        $this->assertSame(12, DB::table('shopee_iklan_jam')->count());
        $this->assertSame(6, DB::table('shopee_iklan_jam')->where('campaign_id', 0)->count());
        $this->assertStringNotContainsString('22', implode(',', array_filter($this->dipanggil, fn ($p) => str_contains($p, 'campaign_hourly'))));

        // Tarik ulang = timpa, bukan dobel.
        $this->artisan('shopee:tarik-iklan', ['--hari' => 1])->assertSuccessful();
        $this->assertSame(12, DB::table('shopee_iklan_jam')->count());
    }

    public function test_tarik_tidak_memanggil_endpoint_yang_mengubah_iklan(): void
    {
        $this->artisan('shopee:tarik-iklan')->assertSuccessful();

        foreach ($this->dipanggil as $p) {
            $this->assertMatchesRegularExpression('#/ads/get_#', $p, "Tahap baca saja tidak boleh memanggil $p");
        }
    }

    public function test_jam_habis_median_dan_hari_ini_tidak_dihitung(): void
    {
        $svc = app(ShopeeIklanService::class);
        $hasil = $svc->jamHabis([
            '2026-11-17' => [0 => 6000, 1 => 4000, 5 => 0],   // habis jam 1
            '2026-11-18' => [0 => 3000, 3 => 7000],           // habis jam 3
            '2026-11-19' => [0 => 2000, 9 => 1000],           // tak habis
            '2026-11-20' => [0 => 10000],                     // hari ini — dilewati
        ], 10000, '2026-11-20');

        $this->assertSame(['hari_aktif' => 3, 'hari_habis' => 2, 'jam' => 3], $hasil);
        $this->assertSame(['hari_aktif' => 1, 'hari_habis' => 0, 'jam' => null], $svc->jamHabis(['2026-11-17' => [0 => 500]], 0));
    }

    public function test_halaman_menampilkan_peta_jam_dan_iklan(): void
    {
        $this->artisan('shopee:tarik-iklan', ['--hari' => 1])->assertSuccessful();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('sales.iklan-shopee.index'))
            ->assertOk()
            ->assertSee('Iklan Shopee')
            ->assertSee('Tempat Brosur A4')
            ->assertDontSee('Kotak Saran lama')        // selesai → disembunyikan bawaan
            ->assertSee('± jam 01', false)             // kemarin: 6000 + 4000 = modal 10000 di jam 1
            ->assertSee('Rp1.778.509');

        $this->actingAs($admin)->get(route('sales.iklan-shopee.index', ['status' => 'semua']))->assertSee('Kotak Saran lama');
    }

    public function test_staf_biasa_tidak_bisa_membuka(): void
    {
        $staf = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $this->actingAs($staf)->get(route('sales.iklan-shopee.index'))->assertForbidden();
    }
}
