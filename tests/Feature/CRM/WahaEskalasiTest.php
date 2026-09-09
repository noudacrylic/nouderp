<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmOutboxMessage;
use App\Modules\CRM\NotificationManager;
use App\Modules\CRM\Services\CrmOutboxSender;
use App\Modules\CRM\Services\WahaHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 3 WAHA: antrean yang tertahan tidak boleh menggantung selamanya, dan
 * sesi yang putus tidak boleh diam-diam.
 *
 * Dua kerusakan yang diuji di sini sama-sama TANPA GEJALA: ERP tetap rapi,
 * antrean tetap tertib, dan pelanggan tidak menerima apa pun. Itulah sebabnya
 * keduanya butuh penjaga sendiri.
 */
class WahaEskalasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'crm.dry_run'                       => true,
            'crm.notifikasi.driver'             => 'waha',
            'crm.notifikasi.tahan_maks_jam'     => 3,
            'crm.notifikasi.tahan_jeda_menit'   => 10,
            'crm.notifikasi.peringatan_jeda_menit' => 60,
        ]);

        app(ChatManager::class)->fake()->reset();
    }

    private function baris(array $ubah = []): CrmOutboxMessage
    {
        return CrmOutboxMessage::antrekan('uji:' . uniqid(), $ubah + [
            'event'         => CrmOutboxMessage::EVENT_PEMBAYARAN,
            'recipient'     => '628998844666',
            'template_name' => 'pembayaran_diterima',
            'template_body' => ['Budi', '150.000', 'SO-1', 'Lunas'],
            'status'        => CrmOutboxMessage::STATUS_MENUNGGU,
        ]);
    }

    /* --------------------------------------------------------------- eskalasi */

    public function test_penahanan_pertama_mencatat_jam_mulai(): void
    {
        app(NotificationManager::class)->fake()->tahan = true;

        $baris = $this->baris();
        app(CrmOutboxSender::class)->kirimSatu($baris);

        $this->assertNotNull($baris->refresh()->held_since);
    }

    /**
     * Jam mulai dicatat SEKALI. Kalau di-set ulang tiap percobaan, jamnya
     * mundur terus dan batas eskalasi tak pernah tercapai — pesan menggantung
     * selamanya tanpa ada yang sadar.
     */
    public function test_jam_mulai_tidak_mundur_pada_percobaan_berikutnya(): void
    {
        app(NotificationManager::class)->fake()->tahan = true;

        $baris = $this->baris();
        $baris->tandaiTertahan('sesi mati', now()->addMinutes(10));
        $awal = $baris->refresh()->held_since;

        $this->travel(30)->minutes();

        $baris->forceFill(['scheduled_at' => null])->save();
        app(CrmOutboxSender::class)->kirimSatu($baris);

        $this->assertTrue($baris->refresh()->held_since->equalTo($awal), 'held_since tidak boleh diperbarui.');
    }

    public function test_belum_lewat_batas_tetap_ditahan_tanpa_menyentuh_jalur_berbayar(): void
    {
        app(NotificationManager::class)->fake()->tahan = true;

        $baris = $this->baris();
        app(CrmOutboxSender::class)->kirimSatu($baris);

        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $baris->refresh()->status);
        $this->assertEmpty(app(ChatManager::class)->fake()->sentOfKind('template'));
    }

    /**
     * Lewat batas → jalur berbayar. Kabar "pesanan Anda sudah dikirim" yang
     * datang sehari kemudian sama tak bergunanya dengan tidak datang.
     */
    public function test_lewat_batas_dialihkan_ke_template_resmi(): void
    {
        app(NotificationManager::class)->fake()->tahan = true;

        $baris = $this->baris();
        $baris->tandaiTertahan('sesi mati', now());
        $baris->forceFill(['held_since' => now()->subHours(4), 'scheduled_at' => null])->save();

        $this->assertTrue(app(CrmOutboxSender::class)->kirimSatu($baris));

        $baris->refresh();
        $this->assertSame(CrmOutboxMessage::STATUS_TERKIRIM, $baris->status);
        $this->assertNull($baris->held_since, 'Jam tahan harus bersih setelah berangkat.');
        $this->assertStringContainsString('Dialihkan ke template resmi', (string) $baris->reason);

        // Benar-benar lewat jalur template, bukan teks WAHA.
        $terkirim = app(ChatManager::class)->fake()->sentOfKind('template');
        $this->assertCount(1, $terkirim);
        $this->assertSame('pembayaran_diterima', $terkirim[0]['template']);
    }

    /** Kedua jalur mati sekaligus: tetap ditahan, jangan dihanguskan. */
    public function test_eskalasi_yang_ikut_gagal_tetap_ditahan(): void
    {
        app(NotificationManager::class)->fake()->tahan = true;
        app(ChatManager::class)->fake()->failWith = 'Saldo Meta habis';

        $baris = $this->baris();
        $baris->tandaiTertahan('sesi mati', now());
        $baris->forceFill(['held_since' => now()->subHours(4), 'scheduled_at' => null])->save();

        $this->assertFalse(app(CrmOutboxSender::class)->kirimSatu($baris));

        $baris->refresh();
        $this->assertSame(CrmOutboxMessage::STATUS_MENUNGGU, $baris->status);
        $this->assertStringContainsString('Saldo Meta habis', (string) $baris->reason);
        $this->assertNotNull($baris->held_since);
    }

    public function test_pengiriman_berhasil_membersihkan_jam_tahan(): void
    {
        $baris = $this->baris();
        $baris->tandaiTertahan('sesi mati', now());
        $baris->forceFill(['scheduled_at' => null])->save();

        app(NotificationManager::class)->fake()->tahan = false;
        app(CrmOutboxSender::class)->kirimSatu($baris);

        $this->assertNull($baris->refresh()->held_since);
    }

    /* -------------------------------------------------------------- heartbeat */

    private function siapkanWaha(): void
    {
        config(['crm.dry_run' => false]);

        CrmSetting::for('waha')->update([
            'is_enabled' => true,
            'api_key'    => 'kunci-uji',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['session' => 'notifikasi'],
        ]);

        TelegramSetting::create(['bot_token' => 'token-uji', 'admin_chat_id' => '111', 'is_active' => true]);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => '222']);
    }

    public function test_sesi_putus_memicu_peringatan_dan_qr_ke_telegram(): void
    {
        $this->siapkanWaha();

        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['status' => 'SCAN_QR_CODE']),
            '*/auth/qr'                 => Http::response('PNG-PALSU'),
            'api.telegram.org/*'        => Http::response(['ok' => true]),
        ]);

        $hasil = app(WahaHealthService::class)->periksa();

        $this->assertFalse($hasil['siap']);
        $this->assertSame('SCAN_QR_CODE', $hasil['status']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendPhoto'));
    }

    /**
     * Rem pengulangan. Sesi mati semalaman = 300 pesan Telegram, dan
     * peringatan yang terlalu sering berhenti dibaca.
     */
    public function test_peringatan_tidak_diulang_dalam_jeda_yang_sama(): void
    {
        $this->siapkanWaha();

        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['status' => 'STOPPED']),
            '*/sessions/notifikasi/start' => Http::response(['status' => 'STARTING']),
            '*/auth/qr'                 => Http::response('PNG-PALSU'),
            'api.telegram.org/*'        => Http::response(['ok' => true]),
        ]);

        $kesehatan = app(WahaHealthService::class);
        $kesehatan->periksa();
        $kesehatan->periksa();

        $jumlah = 0;
        Http::assertSent(function ($r) use (&$jumlah) {
            if (str_contains($r->url(), 'sendMessage')) {
                $jumlah++;
            }

            return true;
        });

        /*
         * Satu peringatan = satu sendMessage per penerima, dan di sini ada dua
         * (admin_chat_id + satu super_admin yang tertaut). Jadi dua panggilan
         * berarti SATU peringatan; peringatan kedua yang lolos rem akan
         * membuatnya empat.
         */
        $this->assertSame(2, $jumlah, 'Peringatan kedua harus tertahan rem pengulangan.');
    }

    public function test_pulih_dikabarkan_hanya_bila_sebelumnya_bermasalah(): void
    {
        $this->siapkanWaha();

        CrmSetting::for('waha')->update(['config' => ['session' => 'notifikasi', 'last_status' => 'STOPPED']]);

        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['status' => 'WORKING']),
            'api.telegram.org/*'        => Http::response(['ok' => true]),
        ]);

        $this->assertTrue(app(WahaHealthService::class)->periksa()['siap']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    public function test_sesi_sehat_terus_menerus_tidak_mengabari_apa_pun(): void
    {
        $this->siapkanWaha();

        CrmSetting::for('waha')->update(['config' => ['session' => 'notifikasi', 'last_status' => 'WORKING']]);

        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['status' => 'WORKING']),
            'api.telegram.org/*'        => Http::response(['ok' => true]),
        ]);

        app(WahaHealthService::class)->periksa();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
    }

    /**
     * Mode aman = tak ada yang perlu diperingatkan. Notifikasi diserahkan ke
     * driver palsu, jadi sesi mati tidak merugikan siapa pun; memperingatkannya
     * hanya membuat pita menyala terus dan Telegram berbunyi tanpa ada yang
     * bisa ditindaklanjuti — lalu berhenti dipercaya saat betulan rusak.
     */
    public function test_mode_aman_tidak_memantau_maupun_memasang_pita(): void
    {
        $this->siapkanWaha();
        config(['crm.dry_run' => true]);
        Http::fake();

        $this->assertTrue(app(WahaHealthService::class)->periksa()['dilewati']);
        $this->assertNull(app(WahaHealthService::class)->statusTersimpan());
        Http::assertNothingSent();
    }

    /** Jalur resmi yang sedang dipakai tidak perlu dipantau sama sekali. */
    public function test_driver_resmi_dilewati_tanpa_menyentuh_jaringan(): void
    {
        config(['crm.notifikasi.driver' => 'resmi']);
        Http::fake();

        $this->assertTrue(app(WahaHealthService::class)->periksa()['dilewati']);
        Http::assertNothingSent();
    }

    /**
     * Pita di layar membaca hasil pemeriksaan TERSIMPAN. Kalau ia menelepon
     * WAHA sendiri, inbox menggantung sampai timeout justru saat WAHA mati.
     */
    public function test_status_tersimpan_dibaca_tanpa_memanggil_waha(): void
    {
        $this->siapkanWaha();

        CrmSetting::for('waha')->update(['config' => [
            'session'         => 'notifikasi',
            'last_status'     => 'SCAN_QR_CODE',
            'last_checked_at' => now()->toIso8601String(),
        ]]);

        Http::fake();

        $status = app(WahaHealthService::class)->statusTersimpan();

        $this->assertSame('SCAN_QR_CODE', $status['status']);
        $this->assertFalse($status['siap']);
        Http::assertNothingSent();
    }
}
