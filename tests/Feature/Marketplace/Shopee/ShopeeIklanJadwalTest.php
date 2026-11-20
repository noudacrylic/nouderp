<?php

namespace Tests\Feature\Marketplace\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklan;
use App\Modules\Marketplace\Shopee\Models\ShopeeIklanKelompok as Kelompok;
use App\Modules\Marketplace\Shopee\Models\ShopeeItem;
use App\Modules\Marketplace\Shopee\Services\ShopeeIklanJadwalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Kelompok iklan Shopee: jam ramai semua tayang, jam sepi bergiliran berbobot, mode bayangan. */
class ShopeeIklanJadwalTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeIklanJadwalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee' => ['partner_id' => '1000001', 'partner_key' => 'kunci-rahasia-uji', 'env' => 'test', 'iklan_maks_aksi' => 100]]);
        Http::fake();
        $this->svc = app(ShopeeIklanJadwalService::class);
        Carbon::setTestNow('2026-11-21 10:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kelompok(array $isi = []): Kelompok
    {
        return Kelompok::create($isi + ['env' => 'test', 'nama' => 'Tempat Brosur', 'mode' => 'bayangan',
            'jam_ramai' => [9, 10, 11, 12], 'aktif_sepi' => 2, 'rotasi_jam' => 3]);
    }

    private function iklan(int $id, ?Kelompok $k, string $status = 'ongoing', ?int $stok = null, float $biaya = 0, float $gmv = 0): ShopeeIklan
    {
        $i = ShopeeIklan::create(['env' => 'test', 'shop_id' => 555, 'kelompok_id' => $k?->id, 'campaign_id' => $id,
            'nama' => "Iklan $id", 'status' => $status, 'modal_harian' => 10000, 'item_ids' => [$id * 10]]);
        if ($stok !== null) {
            ShopeeItem::create(['env' => 'test', 'shop_id' => 555, 'item_id' => $id * 10, 'model_id' => 0, 'nama' => "Item $id", 'stok' => $stok]);
        }
        if ($biaya > 0) {
            DB::table('shopee_iklan_jam')->insert(['env' => 'test', 'campaign_id' => $id, 'tanggal' => now()->subDays(2)->toDateString(),
                'jam' => 10, 'biaya' => $biaya, 'gmv' => $gmv, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $i;
    }

    public function test_jam_sepi_bergiliran_rata_bila_bobot_sama(): void
    {
        $k = $this->kelompok(['jam_ramai' => []]);
        foreach ([1, 2, 3, 4] as $id) {
            $this->iklan($id, $k);
        }

        $rencana = $this->svc->rencanaHari($k, now()->startOfDay());
        $hitung  = array_count_values(array_merge(...array_values($rencana)));

        $this->assertSame(2, count($rencana[0]));
        // 8 giliran × 2 iklan × 3 jam = 48 jam-tayang, dibagi rata 4 iklan.
        $this->assertSame([1 => 12, 2 => 12, 3 => 12, 4 => 12], array_replace([1 => 0, 2 => 0, 3 => 0, 4 => 0], $hitung));
        // Dalam satu giliran (3 jam) iklan tidak berganti.
        $this->assertSame($rencana[3], $rencana[5]);
        $this->assertNotSame($rencana[0], $rencana[3]);
    }

    public function test_roas_bagus_lebih_sering_dapat_giliran(): void
    {
        $k = $this->kelompok(['jam_ramai' => [], 'aktif_sepi' => 1]);
        $this->iklan(1, $k, biaya: 100000, gmv: 3000000); // ROAS 30
        $this->iklan(2, $k, biaya: 100000, gmv: 500000);  // ROAS 5
        $this->iklan(3, $k, biaya: 100000, gmv: 500000);

        $hitung = array_count_values(array_merge(...array_values($this->svc->rencanaHari($k, now()->startOfDay()))));

        $this->assertGreaterThan($hitung[2] ?? 0, $hitung[1]);
        $this->assertGreaterThan(0, $hitung[2] ?? 0, 'yang ROAS-nya rendah tetap kebagian');
    }

    public function test_jam_ramai_semua_layak_tayang_stok_habis_dan_selesai_tidak(): void
    {
        $k = $this->kelompok();
        $this->iklan(1, $k, stok: 5);
        $this->iklan(2, $k, 'paused');
        $this->iklan(3, $k, stok: 0);          // stok habis
        $this->iklan(4, $k, 'ended');          // tak bisa diatur

        $this->assertSame([1, 2], collect($this->svc->aktifPada($k, now()->setTime(10, 0)))->sort()->values()->all());
        $sepi = $this->svc->aktifPada($k, now()->setTime(2, 0));
        $this->assertCount(2, $sepi);
        $this->assertEmpty(array_intersect([3, 4], $sepi));
    }

    public function test_batas_belanja_tercapai_jam_ramai_jadi_sepi(): void
    {
        $k = $this->kelompok(['batas_belanja' => 15000, 'aktif_sepi' => 1]);
        foreach ([1, 2, 3] as $id) {
            $this->iklan($id, $k);
        }
        $this->assertCount(3, $this->svc->aktifPada($k, now()->setTime(10, 0)));

        DB::table('shopee_iklan_jam')->insert(['env' => 'test', 'campaign_id' => 1, 'tanggal' => now()->toDateString(),
            'jam' => 9, 'biaya' => 16000, 'gmv' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(1, $this->svc->aktifPada($k, now()->setTime(10, 0)));
    }

    public function test_bayangan_mencatat_tanpa_memanggil_shopee_dan_tidak_mengulang(): void
    {
        $k = $this->kelompok();
        foreach ([1, 2, 3, 4] as $id) {
            $this->iklan($id, $k);
        }
        $this->iklan(9, null); // bukan anggota: tak pernah disentuh

        // 02.00 = jam sepi: 2 dari 4 berjalan dijeda.
        $h = $this->svc->jalankan(now()->setTime(2, 0));
        $this->assertSame(2, $h['aksi']);
        $this->assertSame(2, DB::table('shopee_iklan_aksi')->where('aksi', 'jeda')->where('hasil', 'bayangan')->count());
        $this->assertSame(0, DB::table('shopee_iklan_aksi')->where('campaign_id', 9)->count());

        // Jam sama dijalankan lagi → status simulasi sudah sesuai, tak ada aksi baru.
        $this->assertSame(0, $this->svc->jalankan(now()->setTime(2, 0))['aksi']);

        // 10.00 = jam ramai: yang dijeda dilanjutkan.
        $this->assertSame(2, $this->svc->jalankan(now()->setTime(10, 0))['aksi']);
        $this->assertSame(2, DB::table('shopee_iklan_aksi')->where('aksi', 'lanjut')->count());

        // Status asli di ERP tetap, dan tidak ada panggilan ke Shopee.
        $this->assertSame(5, ShopeeIklan::where('status', 'ongoing')->count());
        Http::assertNothingSent();
    }

    public function test_batas_aksi_harian_lanjut_didahulukan(): void
    {
        config(['services.shopee.iklan_maks_aksi' => 1]);
        $k = $this->kelompok(['aktif_sepi' => 1, 'status_simulasi' => ['1' => false, '2' => true, '3' => true]]);
        foreach ([1, 2, 3] as $id) {
            $this->iklan($id, $k);
        }

        $this->svc->jalankan(now()->setTime(10, 0)); // jam ramai: 1 perlu dilanjutkan
        $this->svc->jalankan(now()->setTime(13, 0)); // jam sepi: 2 perlu dijeda, batas sudah habis

        $this->assertSame(1, DB::table('shopee_iklan_aksi')->where('hasil', 'bayangan')->where('aksi', 'lanjut')->count());
        $this->assertSame(2, DB::table('shopee_iklan_aksi')->where('hasil', 'dilewati')->count());
    }

    public function test_jadwal_manual_diikuti_persis_sisanya_bergiliran(): void
    {
        $k = $this->kelompok(['jam_ramai' => [10], 'aktif_sepi' => 1, 'jadwal_manual' => ['1' => [2, 3], '4' => [2]]]);
        $this->iklan(1, $k);
        $this->iklan(2, $k);
        $this->iklan(3, $k);
        $this->iklan(4, $k, stok: 0); // manual tapi stok habis → tetap tidak tayang

        $jam2 = $this->svc->aktifPada($k, now()->setTime(2, 0));
        $this->assertContains(1, $jam2);
        $this->assertNotContains(4, $jam2);
        $this->assertCount(2, $jam2, 'manual 1 + satu giliran otomatis dari 2/3');

        // Jam ramai: anggota otomatis semua tayang, manual hanya di jamnya sendiri.
        $this->assertSame([2, 3], collect($this->svc->aktifPada($k, now()->setTime(10, 0)))->sort()->values()->all());

        $this->svc->jalankan(now()->setTime(10, 0));
        $this->assertSame('jadwal manual', DB::table('shopee_iklan_aksi')->where('campaign_id', 1)->value('alasan'));
    }

    public function test_simpan_dan_kembalikan_jadwal_manual_dari_grid(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok();
        $this->iklan(1, $k);
        $this->iklan(2, $k);
        $this->iklan(7, null); // bukan anggota

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.jadwal-manual', $k), [
            'baris' => ['1' => '5,3,3,99', '2' => '', '7' => '1,2'],
        ])->assertRedirect();
        $k->refresh();
        $this->assertSame([3, 5], $k->jamManual(1));
        $this->assertSame([], $k->jamManual(2), 'baris kosong = tidak pernah tayang');
        $this->assertNull($k->jamManual(7));

        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))->assertOk()->assertSee('↺ otomatis');

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.jadwal-manual', $k), ['otomatis' => 1])->assertRedirect();
        $this->assertNull($k->refresh()->jamManual(1));
        $this->assertSame([], $k->jamManual(2));

        // Keluar dari kelompok → jadwal manualnya ikut dibuang.
        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.update', $k), [
            'nama' => $k->nama, 'mode' => 'bayangan', 'aktif_sepi' => 1, 'rotasi_jam' => 3, 'anggota' => [1],
        ])->assertRedirect();
        $this->assertNull($k->refresh()->jadwal_manual);
    }

    public function test_halaman_kelompok_simpan_anggota_dan_tolak_otomatis(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $lain  = $this->kelompok(['nama' => 'Kotak Saran']);
        $this->iklan(1, null);
        $this->iklan(2, null);
        $this->iklan(3, $lain);

        $this->actingAs($admin)->post(route('sales.iklan-shopee.kelompok.store'), [
            'nama' => 'Brosur', 'mode' => 'bayangan', 'jam_ramai' => [10, 11], 'aktif_sepi' => 1, 'rotasi_jam' => 3,
            'batas_belanja' => '50.000', 'anggota' => [1, 2, 3],
        ])->assertRedirect();

        $k = Kelompok::where('nama', 'Brosur')->first();
        $this->assertEquals(50000, (float) $k->batas_belanja);
        $this->assertSame([1, 2], ShopeeIklan::where('kelompok_id', $k->id)->orderBy('campaign_id')->pluck('campaign_id')->all());
        $this->assertSame($lain->id, ShopeeIklan::where('campaign_id', 3)->value('kelompok_id'), 'anggota kelompok lain tidak dicuri');

        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))
            ->assertOk()->assertSee('Rencana tayang hari ini')->assertSee('Iklan 1');
        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.index'))->assertOk()->assertSee('Brosur');

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.update', $k), [
            'nama' => 'Brosur', 'mode' => 'otomatis', 'aktif_sepi' => 1, 'rotasi_jam' => 3,
        ])->assertSessionHasErrors('mode');
    }
}
