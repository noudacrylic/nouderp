<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Services\CrmReplyService;
use App\Modules\CRM\Services\PancinganJendelaService;
use App\Modules\CRM\Support\TemplateResmi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pancingan jendela 24 jam.
 *
 * Yang benar-benar mahal kalau salah di sini adalah HARGA, bukan fungsi.
 * Pancingan yang bunyinya sama persis berangkat GRATIS selagi jendelanya masih
 * terbuka, dan BERBAYAR kalau terlambat semenit. Jadi yang dijaga tes ini
 * terutama satu hal: jalur mana yang dipilih, di keadaan yang mana.
 */
class PancinganJendelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Jam dipaku. Penjadwal ini punya penjaga "jam sopan", jadi tanpa jam
         * yang dipaku hasilnya bergantung pada pukul berapa tesnya kebetulan
         * dijalankan — hijau sepanjang hari kerja, merah kalau dijalankan
         * pagi-pagi buta. Kegagalan seperti itu selalu dikira bug baru.
         */
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        app(ChatManager::class)->fake()->windowOpen = true;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function percakapan(int $sisaMenit, string $nomor = '628998844666'): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);

        $p->forceFill([
            'display_name'      => 'Budi',
            'window_expires_at' => now()->addMinutes($sisaMenit),
            'last_inbound_at'   => now()->subHours(23),
            'last_outbound_at'  => now()->subHours(22),
            'last_message_at'   => now()->subHours(22),
            'status'            => CrmConversation::STATUS_AKTIF,
        ])->save();

        return $p->refresh();
    }

    private function fake()
    {
        return app(ChatManager::class)->fake();
    }

    /* ------------------------------------------------------- pemilihan jalur */

    public function test_jendela_hampir_habis_dipancing_lewat_jalur_gratis_bertombol(): void
    {
        $p = $this->percakapan(30);

        $hasil = app(CrmReplyService::class)->kirimPancingan($p);

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));

        $kirim = $this->fake()->sentOfKind('interaktif');

        $this->assertCount(1, $kirim, 'pancingan di dalam jendela wajib lewat pesan sesi, bukan template berbayar');
        $this->assertSame([], $this->fake()->sentOfKind('template'));
        $this->assertSame(
            TemplateResmi::TOMBOL_PANCINGAN,
            $kirim[0]['interactive']['action']['buttons'][0]['reply']['title']
        );
        $this->assertStringContainsString('Kak Budi', $kirim[0]['text']);
    }

    public function test_jendela_sudah_tutup_terpaksa_lewat_template_berbayar(): void
    {
        $p = $this->percakapan(-10);

        $hasil = app(CrmReplyService::class)->kirimPancingan($p);

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));

        $kirim = $this->fake()->sentOfKind('template');

        $this->assertCount(1, $kirim);
        $this->assertSame(TemplateResmi::TEMPLATE_PANCINGAN, $kirim[0]['template']);
        $this->assertSame([], $this->fake()->sentOfKind('interaktif'));
    }

    public function test_jendela_yang_masih_lapang_tidak_boleh_dipancing(): void
    {
        $p = $this->percakapan(300);

        $hasil = app(CrmReplyService::class)->kirimPancingan($p);

        $this->assertFalse($hasil['success']);
        $this->assertSame([], $this->fake()->sent);
    }

    public function test_percakapan_tanpa_nama_disapa_kak_bukan_nomor_teleponnya(): void
    {
        $p = $this->percakapan(30);
        $p->forceFill(['display_name' => null])->save();

        app(CrmReplyService::class)->kirimPancingan($p->refresh());

        $teks = $this->fake()->sentOfKind('interaktif')[0]['text'];

        $this->assertStringStartsWith('Halo Kak,', $teks);
        $this->assertStringNotContainsString('628998844666', $teks);
    }

    /* ------------------------------------------------------------- penjadwal */

    public function test_penjadwal_mengantrekan_yang_hampir_habis_dan_melewatkan_yang_masih_lama(): void
    {
        $hampir = $this->percakapan(30, '628998844666');
        $lama   = $this->percakapan(600, '628111222333');

        $h = app(PancinganJendelaService::class)->jalankan();

        $this->assertSame(1, $h['diantrekan']);
        $this->assertNotNull($hampir->refresh()->pancingan_untuk_jendela_at);
        $this->assertNull($lama->refresh()->pancingan_untuk_jendela_at);

        // Baris outbox, bukan pengiriman langsung — itu yang memberinya riwayat,
        // saklar, dan pengulangan yang sama dengan notifikasi lain.
        $baris = CrmOutboxMessage::firstOrFail();
        $this->assertSame(CrmOutboxMessage::EVENT_PANCINGAN, $baris->event);
        $this->assertSame($hampir->id, $baris->conversation_id);
        $this->assertSame([], $this->fake()->sent);
    }

    public function test_penjadwal_tidak_mengantrekan_dua_kali_untuk_jendela_yang_sama(): void
    {
        $this->percakapan(30);

        app(PancinganJendelaService::class)->jalankan();
        $kedua = app(PancinganJendelaService::class)->jalankan();

        $this->assertSame(0, $kedua['diantrekan']);
        $this->assertSame(1, CrmOutboxMessage::count());
    }

    public function test_pelanggan_yang_membalas_membuat_pancingan_berikutnya_boleh_berangkat(): void
    {
        $p = $this->percakapan(30);

        app(PancinganJendelaService::class)->jalankan();

        // Pelanggan membalas -> jendela bergeser. Kunci dedupe memuat jendelanya,
        // jadi ia berganti sendiri — itulah satu-satunya hal yang membuka kunci
        // pancingan kedua.
        $p->refresh()->forceFill(['window_expires_at' => now()->addMinutes(45)])->save();

        $this->assertSame(1, app(PancinganJendelaService::class)->jalankan()['diantrekan']);
        $this->assertSame(2, CrmOutboxMessage::count());
    }

    public function test_percakapan_yang_belum_pernah_kita_jawab_tidak_dipancing(): void
    {
        // Lead yang menyapa sekali lalu diam bukan diskusi yang menggantung —
        // yang dibutuhkannya jawaban, bukan tombol.
        $this->percakapan(30)->forceFill(['last_outbound_at' => null])->save();

        $this->assertSame(0, app(PancinganJendelaService::class)->jalankan()['diantrekan']);
    }

    public function test_percakapan_yang_sudah_diarsipkan_tidak_dipancing(): void
    {
        $this->percakapan(30)->forceFill(['status' => 'arsip'])->save();

        $this->assertSame(0, app(PancinganJendelaService::class)->jalankan()['diantrekan']);
    }

    public function test_jenis_yang_dimatikan_tidak_mengantrekan_apa_pun(): void
    {
        // Dimatikan = tidak diantrekan sama sekali. Kalau dicatat 'dilewati',
        // kunci dedupe jendela ini terbakar dan pancingannya tak akan pernah
        // bisa berangkat lagi meski jenisnya dinyalakan semenit kemudian.
        config(['crm.notifikasi.aktif.pancingan' => false]);

        $this->percakapan(30);

        $h = app(PancinganJendelaService::class)->jalankan();

        $this->assertSame(0, $h['diantrekan']);
        $this->assertSame(0, CrmOutboxMessage::count());
    }

    public function test_tidak_memancing_di_tengah_malam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 02:00:00'));

        $this->percakapan(30);

        $h = app(PancinganJendelaService::class)->jalankan();

        $this->assertSame(0, $h['diantrekan']);
        $this->assertSame('di luar jam sopan', $h['dilewati']);
    }

    public function test_jendela_yang_habis_tengah_malam_ditarik_maju_ke_jam_sopan_terakhir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 20:15:00'));

        // Habis pukul 03.00 — pancingan pada jam normalnya (02.00) akan
        // membangunkan orang, jadi ia harus berangkat sekarang.
        $this->percakapan(6 * 60 + 45);

        $this->assertSame(1, app(PancinganJendelaService::class)->jalankan()['diantrekan']);
    }

    /* ------------------------------------------------- pengiriman dari outbox */

    public function test_baris_outbox_berangkat_gratis_selagi_jendela_masih_terbuka(): void
    {
        $this->percakapan(30);

        app(PancinganJendelaService::class)->jalankan();
        app(CrmOutboxSender::class)->kirimSatu(CrmOutboxMessage::firstOrFail());

        $this->assertCount(1, $this->fake()->sentOfKind('interaktif'));
        $this->assertSame(CrmOutboxMessage::STATUS_TERKIRIM, CrmOutboxMessage::firstOrFail()->status);
    }

    public function test_baris_yang_tertunda_sampai_jendelanya_tutup_naik_ke_template_berbayar(): void
    {
        // Persis yang terjadi saat WAHA mati dan barisnya tertahan berjam-jam:
        // jendelanya keburu habis, dan satu-satunya yang sah tinggal template.
        $p = $this->percakapan(30);

        app(PancinganJendelaService::class)->jalankan();

        $p->refresh()->forceFill(['window_expires_at' => now()->subMinute()])->save();

        app(CrmOutboxSender::class)->kirimSatu(CrmOutboxMessage::firstOrFail());

        $this->assertCount(1, $this->fake()->sentOfKind('template'));
        $this->assertSame([], $this->fake()->sentOfKind('interaktif'));
    }

    /* --------------------------------------------- tombol manual di layar chat */

    public function test_tombol_pancingan_di_layar_chat_mencatat_pesannya_ke_thread(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p     = $this->percakapan(30);

        $this->actingAs($admin)
            ->post(route('crm.inbox.pancingan', $p))
            ->assertRedirect();

        $pesan = CrmMessage::where('conversation_id', $p->id)->latest('id')->first();

        $this->assertNotNull($pesan);
        $this->assertSame('interactive', $pesan->message_type);
    }

    /* ----------------------------------------------- tombol terbaca di thread */

    /**
     * Tombolnya ikut TERCATAT, bukan cuma terkirim.
     *
     * Yang dikembalikan vendor hanyalah jawaban atas kiriman, bukan salinan
     * kirimannya. Tanpa penanda sendiri, baris pesan ini tidak punya cara tahu
     * ia bertombol — dan kalimatnya yang berbunyi "silakan tekan tombol di
     * bawah ini" tampil menggantung tanpa tombol.
     */
    public function test_tombol_pancingan_ikut_tercatat_di_barisnya(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p     = $this->percakapan(30);

        $this->actingAs($admin)->post(route('crm.inbox.pancingan', $p))->assertRedirect();

        $pesan = CrmMessage::where('conversation_id', $p->id)->latest('id')->first();

        $this->assertSame([TemplateResmi::TOMBOL_PANCINGAN], $pesan->tombolBalasanCepat());
    }

    /**
     * Dan terbaca di layar. Ini yang membedakan "tombolnya tidak ada" dari
     * "tombolnya ada tapi ERP tidak menggambarnya" — dua gejala yang di mata
     * admin terlihat sama persis.
     */
    public function test_tombol_pancingan_tergambar_di_gelembung_thread(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p     = $this->percakapan(30);

        $this->actingAs($admin)->post(route('crm.inbox.pancingan', $p))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('crm.inbox.show', $p->id))
            ->assertOk()
            ->assertSee(TemplateResmi::TOMBOL_PANCINGAN);
    }

    /**
     * Pesan biasa TIDAK boleh ikut menumbuhkan tombol. Tombol palsu di layar
     * kita berarti admin mengira pelanggan punya jalan pintas membalas,
     * padahal yang diterimanya cuma teks.
     */
    public function test_pesan_biasa_tidak_bertombol(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $p     = $this->percakapan(300);

        $this->actingAs($admin)
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'Baik Kak, kami siapkan.'])
            ->assertRedirect();

        $pesan = CrmMessage::where('conversation_id', $p->id)->latest('id')->first();

        $this->assertSame([], $pesan->tombolBalasanCepat());
    }
}
