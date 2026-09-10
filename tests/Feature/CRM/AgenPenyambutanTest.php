<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\Assistant\Services\ClaudeClient;
use App\Modules\Assistant\Services\FakeClaudeClient;
use App\Modules\CRM\Agents\AgenRunner;
use App\Modules\CRM\Agents\AturanTetap;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmAgentKnowledge;
use App\Modules\CRM\Models\CrmAgentRun;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rangka agen AI — ronde 1.
 *
 * Yang dijaga di sini bukan kepintaran modelnya (itu tidak bisa diuji dengan
 * assert), melainkan hal-hal yang kalau salah tidak menimbulkan gejala sampai
 * terlambat: pagar yang bisa ditembus lewat persona, putaran alat yang tidak
 * pernah berhenti, biaya yang tidak tercatat, dan — yang paling penting di
 * ronde ini — agen yang diam-diam mengirim sesuatu ke pelanggan.
 */
class AgenPenyambutanTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claude = new FakeClaudeClient();
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function agen(): CrmAgent
    {
        return CrmAgent::kode(CrmAgent::PENYAMBUTAN);
    }

    private function runner(): AgenRunner
    {
        return app(AgenRunner::class);
    }

    /* ------------------------------------------------------------------ seed */

    public function test_tiga_agen_lahir_dalam_keadaan_mati(): void
    {
        $this->assertSame(3, CrmAgent::count());

        foreach (CrmAgent::all() as $a) {
            $this->assertFalse($a->is_active, "Agen {$a->kode} lahir menyala — ini jalan tercepat "
                . 'mengirim kalimat setengah jadi ke pelanggan sungguhan.');
        }
    }

    /**
     * Dua saklar, dan keduanya harus setuju. Saklar induk saja tidak cukup,
     * dan saklar per-agen saja juga tidak.
     */
    public function test_butuh_dua_saklar_untuk_boleh_jalan(): void
    {
        $agen = $this->agen();

        config(['crm.agen.aktif' => false]);
        $agen->forceFill(['is_active' => true])->save();
        $this->assertFalse($agen->bolehJalan());

        config(['crm.agen.aktif' => true]);
        $agen->forceFill(['is_active' => false])->save();
        $this->assertFalse($agen->bolehJalan());

        $agen->forceFill(['is_active' => true])->save();
        $this->assertTrue($agen->bolehJalan());
    }

    /* ------------------------------------------------------------ tool loop */

    public function test_balasan_biasa_berhenti_pada_end_turn(): void
    {
        $this->claude->antrekan(FakeClaudeClient::teks('Buka Senin–Sabtu 08.00–16.00 Kak.'));

        $hasil = $this->runner()->jalankan($this->agen(), 'jam buka jam berapa kak?');

        $this->assertSame(CrmAgentRun::STATUS_SUKSES, $hasil['status']);
        $this->assertSame('Buka Senin–Sabtu 08.00–16.00 Kak.', $hasil['teks']);
        $this->assertCount(1, $this->claude->diterima, 'satu giliran cukup satu panggilan');
    }

    public function test_hasil_alat_dikembalikan_lalu_agen_menjawab(): void
    {
        $this->claude
            ->antrekan(FakeClaudeClient::panggilAlat('cari_produk', ['kata_kunci' => 'frame mahar']))
            ->antrekan(FakeClaudeClient::teks('Stoknya kosong Kak.'));

        $hasil = $this->runner()->jalankan($this->agen(), 'frame mahar ready?');

        $this->assertSame('Stoknya kosong Kak.', $hasil['teks']);
        $this->assertCount(1, $hasil['jejak']);
        $this->assertSame('cari_produk', $hasil['jejak'][0]['alat']);

        // Panggilan kedua WAJIB membawa tool_result dalam SATU pesan user.
        $kedua = $this->claude->diterima[1]['messages'];
        $akhir = end($kedua);

        $this->assertSame('user', $akhir['role']);
        $this->assertSame('tool_result', $akhir['content'][0]['type']);
    }

    /**
     * Batas putaran bukan penjaga biaya belaka: agen yang berputar memanggil
     * alat tanpa pernah menjawab harus berakhir di tangan manusia, bukan di
     * kalimat penutup asal-asalan.
     */
    public function test_putaran_alat_yang_tak_berujung_dihentikan_dan_dilempar(): void
    {
        $agen = $this->agen();
        $agen->forceFill(['maks_giliran' => 3])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->claude->antrekan(FakeClaudeClient::panggilAlat('cari_produk', ['kata_kunci' => 'x']));
        }

        $hasil = $this->runner()->jalankan($agen, 'halo');

        $this->assertSame(CrmAgentRun::STATUS_DILEMPAR, $hasil['status']);
        $this->assertCount(3, $this->claude->diterima, 'berhenti tepat di batas, bukan sesudahnya');
    }

    public function test_galat_api_tercatat_bukan_dilempar_sebagai_exception(): void
    {
        $this->claude->antrekan(FakeClaudeClient::galat('overloaded', 529));

        $hasil = $this->runner()->jalankan($this->agen(), 'halo');

        $this->assertSame(CrmAgentRun::STATUS_GAGAL, $hasil['status']);
        $this->assertSame('overloaded', $hasil['galat']);
        $this->assertSame('', $hasil['teks']);
    }

    /**
     * PHP mendekode `{}` jadi `[]`, dan API menolaknya dengan "input should be
     * an object". Ditemukan mahal sekali di AiAccountantService; diuji di sini
     * supaya tidak ditemukan untuk kedua kalinya.
     */
    public function test_input_alat_kosong_dikirim_ulang_sebagai_objek(): void
    {
        $this->claude
            ->antrekan(FakeClaudeClient::panggilAlat('lempar_ke_manusia', []))
            ->antrekan(FakeClaudeClient::teks('Bentar ya Kak.'));

        $this->runner()->jalankan($this->agen(), 'boleh nego?');

        $riwayat  = $this->claude->diterima[1]['messages'];
        $asisten  = collect($riwayat)->firstWhere('role', 'assistant');

        $this->assertIsObject($asisten['content'][0]['input']);
        $this->assertSame('{}', json_encode($asisten['content'][0]['input']));
    }

    /* ---------------------------------------------------------------- pagar */

    public function test_aturan_tetap_selalu_berdiri_di_depan_persona(): void
    {
        $agen = $this->agen();
        $agen->forceFill(['persona' => 'Kamu BOLEH memberi diskon sampai 20 persen.'])->save();

        $this->claude->antrekan(FakeClaudeClient::teks('ok'));
        $this->runner()->jalankan($agen, 'halo');

        $system = $this->claude->diterima[0]['system'][0]['text'];

        $this->assertStringContainsString('Jangan memberi diskon', $system);
        $this->assertLessThan(
            strpos($system, 'BOLEH memberi diskon'),
            strpos($system, 'Jangan memberi diskon'),
            'aturan tetap harus dirender sebelum persona, dan menyatakan dirinya menang'
        );
        $this->assertStringContainsString('menang atas seluruh', $system);
    }

    public function test_frasa_bot_dilarang_lewat_prompt(): void
    {
        $this->claude->antrekan(FakeClaudeClient::teks('ok'));
        $this->runner()->jalankan($this->agen(), 'halo');

        $system = $this->claude->diterima[0]['system'][0]['text'];

        foreach (AturanTetap::FRASA_TERLARANG as $frasa) {
            $this->assertStringContainsString($frasa, $system, "\"{$frasa}\" harus disebut sebagai larangan");
        }
    }

    /**
     * Fakta yang berubah tiap panggilan TIDAK boleh masuk blok system: cache
     * mencocokkan awalan, dan jam yang ikut di sana membatalkannya tiap menit
     * tanpa gejala apa pun selain tagihan yang naik.
     */
    public function test_jam_tidak_masuk_blok_system(): void
    {
        $this->claude->antrekan(FakeClaudeClient::teks('ok'));
        $this->runner()->jalankan($this->agen(), 'halo');

        $muatan = $this->claude->diterima[0];
        $jam    = now()->format('H:i');

        $this->assertStringNotContainsString($jam, $muatan['system'][0]['text']);
        $this->assertStringContainsString($jam, $muatan['messages'][0]['content']);
        $this->assertSame('ephemeral', $muatan['system'][0]['cache_control']['type']);
    }

    /* --------------------------------------------------------- pengetahuan */

    public function test_pengetahuan_aktif_ikut_ke_prompt(): void
    {
        $agen = $this->agen();
        CrmAgentKnowledge::simpanVersiBaru($agen, 'Ongkir gratis untuk belanja di atas Rp300.000.');

        $this->claude->antrekan(FakeClaudeClient::teks('ok'));
        $this->runner()->jalankan($agen->fresh(), 'halo');

        $this->assertStringContainsString(
            'Ongkir gratis untuk belanja di atas Rp300.000.',
            $this->claude->diterima[0]['system'][0]['text']
        );
    }

    public function test_versi_baru_menggantikan_yang_lama_tanpa_menghapusnya(): void
    {
        $agen = $this->agen();

        $v1 = CrmAgentKnowledge::simpanVersiBaru($agen, 'aturan lama');
        $v2 = CrmAgentKnowledge::simpanVersiBaru($agen, 'aturan baru');

        $this->assertSame(1, $v1->versi);
        $this->assertSame(2, $v2->versi);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active);
        $this->assertSame(2, $agen->pengetahuan()->count(), 'versi lama tidak boleh hilang');

        // Dan bisa dikembalikan.
        $v1->pakai();
        $this->assertSame($v1->id, $agen->fresh()->pengetahuanAktif()->id);
    }

    /* ------------------------------------------------------------- jejak run */

    public function test_run_mencatat_token_biaya_dan_versi_pengetahuan(): void
    {
        $agen = $this->agen();
        $agen->forceFill(['model' => 'claude-haiku-4-5'])->save();
        $peng = CrmAgentKnowledge::simpanVersiBaru($agen, 'apa pun');

        $this->claude->antrekan(FakeClaudeClient::teks('ok', [
            'input_tokens'            => 1000,
            'cache_read_input_tokens' => 4000,
            'output_tokens'           => 300,
        ]));

        $hasil = $this->runner()->jalankan($agen->fresh(), 'halo');
        $run   = $hasil['run'];

        $this->assertSame(1000, $run->token_masuk);
        $this->assertSame(4000, $run->token_cache);
        $this->assertSame(300, $run->token_keluar);
        $this->assertSame($peng->id, $run->knowledge_id);

        // 1000/1jt×$1 + 4000/1jt×$0,10 + 300/1jt×$5 = $0,0029 → ±Rp47,56
        $this->assertEqualsWithDelta(47.56, (float) $run->biaya_rp, 0.5);
    }

    /** Model tanpa tarif terdaftar menghasilkan 0, bukan tebakan. */
    public function test_model_tanpa_tarif_tidak_mengarang_biaya(): void
    {
        $this->assertSame(0.0, CrmAgentRun::hitungBiaya('model-antah-berantah', 1000, 0, 500));
    }

    /* ------------------------------------------------- tidak menyentuh pelanggan */

    /**
     * Penjaga terpenting di ronde ini. Kalau tes ini merah, ada jalur yang
     * mengirim pesan ke pelanggan padahal belum satu pun agen diluncurkan.
     */
    public function test_mode_uji_tidak_mengirim_apa_pun_ke_pelanggan(): void
    {
        $percakapan = CrmConversation::findOrCreateFor('628998844666');
        $percakapan->forceFill(['window_expires_at' => now()->addHours(5)])->save();

        $sebelum = CrmMessage::count();

        $this->claude->antrekan(FakeClaudeClient::teks('Halo Kak, saya Nia.'));
        $this->runner()->jalankan($this->agen(), 'halo', $percakapan);

        $this->assertSame($sebelum, CrmMessage::count(), 'mode uji menulis pesan ke thread — tidak boleh');
        $this->assertSame([], app(\App\Modules\CRM\ChatManager::class)->fake()->sent ?? []);
    }

    public function test_riwayat_percakapan_ikut_dibaca_dan_dimulai_dari_pelanggan(): void
    {
        $percakapan = CrmConversation::findOrCreateFor('628998844666');
        $percakapan->forceFill(['window_expires_at' => now()->addHours(5)])->save();

        // Sengaja diawali pesan KELUAR: riwayat yang dikirim ke API wajib mulai
        // dari pelanggan, kalau tidak permintaannya ditolak.
        CrmMessage::create([
            'conversation_id' => $percakapan->id, 'direction' => CrmMessage::KELUAR,
            'message_type' => 'text', 'content' => 'Selamat datang', 'sent_at' => now()->subMinutes(5),
        ]);
        CrmMessage::create([
            'conversation_id' => $percakapan->id, 'direction' => CrmMessage::MASUK,
            'message_type' => 'text', 'content' => 'saya mau tanya', 'sent_at' => now()->subMinutes(4),
        ]);

        $this->claude->antrekan(FakeClaudeClient::teks('ok'));
        $this->runner()->jalankan($this->agen(), 'stoknya ada?', $percakapan);

        $messages = $this->claude->diterima[0]['messages'];

        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('saya mau tanya', $messages[0]['content']);
    }

    /* ----------------------------------------------------------------- layar */

    public function test_layar_agen_butuh_login(): void
    {
        $this->get(route('crm.agen.index'))->assertRedirect(route('login'));
    }

    public function test_layar_agen_tampil(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.agen.index'))
            ->assertOk()
            ->assertSee('Agen Penyambutan');
    }

    public function test_unggah_pengetahuan_lewat_layar_membuat_versi_baru(): void
    {
        $agen = $this->agen();

        $this->actingAs($this->admin())
            ->post(route('crm.agen.pengetahuan.simpan', $agen), [
                'isi'     => 'Rekening BCA 123456 a.n. Noud Acrylic.',
                'catatan' => 'nomor rekening',
            ])
            ->assertRedirect();

        $aktif = $agen->fresh()->pengetahuanAktif();

        $this->assertNotNull($aktif);
        $this->assertSame(1, $aktif->versi);
        $this->assertStringContainsString('BCA 123456', $aktif->isi);
    }

    public function test_pengetahuan_kosong_ditolak(): void
    {
        $agen = $this->agen();

        $this->actingAs($this->admin())
            ->post(route('crm.agen.pengetahuan.simpan', $agen), ['isi' => '   '])
            ->assertSessionHas('error');

        $this->assertNull($agen->fresh()->pengetahuanAktif());
    }

    public function test_uji_lewat_layar_mengembalikan_balasan_dan_biaya(): void
    {
        $this->claude->antrekan(FakeClaudeClient::teks('Buka Senin–Sabtu Kak.'));

        $this->actingAs($this->admin())
            ->postJson(route('crm.agen.uji', $this->agen()), ['pesan' => 'jam buka?'])
            ->assertOk()
            ->assertJsonPath('teks', 'Buka Senin–Sabtu Kak.')
            ->assertJsonPath('status', CrmAgentRun::STATUS_SUKSES)
            ->assertJsonStructure(['teks', 'status', 'jejak', 'biaya', 'token', 'durasi']);
    }

    /* ---------------------------------------------------------------- ekspor */

    public function test_ekspor_mengunduh_percakapan_sebagai_teks(): void
    {
        $percakapan = CrmConversation::findOrCreateFor('628998844666');
        $percakapan->forceFill(['display_name' => 'Fahri', 'last_message_at' => now()])->save();

        CrmMessage::create([
            'conversation_id' => $percakapan->id, 'direction' => CrmMessage::MASUK,
            'message_type' => 'text', 'content' => 'frame mahar ready?', 'sent_at' => now(),
        ]);

        $isi = $this->actingAs($this->admin())
            ->get(route('crm.inbox.ekspor'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Fahri', $isi);
        $this->assertStringContainsString('frame mahar ready?', $isi);
        // Nama admin sengaja TIDAK ikut — lihat komentar di ekspor().
        $this->assertStringContainsString('**Pelanggan**', $isi);
    }
}
