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
        $this->assertSame(18, SeoJenis::count());
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

    public function test_sambung_google_oauth_lalu_tarik_dengan_refresh_token(): void
    {
        config(['services.gsc.kredensial' => '']); // tanpa kunci JSON (diblokir kebijakan org)
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($owner)->get(route('store.kata-kunci.index'))->assertOk()->assertSee(route('store.kata-kunci.google.callback'));

        $this->post(route('store.kata-kunci.google.klien'), ['client_id' => 'abc.apps.googleusercontent.com', 'client_secret' => 'rahasia'])->assertSessionHas('success');
        $this->assertNotSame('rahasia', \DB::table('seo_google_akun')->value('client_secret'), 'Secret terenkripsi di DB.');

        $r = $this->get(route('store.kata-kunci.google.sambung'));
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $p);
        $this->assertSame(['offline', 'consent', route('store.kata-kunci.google.callback')], [$p['access_type'], $p['prompt'], $p['redirect_uri']]);
        $this->assertStringContainsString('webmasters.readonly', $p['scope']);

        $idToken = 'x.' . rtrim(strtr(base64_encode(json_encode(['email' => 'owner@noudakrilik.com'])), '+/', '-_'), '=') . '.y';
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['refresh_token' => 'rt-1', 'access_token' => 'at-1', 'id_token' => $idToken])
                ->push(['access_token' => 'at-2']),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:noudakrilik.com']]]),
            'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['rows' => [['keys' => ['box mahar akrilik'], 'clicks' => 2, 'impressions' => 40, 'position' => 5]]]),
            'oauth2.googleapis.com/revoke' => Http::response([]),
        ]);

        // State salah ditolak; state benar → tersambung.
        $this->get(route('store.kata-kunci.google.callback', ['state' => 'palsu', 'code' => 'k']))->assertSessionHas('error');
        $this->get(route('store.kata-kunci.google.sambung'));
        $state = session('seo_google_state');
        $this->get(route('store.kata-kunci.google.callback', ['state' => $state, 'code' => 'kode-1']))->assertSessionHas('success');

        $gsc = app(SearchConsoleService::class);
        $this->assertTrue($gsc->tersambung());
        $this->assertSame('owner@noudakrilik.com', $gsc->emailAkun());
        $this->assertSame(['baris' => 1, 'baru' => 1], $gsc->tarik());
        $this->assertSame(40, SeoKataKunci::where('kata', 'box mahar akrilik')->value('gsc_tayang'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token') && ($req->data()['grant_type'] ?? null) === 'authorization_code' && $req->data()['code'] === 'kode-1');

        $this->post(route('store.kata-kunci.google.putus'))->assertSessionHas('success');
        $this->assertFalse(app(SearchConsoleService::class)->tersambung());
        Http::assertSent(fn ($req) => str_contains($req->url(), '/revoke'));
    }

    public function test_kelompokkan_ai_tanpa_menyentuh_pilihan_manual(): void
    {
        $nm = $this->jenis('Nama Meja');
        $sign = $this->jenis('Akrilik Sign / Papan Nama');
        $a = SeoKataKunci::create(['kata' => 'papan nama akrilik meja', 'sumber' => 'gsc', 'seo_jenis_id' => $sign->id, 'jenis_oleh' => 'alias']);
        $b = SeoKataKunci::create(['kata' => 'akrilik semarang', 'sumber' => 'gsc']);
        $c = SeoKataKunci::create(['kata' => 'kaca film mobil', 'sumber' => 'gsc']);
        $d = SeoKataKunci::create(['kata' => 'papan nama kantor', 'sumber' => 'gsc', 'seo_jenis_id' => $nm->id, 'jenis_oleh' => 'manual']);

        $this->claude->jawaban = [FakeClaudeClient::teks(json_encode(['hasil' => [
            ['kata' => 'papan nama akrilik meja', 'jenis' => 'Nama Meja'],
            ['kata' => 'akrilik semarang', 'jenis' => 'Umum / Toko Akrilik'],
            ['kata' => 'kaca film mobil', 'jenis' => null],
            ['kata' => 'papan nama kantor', 'jenis' => 'Akrilik Sign / Papan Nama'], // tak ditanya, diabaikan
        ]]))];

        $this->assertSame(['diperiksa' => 3, 'berubah' => 2], app(\App\Modules\Seo\Services\KelompokKataKunciService::class)->kelompokkan());
        $this->assertSame([$nm->id, 'ai'], [$a->fresh()->seo_jenis_id, $a->fresh()->jenis_oleh]);
        $this->assertSame('Umum / Toko Akrilik', $b->fresh()->jenis->nama);
        $this->assertSame([null, 'ai'], [$c->fresh()->seo_jenis_id, $c->fresh()->jenis_oleh]);
        $this->assertSame($nm->id, $d->fresh()->seo_jenis_id, 'Pilihan manual tak disentuh.');
        $this->assertStringNotContainsString('papan nama kantor', $this->claude->diterima[0]['messages'][0]['content']);
        $this->assertSame('claude-haiku-5-5', $this->claude->diterima[0]['model']);

        // Yang sudah dijawab AI tidak ditanya lagi.
        $this->assertSame(['diperiksa' => 0, 'berubah' => 0], app(\App\Modules\Seo\Services\KelompokKataKunciService::class)->kelompokkan());

        // Mengubah jenis dari halaman → manual.
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($owner)->put(route('store.kata-kunci.ubah', $c->id), ['seo_jenis_id' => $sign->id]);
        $this->assertSame('manual', $c->fresh()->jenis_oleh);
    }

    public function test_laporan_tampil_tak_diklik_usulan_lalu_pakai_di_produk(): void
    {
        $kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($kunci, $pem);
        $file = tempnam(sys_get_temp_dir(), 'gsc');
        file_put_contents($file, json_encode(['client_email' => 'erp@x.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['services.gsc.kredensial' => $file]);

        $produk = \DB::table('store_products')->insertGetId(['slug' => 'kotak-saran-akrilik', 'name' => 'Kotak Saran Akrilik', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now()]);
        $url = 'https://noudakrilik.com/produk/kotak-saran-akrilik';

        Http::fake(function ($req) use ($url) {
            if (str_contains($req->url(), 'oauth2')) return Http::response(['access_token' => 't']);
            if (str_contains($req->url(), 'searchAnalytics')) {
                $d = $req->data();
                if ($d['dimensions'] === ['page']) return Http::response(['rows' => [
                    ['keys' => [$url], 'impressions' => 692, 'clicks' => 0, 'ctr' => 0, 'position' => 3.4],
                    ['keys' => ['https://noudakrilik.com/'], 'impressions' => 400, 'clicks' => 40, 'ctr' => 0.1, 'position' => 2], // CTR bagus
                    ['keys' => ['https://noudakrilik.com/kategori/x'], 'impressions' => 900, 'clicks' => 0, 'ctr' => 0, 'position' => 40], // terlalu bawah
                ]]);
                return Http::response(['rows' => [['keys' => ['kotak saran'], 'impressions' => 600, 'clicks' => 0, 'position' => 3.1]]]);
            }
            if ($req->url() === $url) return Http::response('<html><head><title>Kotak Saran Akrilik | Noud</title><meta name="description" content="Kotak saran lama"></head></html>');
            return Http::response('', 404);
        });
        $this->claude->jawaban = [FakeClaudeClient::teks(json_encode(['usulan' => [[
            'url' => $url, 'judul' => 'Kotak Saran Akrilik Custom Logo | Noud Acrylic', 'deskripsi' => 'Kotak saran akrilik bening, bisa nama instansi & logo.', 'alasan' => 'kejar "kotak saran"',
        ]]]))];

        $this->assertSame(['halaman' => 1, 'diusulkan' => 1], app(\App\Modules\Seo\Services\SaranHalamanService::class)->susun());
        $s = \App\Modules\Seo\Models\SeoSaranHalaman::sole();
        $this->assertSame(['produk', $produk, 'Kotak Saran Akrilik | Noud', 'Kotak saran lama'], [$s->jenis, $s->store_product_id, $s->judul_sekarang, $s->deskripsi_sekarang]);
        $this->assertSame('kotak saran', $s->kueri[0]['kata']);
        $this->assertStringContainsString('gratis ongkir', $this->claude->diterima[0]['system'], 'Aturan konten (larangan) ikut dikirim.');

        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($owner)->get(route('store.kata-kunci.halaman'))->assertOk()->assertSee('Kotak Saran Akrilik Custom Logo')->assertSee('Pakai di produk');
        $this->post(route('store.kata-kunci.halaman.pakai', $s->id), ['judul' => 'Kotak Saran Akrilik Custom | Noud Acrylic', 'deskripsi' => 'Deskripsi baru.'])->assertSessionHas('success');
        $this->assertSame(['Kotak Saran Akrilik Custom | Noud Acrylic', 'Deskripsi baru.'], array_values((array) \DB::table('store_products')->where('id', $produk)->first(['meta_title', 'meta_description'])));
        $this->assertSame('dipakai', $s->fresh()->status);

        $this->post(route('store.kata-kunci.halaman.susun'))->assertSessionHas('success');
        $this->assertTrue(Cache::has(\App\Modules\Seo\Services\SaranHalamanService::KUNCI_ANTREAN));
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
        $this->assertSame(19, SeoJenis::count());
    }
}
