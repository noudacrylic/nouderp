<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Modules\CRM\Providers\WahaProvider;
use App\Modules\CRM\Services\WahaHealthService;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use App\Modules\CRM\Support\PeranWaha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Satu container WAHA, dua nomor WhatsApp — dan satu-satunya hal yang
 * memisahkan keduanya adalah NAMA SESI.
 *
 * Yang dijaga di sini semuanya punya bentuk kegagalan yang sama: tidak ada
 * gejala apa pun sampai akibatnya tak bisa dibatalkan lagi. Notifikasi yang
 * berangkat dari nomor utama tidak terlihat salah di layar mana pun — sampai
 * nomor utama diblokir karena kirim-duluan. "Putuskan" yang mengenai peran
 * yang keliru terlihat berhasil — sampai ada yang sadar nomor toko sudah
 * lepas. Karena itu penjagaannya ada di kode, bukan di kehati-hatian orang.
 */
class DuaNomorWahaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function waha(array $sesi = []): CrmSetting
    {
        $waha = CrmSetting::for('waha');

        $waha->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci-waha',
            'base_url'   => 'http://127.0.0.1:3000',
            'config'     => ['sesi' => array_merge([
                'utama'      => ['session' => 'utama'],
                'notifikasi' => ['session' => 'notifikasi'],
            ], $sesi)],
        ])->save();

        return $waha;
    }

    /* ------------------------------------------------------------- pemisahan */

    /**
     * Nomor yang dipegang adapter ditentukan PERAN-nya. Kalau instance yang
     * dibuat untuk nomor utama diam-diam memakai sesi notifikasi, seluruh
     * pemisahan ini cuma hiasan di layar.
     */
    public function test_tiap_peran_memegang_sesinya_sendiri(): void
    {
        $waha = $this->waha(['utama' => ['session' => 'cs-toko']]);

        $this->assertSame('cs-toko', (new WahaProvider($waha, PeranWaha::UTAMA))->sesi());
        $this->assertSame('notifikasi', (new WahaProvider($waha, PeranWaha::NOTIFIKASI))->sesi());
    }

    /** Tanpa peran = notifikasi, supaya app(WahaProvider::class) tetap berarti sama. */
    public function test_peran_bawaan_tetap_notifikasi(): void
    {
        $this->waha();

        $this->assertSame(PeranWaha::NOTIFIKASI, app(WahaProvider::class)->peran());
    }

    /**
     * INTI DARI SELURUH PEMISAHAN INI. Nomor utama tidak boleh mengirim
     * notifikasi — dialah yang risiko blokirnya paling mahal, dan kirim-duluan
     * adalah perbuatan yang paling memicu blokir. Penjagaannya di adapter,
     * bukan di pemanggil: satu salah kabel di lapisan atas sudah cukup
     * membatalkannya, dan salah kabel seperti itu tak bergejala.
     */
    public function test_nomor_utama_menolak_mengirim_notifikasi(): void
    {
        $waha = $this->waha();
        config(['crm.dry_run' => false, 'crm.allowed_recipients' => []]);

        $hasil = (new WahaProvider($waha, PeranWaha::UTAMA))->kirimNotifikasi([
            'to'       => '628998844666',
            'template' => 'pembayaran_diterima',
            'body'     => ['Budi', '150.000', 'SO-1', 'Lunas'],
        ]);

        $this->assertFalse($hasil['success']);
        // Gagal, BUKAN ditahan: salah kabel tidak pulih sendiri, dan barisnya
        // harus berhenti di antrean dengan alasan yang terbaca.
        $this->assertFalse($hasil['tahan']);
        $this->assertStringContainsString('nomor notifikasi', $hasil['error']);

        // Dan yang terpenting: tak satu byte pun sampai ke WhatsApp.
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ layar */

    public function test_layar_menampilkan_kedua_nomor(): void
    {
        $this->waha();

        $this->actingAs($this->admin())
            ->get(route('settings.waha.edit'))
            ->assertOk()
            ->assertSee(PeranWaha::label(PeranWaha::UTAMA))
            ->assertSee(PeranWaha::label(PeranWaha::NOTIFIKASI));
    }

    /**
     * Dua peran menunjuk sesi yang sama = satu nomor memikul kedua peran, dan
     * itu membatalkan seluruh alasan keduanya dipisah. Ditolak, bukan sekadar
     * diperingatkan — akibatnya tidak bisa dibatalkan.
     */
    public function test_nama_sesi_kembar_ditolak(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.waha.update'), [
                'waha_enabled'            => '1',
                'waha_api_key'            => 'kunci-waha',
                'waha_session_utama'      => 'satu-saja',
                'waha_session_notifikasi' => 'satu-saja',
            ])
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'tidak boleh sama'));

        // Tak ada yang tersimpan: penolakan harus membatalkan seluruh simpanan,
        // bukan menyimpan separuh lalu mengeluh. Kunci yang terlanjur masuk
        // sambil sesinya ditolak adalah keadaan setengah jadi yang paling
        // membingungkan — layar bilang gagal, tapi jalurnya sudah hidup.
        $this->assertNull(CrmSetting::for('waha')->api_key);
        $this->assertNotTrue(CrmSetting::for('waha')->is_enabled);
    }

    /**
     * Tombol yang mengenai nomor yang keliru adalah kerusakan paling mahal di
     * layar ini: memutus nomor utama karena mengira sedang mengganti nomor
     * notifikasi tidak bisa dibatalkan dengan menekan tombol lain.
     */
    public function test_tautkan_utama_menyentuh_sesi_utama_saja(): void
    {
        $this->waha();

        Http::fake([
            '*/api/sessions/utama' => Http::response(['status' => 'STOPPED']),
            '*/api/sessions/utama/start' => Http::response(['status' => 'STARTING']),
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.tautkan', ['peran' => PeranWaha::UTAMA]))
            ->assertRedirect(route('settings.waha.edit', ['qr' => PeranWaha::UTAMA]));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'notifikasi'));
    }

    public function test_putuskan_utama_tidak_menyentuh_sesi_notifikasi(): void
    {
        $this->waha();

        Http::fake([
            '*/api/sessions/utama/logout' => Http::response(['success' => true]),
            '*/api/sessions/utama/start'  => Http::response(['success' => true]),
            '*/api/sessions/utama'        => Http::response(['status' => 'STOPPED']),
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.putuskan', ['peran' => PeranWaha::UTAMA]))
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'notifikasi'));
    }

    /**
     * Status satu nomor tidak boleh menimpa status nomor lain. Dulu keduanya
     * berbagi satu kunci datar di akar config, jadi siapa pun yang diperiksa
     * terakhir menang — dan layar menampilkan keadaan nomor yang salah.
     */
    public function test_status_satu_nomor_tidak_menimpa_nomor_lain(): void
    {
        $this->waha(['notifikasi' => ['session' => 'notifikasi', 'last_status' => 'WORKING']]);

        Http::fake(['*/api/sessions/utama' => Http::response(['status' => 'SCAN_QR_CODE'])]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.uji', ['peran' => PeranWaha::UTAMA]));

        $waha = CrmSetting::for('waha');

        $this->assertSame('SCAN_QR_CODE', $waha->sesi(PeranWaha::UTAMA)['last_status']);
        $this->assertSame('WORKING', $waha->sesi(PeranWaha::NOTIFIKASI)['last_status']);
    }

    /** Peran yang tidak dikenal tidak boleh diterima rute sama sekali. */
    public function test_peran_asing_ditolak(): void
    {
        $this->waha();

        $this->actingAs($this->admin())
            ->post('/erp/settings/waha/cadangan/putuskan')
            ->assertNotFound();
    }

    /* -------------------------------------------------------------- heartbeat */

    private function siapkanTelegram(): void
    {
        TelegramSetting::create(['bot_token' => 'token-uji', 'admin_chat_id' => '111', 'is_active' => true]);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'telegram_chat_id' => '222']);
    }

    /**
     * Nomor utama BELUM ditautkan = belum ada yang bisa rusak. Memperingatkannya
     * hanya membuat Telegram berbunyi tanpa ada yang bisa ditindaklanjuti, dan
     * peringatan seperti itu berhenti dibaca justru saat betulan perlu.
     */
    public function test_nomor_utama_belum_tertaut_tidak_dipantau(): void
    {
        $this->waha();
        config(['crm.dry_run' => false]);
        Http::fake();

        $this->assertTrue(app(WahaHealthService::class)->periksa(PeranWaha::UTAMA)['dilewati']);
        Http::assertNothingSent();
    }

    /**
     * Sekali tertaut, diamnya nomor utama WAJIB bersuara — meski belum ada
     * satu fitur pun yang memakainya. Sesi yang menganggur tetap harus hidup;
     * kalau tidak, penautannya sudah batal diam-diam jauh sebelum ada yang
     * menyadarinya.
     */
    public function test_nomor_utama_yang_pernah_tertaut_ikut_dipantau(): void
    {
        $this->waha(['utama' => ['session' => 'utama', 'pernah_tertaut' => true]]);
        config(['crm.dry_run' => false]);
        $this->siapkanTelegram();

        Http::fake([
            '*/api/sessions/utama' => Http::response(['status' => 'SCAN_QR_CODE']),
            '*/auth/qr*'           => Http::response('PNG-PALSU'),
            'api.telegram.org/*'   => Http::response(['ok' => true]),
        ]);

        $hasil = app(WahaHealthService::class)->periksa(PeranWaha::UTAMA);

        $this->assertFalse($hasil['siap']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }

    /**
     * Peringatan WAJIB menyebut nomor MANA dan apa akibatnya. Kedua nomor rusak
     * dengan cara yang sama tapi kehilangan hal yang berbeda: notifikasi punya
     * jalur cadangan berbayar, nomor utama tidak punya pengganti sama sekali.
     * Pesan yang menyebut akibat keliru mengirim orang ke pemulihan yang keliru.
     */
    public function test_peringatan_menyebut_nomor_dan_akibatnya(): void
    {
        $this->waha(['utama' => ['session' => 'utama', 'pernah_tertaut' => true]]);
        config(['crm.dry_run' => false]);
        $this->siapkanTelegram();

        Http::fake([
            '*/api/sessions/utama' => Http::response(['status' => 'SCAN_QR_CODE']),
            '*/auth/qr*'           => Http::response('PNG-PALSU'),
            'api.telegram.org/*'   => Http::response(['ok' => true]),
        ]);

        app(WahaHealthService::class)->periksa(PeranWaha::UTAMA);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'sendMessage')) {
                return false;
            }

            $teks = (string) ($r['text'] ?? '');

            return str_contains($teks, 'Nomor Utama')
                // Akibat milik nomor notifikasi tidak boleh nyasar ke sini.
                && ! str_contains($teks, 'template berbayar');
        });
    }

    /**
     * Rem pengulangan berlaku PER NOMOR. Kalau dipakai bersama, nomor
     * notifikasi yang mati semalam akan membungkam peringatan nomor utama yang
     * putus sejam kemudian — dan yang kedua itu justru yang lebih mendesak.
     */
    public function test_rem_peringatan_satu_nomor_tidak_membungkam_nomor_lain(): void
    {
        $this->waha([
            'utama'      => ['session' => 'utama', 'pernah_tertaut' => true],
            'notifikasi' => ['session' => 'notifikasi', 'alerted_at' => now()->toIso8601String()],
        ]);

        config(['crm.dry_run' => false, 'crm.notifikasi.driver' => 'waha']);
        $this->siapkanTelegram();

        Http::fake([
            '*/api/sessions/*'   => Http::response(['status' => 'SCAN_QR_CODE']),
            '*/auth/qr*'         => Http::response('PNG-PALSU'),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(WahaHealthService::class)->periksaSemua();

        // Nomor utama tetap bersuara meski nomor notifikasi baru saja dikabari.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Nomor Utama'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Nomor Notifikasi'));
    }
}
