<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok as Kelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanAksiService;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanJadwalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Perintah tulis ke iklan Shopee: tombol uji jeda & lanjut, dan mode Otomatis kelompok. */
class ShopeeIklanAksiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> status per campaign di "Shopee" */
    private array $status = [11 => 'ongoing'];

    /** @var array<int, string> "aksi:campaign" yang dikirim */
    private array $aksi = [];

    private bool $tolakResume = false;

    /** @var array<int, int> modal harian per campaign di "Shopee" (bawaan 5000) */
    private array $modal = [];

    /** Berapa kali lagi edit ditolak karena batas kecepatan per toko. */
    private int $batasKali = 0;

    /** @var array<int, float> lama tunggu yang diminta */
    private array $tunggu = [];

    /** @var array<int, int> campaign yang perintahnya selalu gagal */
    private array $rusak = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test', 'iklan_maks_aksi' => 100]]);
        ShopeeShop::create([
            'shop_id' => 555, 'env' => 'test', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => now()->addHours(3), 'refresh_expires_at' => now()->addDays(20), 'terhubung_at' => now(),
        ]);
        ShopeeIklan::create(['env' => 'test', 'shop_id' => 555, 'campaign_id' => 11, 'nama' => 'Iklan kecil', 'status' => 'ongoing', 'modal_harian' => 5000]);

        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/v2/ads/edit_manual_product_ads') {
                $a   = $r->data()['edit_action'];
                $cid = (int) $r->data()['campaign_id'];
                $this->aksi[] = "$a:$cid";
                if ($this->batasKali > 0) {
                    $this->batasKali--;

                    return Http::response(['error' => 'ads.rate_limit.exceed_shop_api', 'message' => 'Too many requests for the shop at the moment, please try again later.']);
                }
                if (($a === 'resume' && $this->tolakResume) || in_array($cid, $this->rusak, true)) {
                    return Http::response(['error' => 'error_param', 'message' => 'ditolak']);
                }
                $this->status[$cid] = $a === 'pause' ? 'paused' : 'ongoing';

                return Http::response(['response' => ['campaign_id' => $cid]]);
            }
            if ($path === '/api/v2/ads/get_product_level_campaign_id_list') {
                return Http::response(['response' => ['has_next_page' => false,
                    'campaign_list' => array_map(fn ($id) => ['campaign_id' => $id], array_keys($this->status))]]);
            }
            if ($path === '/api/v2/ads/get_product_level_campaign_setting_info') {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $list = array_map(fn ($id) => ['campaign_id' => (int) $id, 'common_info' => ['campaign_status' => $this->status[(int) $id] ?? 'ended',
                    'ad_name' => "Iklan $id", 'campaign_budget' => $this->modal[(int) $id] ?? 5000]],
                    explode(',', $q['campaign_id_list']));

                return Http::response(['response' => ['campaign_list' => $list]]);
            }

            return Http::response(['response' => []]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_uji_jeda_lalu_lanjut_dicatat_berhasil(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])
            ->assertSessionHas('success');

        $this->assertSame(['pause:11', 'resume:11'], $this->aksi);
        $this->assertSame(['berhasil', 'berhasil'], DB::table('shopee_iklan_aksi')->orderBy('id')->pluck('hasil')->all());
        $this->assertSame('ongoing', ShopeeIklan::where('campaign_id', 11)->value('status'));
    }

    public function test_resume_ditolak_dicoba_start(): void
    {
        $this->tolakResume = true;
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])->assertSessionHas('success');

        $this->assertSame(['pause:11', 'resume:11', 'start:11'], $this->aksi);
    }

    public function test_iklan_yang_tidak_berjalan_tidak_diuji(): void
    {
        $this->status[11] = 'paused';
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('sales.iklan-shopee.uji-jeda'), ['campaign_id' => 11])->assertSessionHas('error');

        $this->assertSame([], $this->aksi);
    }

    private function kelompokOtomatis(): Kelompok
    {
        $k = Kelompok::create(['env' => 'test', 'nama' => 'Brosur', 'mode' => 'otomatis', 'jam_ramai' => [10], 'aktif_sepi' => 1, 'rotasi_jam' => 3]);
        foreach ([21, 22, 23] as $cid) {
            // Status di ERP sengaja basi ("paused"): otomatis harus memakai status asli dari Shopee.
            ShopeeIklan::create(['env' => 'test', 'shop_id' => 555, 'kelompok_id' => $k->id, 'campaign_id' => $cid,
                'nama' => "Brosur $cid", 'status' => 'paused', 'modal_harian' => 5000]);
            $this->status[$cid] = 'ongoing';
        }

        return $k;
    }

    public function test_otomatis_menjeda_sungguhan_di_jam_sepi_dan_melanjutkan_di_jam_ramai(): void
    {
        Carbon::setTestNow('2026-11-21 02:00:00');
        $this->kelompokOtomatis();
        $svc = app(ShopeeIklanJadwalService::class);

        $h = $svc->jalankan(now());
        $this->assertSame(2, $h['aksi']);
        $this->assertCount(1, array_filter([21, 22, 23], fn ($c) => $this->status[$c] === 'ongoing'), 'jam sepi: tinggal 1 tayang');
        $this->assertSame(2, DB::table('shopee_iklan_aksi')->where('mode', 'otomatis')->where('hasil', 'berhasil')->count());
        $this->assertNotContains('11', array_map(fn ($a) => explode(':', $a)[1], $this->aksi), 'iklan di luar kelompok tak disentuh');

        // Jam sama lagi → sudah sesuai, tak ada perintah baru.
        $this->aksi = [];
        $this->assertSame(0, $svc->jalankan(now())['aksi']);
        $this->assertSame([], array_filter($this->aksi, fn ($a) => str_starts_with($a, 'pause') || str_starts_with($a, 'resume')));

        // Jam ramai → yang dijeda dilanjutkan.
        $svc->jalankan(now()->setTime(10, 0));
        $this->assertSame(['ongoing', 'ongoing', 'ongoing'], [$this->status[21], $this->status[22], $this->status[23]]);
    }

    public function test_otomatis_lanjut_gagal_jeda_ditunda(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        $k = $this->kelompokOtomatis();
        $k->update(['jam_ramai' => [], 'jadwal_manual' => ['21' => [10], '22' => [], '23' => []]]);
        $this->status[21] = 'paused';
        $this->rusak = [21];

        $h = app(ShopeeIklanJadwalService::class)->jalankan(now());

        $this->assertSame(1, $h['gagal']);
        $this->assertSame(2, DB::table('shopee_iklan_aksi')->where('hasil', 'dilewati')->where('aksi', 'jeda')->count());
        $this->assertSame('ongoing', $this->status[22], 'tidak dijeda karena penggantinya gagal tayang');
    }

    public function test_batas_aksi_hanya_menahan_jeda_lanjut_tetap_dikerjakan(): void
    {
        Carbon::setTestNow('2026-11-21 02:00:00');
        config(['services.shopee.iklan_maks_aksi' => 1]);
        $this->kelompokOtomatis();
        $svc = app(ShopeeIklanJadwalService::class);

        $svc->jalankan(now()); // jam sepi: perlu 2 jeda, batas 1 → 1 jeda, 1 ditunda
        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('aksi', 'jeda')->where('hasil', 'berhasil')->count());
        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('aksi', 'jeda')->where('hasil', 'dilewati')->count());

        $svc->jalankan(now()->setTime(10, 0)); // jam ramai: batas sudah habis, lanjut tetap dikerjakan
        $this->assertSame(['ongoing', 'ongoing', 'ongoing'], [$this->status[21], $this->status[22], $this->status[23]]);
    }

    private function aksiTanpaTunggu(): ShopeeIklanAksiService
    {
        $svc = app(ShopeeIklanAksiService::class);
        $svc->tidur = function (float $d) { $this->tunggu[] = $d; };
        $this->app->instance(ShopeeIklanAksiService::class, $svc);

        return $svc;
    }

    public function test_batas_kecepatan_diulang_dengan_jeda_lalu_berhasil(): void
    {
        $svc = $this->aksiTanpaTunggu();
        $this->batasKali = 2;

        $r = $svc->ubah(ShopeeShop::first(), ShopeeIklan::where('campaign_id', 11)->first(), 'jeda', 'otomatis');

        $this->assertTrue($r['ok']);
        $this->assertSame(['pause:11', 'pause:11', 'pause:11'], $this->aksi);
        $this->assertSame([2.0, 5.0], $this->tunggu);
    }

    public function test_batas_kecepatan_terus_menerus_gagal_tanpa_mencoba_start(): void
    {
        $svc = $this->aksiTanpaTunggu();
        $this->status[11] = 'paused';
        $this->batasKali = 99;

        $r = $svc->ubah(ShopeeShop::first(), ShopeeIklan::where('campaign_id', 11)->first(), 'lanjut', 'otomatis');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ads.rate_limit.exceed_shop_api', $r['pesan']);
        $this->assertSame(['resume:11', 'resume:11', 'resume:11', 'resume:11'], $this->aksi, '1 kirim + 3 ulang, tak beralih ke start');
        $this->assertSame([2.0, 5.0, 10.0], $this->tunggu);
    }

    private function belanja(int $cid, string $tgl, int $jam, float $biaya): void
    {
        DB::table('shopee_iklan_jam')->upsert([['env' => 'test', 'campaign_id' => $cid, 'tanggal' => $tgl, 'jam' => $jam,
            'biaya' => $biaya, 'gmv' => 0, 'created_at' => now(), 'updated_at' => now()]], ['env', 'campaign_id', 'tanggal', 'jam'], ['biaya']);
    }

    public function test_modal_erp_awal_dari_median_belanja_sebelum_21_nov(): void
    {
        $k = $this->kelompokOtomatis();
        foreach (['2026-11-14' => 6998, '2026-11-15' => 7001, '2026-11-16' => 7000, '2026-11-21' => 25000] as $tgl => $b) {
            $this->belanja(21, $tgl, 9, $b); // 21 Nov (sesudah modal dinaikkan) tak ikut dihitung
        }
        $this->belanja(22, '2026-11-10', 9, 4200);
        $this->belanja(22, '2026-11-11', 9, 5900);
        ShopeeIklan::where('campaign_id', 23)->update(['modal_harian' => 25000]); // tanpa riwayat & modal sudah dinaikkan

        $this->assertSame(3, app(ShopeeIklanJadwalService::class)->isiModalErpAwal($k));
        $this->assertEquals(7000, (float) ShopeeIklan::where('campaign_id', 21)->value('modal_erp'));
        $this->assertEquals(5000, (float) ShopeeIklan::where('campaign_id', 22)->value('modal_erp'), 'median 4200 & 5900 = 5050 → 5000');
        $this->assertEquals(ShopeeIklanJadwalService::MODAL_ERP_CADANGAN, (float) ShopeeIklan::where('campaign_id', 23)->value('modal_erp'));
    }

    public function test_modal_erp_habis_dijeda_walau_jam_ramai_dan_dilanjutkan_besok(): void
    {
        Carbon::setTestNow('2026-11-22 10:15:00'); // jam ramai
        $this->kelompokOtomatis();
        ShopeeIklan::whereIn('campaign_id', [21, 22, 23])->update(['modal_erp' => 7000]);
        $this->belanja(22, '2026-11-22', 9, 7300);   // sudah lewat modal ERP
        $this->belanja(23, '2026-11-22', 9, 3000);
        $svc = app(ShopeeIklanJadwalService::class);

        $svc->jalankan(now()->startOfHour());
        $this->assertSame('paused', $this->status[22]);
        $this->assertSame('ongoing', $this->status[21]);
        $this->assertSame('ongoing', $this->status[23]);
        $this->assertSame('modal ERP hari ini habis', DB::table('shopee_iklan_aksi')->where('campaign_id', 22)->value('alasan'));

        // Jam ramai berikutnya di hari yang sama: tetap dijeda.
        $svc->jalankan(now()->setTime(13, 0));
        $this->assertSame('paused', $this->status[22]);

        // Besok jam ramai: hitungan mulai dari nol, dilanjutkan lagi.
        Carbon::setTestNow('2026-11-23 10:01:00');
        $svc->jalankan(now()->startOfHour());
        $this->assertSame('ongoing', $this->status[22]);
    }

    public function test_jeda_modal_erp_habis_tidak_ditahan_batas_aksi_dan_dilewati_tak_berulang(): void
    {
        Carbon::setTestNow('2026-11-22 13:16:00'); // jam sepi (jam ramai hanya 10)
        config(['services.shopee.iklan_maks_aksi' => 0]); // batas sudah habis
        $this->kelompokOtomatis();
        ShopeeIklan::whereIn('campaign_id', [21, 22, 23])->update(['modal_erp' => 7000]);
        $this->belanja(22, '2026-11-22', 12, 9000); // modal ERP habis
        $svc = app(ShopeeIklanJadwalService::class);

        $svc->jalankan(now()->startOfHour());
        $this->assertSame('paused', $this->status[22], 'penjaga uang tetap dijalankan');
        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('campaign_id', 22)->where('hasil', 'berhasil')->count());
        // Rotasi jam sepi: satu dari 21/23 perlu dijeda, tapi ditahan batas.
        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('hasil', 'dilewati')->count());

        Carbon::setTestNow('2026-11-22 13:31:00');
        $svc->jalankan(now()->startOfHour());
        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('hasil', 'dilewati')->count(), 'tidak dicatat ulang tiap 15 menit');
    }

    public function test_simpan_modal_erp_dari_grid(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompokOtomatis();

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.jadwal-manual', $k), [
            'modal_erp' => ['21' => '7.500', '22' => '', '11' => '1.000'],
        ])->assertRedirect();

        $this->assertEquals(7500, (float) ShopeeIklan::where('campaign_id', 21)->value('modal_erp'));
        $this->assertNull(ShopeeIklan::where('campaign_id', 22)->value('modal_erp'), 'kosong = tak diubah');
        $this->assertNull(ShopeeIklan::where('campaign_id', 11)->value('modal_erp'), 'bukan anggota');
        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))->assertOk()->assertSee('modal_erp[21]', false);
    }

    public function test_kabar_telegram_bila_modal_shopee_anggota_naik(): void
    {
        $this->kelompokOtomatis();
        $this->modal = [21 => 25000, 11 => 25000]; // 11 bukan anggota kelompok → tak dikabarkan
        $telegram = \Mockery::mock(\App\Modules\Notifications\Services\TelegramNotifier::class);
        $telegram->shouldReceive('notifyApprovers')->once()->withArgs(fn ($t) => str_contains($t, 'Brosur 21') && str_contains($t, 'Rp25.000') && !str_contains($t, 'Iklan kecil') && !str_contains($t, 'Brosur 22'))->andReturn(1);
        $this->app->instance(\App\Modules\Notifications\Services\TelegramNotifier::class, $telegram);

        app(\App\Modules\Marketplace\Shopee\Services\ShopeeIklanService::class)->tarikDaftar(ShopeeShop::first());

        $this->assertEquals(25000, (float) ShopeeIklan::where('campaign_id', 21)->value('modal_harian'));
    }

    public function test_mode_otomatis_bisa_dipilih_di_halaman(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompokOtomatis();
        $k->update(['mode' => 'bayangan']);

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.update', $k), [
            'nama' => 'Brosur', 'mode' => 'otomatis', 'aktif_sepi' => 1, 'rotasi_jam' => 3, 'anggota' => [21, 22, 23],
        ])->assertSessionHasNoErrors();
        $this->assertSame('otomatis', $k->refresh()->mode);
    }
}
