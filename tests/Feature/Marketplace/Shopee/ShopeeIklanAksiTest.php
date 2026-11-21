<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok as Kelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeShop;
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
                if (($a === 'resume' && $this->tolakResume) || in_array($cid, $this->rusak, true)) {
                    return Http::response(['error' => 'error_param', 'message' => 'ditolak']);
                }
                $this->status[$cid] = $a === 'pause' ? 'paused' : 'ongoing';

                return Http::response(['response' => ['campaign_id' => $cid]]);
            }
            if ($path === '/api/v2/ads/get_product_level_campaign_setting_info') {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $list = array_map(fn ($id) => ['campaign_id' => (int) $id, 'common_info' => ['campaign_status' => $this->status[(int) $id] ?? 'ended']],
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
