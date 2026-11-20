<?php

namespace Tests\Feature\Seo;

use App\Models\User;
use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Assistant\Services\FakeClaudeClient;
use App\Modules\Seo\Models\SeoJenis;
use App\Modules\Seo\Models\SeoKataKunci;
use App\Modules\Seo\Services\RisetKataKunciService;
use App\Modules\Seo\Services\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bank kata kunci SEO (20 Nov 2026): riset AI, Search Console, halaman Store → Kata Kunci. */
class KataKunciTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->claude = new FakeClaudeClient();
        $this->app->instance(ClaudeClient::class, $this->claude);
        Cache::flush();
    }

    private function jenis(string $nama): SeoJenis
    {
        return SeoJenis::where('nama', $nama)->firstOrFail();
    }

    public function test_jenis_awal_terisi_dari_kategori_toko_dan_custom(): void
    {
        $this->assertSame(17, SeoJenis::count());
        $this->assertNotNull(SeoJenis::where('nama', 'Kotak Saran')->first());
        $this->assertNotNull(SeoJenis::where('nama', 'Plakat Akrilik')->whereNotNull('petunjuk')->first());
    }

    public function test_riset_ai_mengisi_bank_tanpa_menimpa_kata_yang_sudah_ada(): void
    {
        $ks = $this->jenis('Kotak Saran');
        SeoKataKunci::create(['kata' => 'kotak saran akrilik', 'sumber' => 'manual', 'niat' => 'beli']);

        // Pencarian web berjalan lama → pause_turn dulu, lalu jawaban JSON.
        $this->claude->jawaban = [
            ['stop_reason' => 'pause_turn', 'content' => [['type' => 'server_tool_use', 'id' => 'x', 'name' => 'web_search', 'input' => ['query' => 'kotak saran']]],
             'usage' => ['input_tokens' => 1000, 'output_tokens' => 50, 'server_tool_use' => ['web_search_requests' => 3]]],
            FakeClaudeClient::teks("Hasil riset:\n```json\n" . json_encode(['kata_kunci' => [
                ['kata' => 'Kotak Saran  Akrilik', 'niat' => 'custom', 'perkiraan' => 'tinggi', 'alasan' => 'judul banyak toko'],
                ['kata' => 'harga kotak saran akrilik', 'niat' => 'beli', 'perkiraan' => 'sedang'],
                ['kata' => 'kotak saran semarang', 'niat' => 'lokal', 'perkiraan' => 'aneh'],
            ]]) . "\n```", ['server_tool_use' => ['web_search_requests' => 2]]),
        ];

        $h = app(RisetKataKunciService::class)->riset($ks);

        $this->assertSame(['baru' => 2, 'ada' => 1, 'cari' => 5], array_intersect_key($h, array_flip(['baru', 'ada', 'cari'])));
        $manual = SeoKataKunci::where('kata', 'kotak saran akrilik')->first();
        $this->assertSame(['manual', 'beli', $ks->id, 'tinggi'], [$manual->sumber, $manual->niat, $manual->seo_jenis_id, $manual->perkiraan], 'Manual tak ditimpa, hanya dilengkapi.');
        $this->assertNull(SeoKataKunci::where('kata', 'kotak saran semarang')->value('perkiraan'), 'Nilai di luar pilihan dibuang.');
        $this->assertSame('riset', SeoKataKunci::where('kata', 'harga kotak saran akrilik')->value('sumber'));

        $ks->refresh();
        $this->assertNotNull($ks->riset_at);
        $this->assertGreaterThan(0, $ks->riset_biaya_rp);

        $kirim = $this->claude->diterima[0];
        $this->assertSame('claude-sonnet-5-5', $kirim['model']);
        $this->assertSame('web_search_20260209', $kirim['tools'][0]['type']);
        $this->assertStringContainsString('Kotak Saran', $kirim['messages'][0]['content']);
        $this->assertCount(2, $this->claude->diterima[1]['messages'], 'pause_turn dilanjutkan dengan isi giliran sebelumnya.');
    }

    public function test_tombol_riset_hanya_menandai_lalu_penjadwal_mengerjakan(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $ks = $this->jenis('Kotak Saran');

        $this->actingAs($owner)->post(route('store.kata-kunci.riset'), ['jenis' => $ks->id])->assertSessionHas('success');
        $this->assertNotNull($ks->fresh()->riset_diminta_at);
        $this->assertSame([], $this->claude->diterima, 'Request web tidak menunggu AI.');

        $this->claude->jawaban = [FakeClaudeClient::teks('{"kata_kunci":[{"kata":"kotak saran kantor","niat":"beli","perkiraan":"sedang"}]}')];
        Artisan::call('seo:riset-kata-kunci', ['--antrean' => true]);
        $this->assertNull($ks->fresh()->riset_diminta_at);
        $this->assertSame($ks->id, SeoKataKunci::where('kata', 'kotak saran kantor')->value('seo_jenis_id'));

        // Jawaban tak terbaca → ringkasan gagal, antrean tidak macet.
        $this->actingAs($owner)->post(route('store.kata-kunci.riset'), ['jenis' => $ks->id]);
        $this->claude->jawaban = [FakeClaudeClient::teks('maaf')];
        Artisan::call('seo:riset-kata-kunci', ['--antrean' => true]);
        $this->assertStringStartsWith('Gagal:', $ks->fresh()->riset_ringkasan);
        $this->assertNull($ks->fresh()->riset_diminta_at);
    }

    public function test_tarik_search_console_mengisi_metrik_dan_mengelompokkan_kata_baru(): void
    {
        $kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($kunci, $pem);
        $file = tempnam(sys_get_temp_dir(), 'gsc');
        file_put_contents($file, json_encode(['client_email' => 'erp@proyek.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.gsc.kredensial' => $file]);

        SeoKataKunci::create(['kata' => 'kotak saran akrilik', 'sumber' => 'riset', 'seo_jenis_id' => $this->jenis('Kotak Saran')->id]);
        SeoKataKunci::create(['kata' => 'kata lama', 'sumber' => 'gsc', 'gsc_tayang' => 99, 'gsc_klik' => 9]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tkn']),
            'www.googleapis.com/webmasters/*' => Http::response(['rows' => [
                ['keys' => ['Kotak Saran Akrilik'], 'clicks' => 4, 'impressions' => 120, 'position' => 7.456],
                ['keys' => ['tempat tissue akrilik'], 'clicks' => 1, 'impressions' => 30, 'position' => 12],
                ['keys' => ['papan nama meja kantor'], 'clicks' => 0, 'impressions' => 8, 'position' => 20],
                ['keys' => ['akrilik murah'], 'clicks' => 0, 'impressions' => 5, 'position' => 30],
            ]]),
        ]);

        $gsc = app(SearchConsoleService::class);
        $this->assertTrue($gsc->tersambung());
        $this->assertSame(['baris' => 4, 'baru' => 3], $gsc->tarik());

        $ks = SeoKataKunci::where('kata', 'kotak saran akrilik')->first();
        $this->assertSame([120, 4, 7.46, 'riset'], [$ks->gsc_tayang, $ks->gsc_klik, $ks->gsc_posisi, $ks->sumber]);
        $this->assertSame($this->jenis('Kotak Tisu')->id, SeoKataKunci::where('kata', 'tempat tissue akrilik')->value('seo_jenis_id'));
        $this->assertSame($this->jenis('Nama Meja')->id, SeoKataKunci::where('kata', 'papan nama meja kantor')->value('seo_jenis_id'), 'Alias terpanjang menang (bukan Papan Nama).');
        $this->assertNull(SeoKataKunci::where('kata', 'akrilik murah')->value('seo_jenis_id'));
        $this->assertNull(SeoKataKunci::where('kata', 'kata lama')->value('gsc_tayang'), 'Angka basi dikosongkan.');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sc-domain%3Anoudakrilik.com/searchAnalytics/query') && $r->hasHeader('Authorization', 'Bearer tkn'));
        $this->assertSame(4, $gsc->status()['baris']);
        @unlink($file);
    }

    public function test_halaman_kata_kunci_tambah_ubah_hapus(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $ks = $this->jenis('Kotak Saran');
        $this->actingAs($owner)->get(route('store.kata-kunci.index'))->assertOk()->assertSee('Kata Kunci SEO')->assertSee('belum disambungkan');

        $this->post(route('store.kata-kunci.simpan'), ['kata' => '  Kotak SARAN Sekolah ', 'seo_jenis_id' => $ks->id])->assertSessionHas('success');
        $this->post(route('store.kata-kunci.simpan'), ['kata' => 'kotak saran sekolah'])->assertSessionHas('error');
        $k = SeoKataKunci::where('kata', 'kotak saran sekolah')->sole();
        $this->assertSame('manual', $k->sumber);

        $this->get(route('store.kata-kunci.index', ['jenis' => $ks->id]))->assertOk()->assertSee('kotak saran sekolah')->assertSee('Riset AI');
        $this->put(route('store.kata-kunci.ubah', $k->id), ['aktif' => 0])->assertSessionHas('success');
        $this->assertFalse($k->fresh()->aktif);
        $this->assertSame($ks->id, $k->fresh()->seo_jenis_id, 'Hanya kolom yang dikirim yang berubah.');
        $this->put(route('store.kata-kunci.ubah', $k->id), ['seo_jenis_id' => ''])->assertSessionHas('success');
        $this->assertNull($k->fresh()->seo_jenis_id);
        $this->get(route('store.kata-kunci.index', ['jenis' => 'tanpa']))->assertOk()->assertSee('kotak saran sekolah');

        $this->delete(route('store.kata-kunci.hapus', $k->id))->assertSessionHas('success');
        $this->assertSame(0, SeoKataKunci::count());

        $this->post(route('store.kata-kunci.jenis.simpan'), ['nama' => 'Akrilik Cetak UV'])->assertRedirect();
        $this->assertSame(18, SeoJenis::count());
    }
}
