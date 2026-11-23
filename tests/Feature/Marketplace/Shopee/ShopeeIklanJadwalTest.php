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

    public function test_bayangan_tidak_dibatasi_batas_aksi(): void
    {
        config(['services.shopee.iklan_maks_aksi' => 1]);
        $k = $this->kelompok(['aktif_sepi' => 1, 'status_simulasi' => ['1' => false, '2' => true, '3' => true]]);
        foreach ([1, 2, 3] as $id) {
            $this->iklan($id, $k);
        }

        $this->svc->jalankan(now()->setTime(10, 0)); // jam ramai: 1 perlu dilanjutkan
        $this->svc->jalankan(now()->setTime(13, 0)); // jam sepi: 2 perlu dijeda

        // Bayangan hanya catatan perkiraan: semua tercatat walau melebihi batas.
        $this->assertSame(3, DB::table('shopee_iklan_aksi')->where('hasil', 'bayangan')->count());
        $this->assertSame(0, DB::table('shopee_iklan_aksi')->where('hasil', 'dilewati')->count());
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

    public function test_jadwal_iklan_stok_habis_disiapkan_dan_dipakai_saat_stok_ada(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok();
        $this->iklan(1, $k);
        $this->iklan(2, $k, stok: 0);

        $this->actingAs($admin)->put(route('sales.iklan-shopee.kelompok.jadwal-manual', $k), ['baris' => ['2' => '8,9']])->assertRedirect();
        $k->refresh();
        $this->assertNotContains(2, $this->svc->aktifPada($k, now()->setTime(8, 0)));
        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))->assertOk()->assertSee('hijau muda dipakai lagi');

        ShopeeItem::where('item_id', 20)->update(['stok' => 7]);
        $this->assertContains(2, $this->svc->aktifPada($k, now()->setTime(8, 0)));
        $this->assertNotContains(2, $this->svc->aktifPada($k, now()->setTime(10, 0)));
    }

    public function test_grid_menampilkan_roas_per_jam_dan_kelompok(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok();
        $this->iklan(1, $k, biaya: 1000, gmv: 12500);  // jam 10, 2 hari lalu → ROAS 12,5
        $this->iklan(2, $k, biaya: 2000, gmv: 5000);   // ROAS 2,5

        $kin = $this->svc->kinerjaPerJam($k, 14);
        $this->assertEqualsWithDelta(12.5, $kin['iklan'][1][10]['roas'], 0.001);
        $this->assertEqualsWithDelta(17500 / 3000, $kin['jam'][10]['roas'], 0.001);

        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', ['kelompok' => $k, 'roas' => 7]))
            ->assertOk()->assertSee('ROAS kelompok')->assertSee('>13<', false)->assertSee('>2,5<', false)->assertSee('7 hari');
    }

    public function test_iklan_ditampilkan_dengan_sku_varian_dipisah_koma(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok();
        $this->iklan(1, $k);
        foreach ([['TBKD-A5-T1', 2], ['TBKD-A4-T1', 1], ['TBKD-A4-T1', 3]] as [$sku, $model]) {
            ShopeeItem::create(['env' => 'test', 'shop_id' => 555, 'item_id' => 10, 'model_id' => $model, 'model_sku' => $sku, 'nama' => 'Item 1', 'stok' => 5]);
        }

        $this->assertSame([1 => 'TBKD-A4-T1, TBKD-A5-T1'], ShopeeIklan::petaSku(ShopeeIklan::all()));
        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))->assertOk()->assertSee('TBKD-A4-T1, TBKD-A5-T1');
        $this->actingAs($admin)->get(route('sales.iklan-shopee.index'))->assertOk()->assertSee('TBKD-A4-T1, TBKD-A5-T1');
    }

    public function test_keramaian_pesanan_per_jam_dan_baris_langkah(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok(['jam_ramai' => [10], 'aktif_sepi' => 1]);
        foreach ([1, 2, 3] as $id) {
            $this->iklan($id, $k);
        }
        foreach ([['2026-11-19 10:15', 'A'], ['2026-11-19 10:40', 'B'], ['2026-11-20 10:05', 'C'], ['2026-11-20 14:00', 'D'], ['2026-11-21 10:30', 'HARI-INI']] as [$t, $sn]) {
            DB::table('shopee_orders')->insert(['env' => 'test', 'shop_id' => 555, 'order_sn' => $sn, 'status' => 'COMPLETED',
                'dibuat_shopee_at' => $t, 'created_at' => now(), 'updated_at' => now()]);
        }

        $ramai = $this->svc->pesananPerJam('test', 7);
        $this->assertEqualsWithDelta(3 / 7, $ramai[10], 0.0001, 'pesanan hari ini tidak dihitung');
        $this->assertEqualsWithDelta(1 / 7, $ramai[14], 0.0001);

        // Rencana: jam 10 ramai (3 tayang), jam lain 1 → langkah di awal jam 10 = 2 nyala, jam 11 = 2 jeda.
        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', ['kelompok' => $k, 'roas' => 7]))
            ->assertOk()->assertSee('Langkah (nyala/jeda)')->assertSee('bg-orange-500', false)->assertSee('pesanan Shopee/hari');
    }

    public function test_kolom_kemarin_jam_modal_erp_habis(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $k = $this->kelompok();
        $this->iklan(1, $k)->update(['modal_erp' => 7000]);
        $this->iklan(2, $k)->update(['modal_erp' => 10000]);
        $kemarin = now()->subDay()->toDateString();
        foreach ([[1, 8, 3000], [1, 11, 4500], [1, 15, 900], [2, 9, 2000], [2, 20, 1500]] as [$cid, $jam, $b]) {
            DB::table('shopee_iklan_jam')->insert(['env' => 'test', 'campaign_id' => $cid, 'tanggal' => $kemarin, 'jam' => $jam,
                'biaya' => $b, 'gmv' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $a = $this->svc->anggota($k)->keyBy(fn ($x) => $x['iklan']->campaign_id);
        $this->assertSame(['biaya' => 8400.0, 'habis_jam' => 11, 'akhir_jam' => 15], $a[1]['kemarin']);
        $this->assertSame(['biaya' => 3500.0, 'habis_jam' => null, 'akhir_jam' => 20], $a[2]['kemarin']);

        $this->actingAs($admin)->get(route('sales.iklan-shopee.kelompok.edit', $k))
            ->assertOk()->assertSee('habis ±11.00')->assertSee('s.d. 20.00');
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

    public function test_halaman_kelompok_simpan_anggota_dan_tolak_mode_asing(): void
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
            'nama' => 'Brosur', 'mode' => 'ngawur', 'aktif_sepi' => 1, 'rotasi_jam' => 3,
        ])->assertSessionHasErrors('mode');
    }
}
