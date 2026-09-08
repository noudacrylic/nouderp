<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tahap 6a modul CRM: layar Pengaturan.
 *
 * Yang diuji di sini adalah hal-hal yang kalau salah tidak menimbulkan gejala
 * sampai sudah terlambat: rahasia yang terhapus karena kolomnya dibiarkan
 * kosong, daftar putih yang diam-diam memblokir semua orang karena nomornya
 * tak dinormalkan, dan saklar jangan-kirim yang tersimpan di DB tapi tak
 * pernah sampai ke config yang benar-benar dibaca modul.
 */
class PengaturanCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmRuntimeConfig::forget();
        config(['crm.dry_run' => true]);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function isian(array $ubah = []): array
    {
        return array_merge([
            'is_enabled'                => '1',
            'api_key'                   => 'kunci-rahasia',
            'webhook_secret'            => 'hmac-rahasia',
            'base_url'                  => 'https://chat.api.co.id/api/v1/public',
            'default_phone_number_id'   => '1050188614567883',
            'dry_run'                   => '1',
            'allowed_recipients'        => '',
            'store_hours_text'          => 'Senin–Sabtu 08.00–16.00',
            'store_open_hour'           => '8',
            'store_close_hour'          => '16',
            'max_media_mb'              => '25',
            'attachment_retention_days' => '180',
        ], $ubah);
    }

    public function test_layar_terbuka_tanpa_menyentuh_jaringan_saat_belum_dikonfigurasi(): void
    {
        // Belum ada API key → tidak boleh ada satu pun panggilan keluar,
        // kalau tidak halaman Pengaturan jadi tak bisa dibuka justru saat
        // dibutuhkan (yaitu ketika kredensialnya belum diisi).
        $this->actingAs($this->admin())
            ->get(route('settings.crm.edit'))
            ->assertOk()
            ->assertSee('CRM WhatsApp');
    }

    public function test_menyimpan_kredensial_dan_pengaman(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'allowed_recipients' => "0855-777-4446\n628998844666",
            ]))
            ->assertRedirect(route('settings.crm.edit'));

        $setting = CrmSetting::for('apicoid');

        $this->assertTrue($setting->is_enabled);
        $this->assertSame('kunci-rahasia', $setting->api_key);
        $this->assertSame('hmac-rahasia', $setting->webhook_secret);
        $this->assertSame('1050188614567883', $setting->default_phone_number_id);

        // Nomor dinormalkan SAAT DISIMPAN. Kalau tidak, '0855-777-4446' tak akan
        // pernah cocok dengan '62855…' yang dipakai adapter dan daftar putihnya
        // memblokir persis nomor yang seharusnya diizinkan.
        $this->assertSame(
            ['628557774446', '628998844666'],
            $setting->config['allowed_recipients']
        );
        $this->assertSame(25 * 1024 * 1024, $setting->config['max_media_bytes']);
    }

    public function test_rahasia_dikosongkan_berarti_tidak_diubah(): void
    {
        $awal = CrmSetting::for('apicoid');
        $awal->forceFill(['api_key' => 'kunci-lama', 'webhook_secret' => 'hmac-lama'])->save();

        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'api_key'        => '',
                'webhook_secret' => '',
            ]))
            ->assertRedirect();

        $setting = CrmSetting::for('apicoid');

        $this->assertSame('kunci-lama', $setting->api_key);
        $this->assertSame('hmac-lama', $setting->webhook_secret);
    }

    public function test_pengaturan_menimpa_config_yang_dibaca_modul(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'dry_run'                   => null,
                'allowed_recipients'        => '628998844666',
                'attachment_retention_days' => '90',
                'store_open_hour'           => '9',
            ]))
            ->assertRedirect();

        // Sengaja disegarkan seperti permintaan baru: nilai DB harus menang
        // atas config/env, kalau tidak saklar di layar cuma hiasan.
        config(['crm.dry_run' => true, 'crm.allowed_recipients' => [], 'crm.attachment_retention_days' => 180]);
        CrmRuntimeConfig::forget();
        CrmRuntimeConfig::apply();

        $this->assertFalse(config('crm.dry_run'));
        $this->assertSame(['628998844666'], config('crm.allowed_recipients'));
        $this->assertSame(90, config('crm.attachment_retention_days'));
        $this->assertSame(9, config('crm.store_open_hour'));
        $this->assertFalse(app(ChatManager::class)->isDryRun());
    }

    public function test_mematikan_dry_run_tanpa_daftar_putih_diberi_peringatan(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'dry_run'            => null,
                'allowed_recipients' => '',
            ]))
            ->assertSessionHas('success', fn ($p) => str_contains($p, 'TANPA daftar putih'));
    }

    public function test_uji_koneksi_menolak_jalan_saat_belum_dikonfigurasi(): void
    {
        // preventStrayRequests() aktif: kalau penjaga ini bocor, tesnya gagal
        // karena ada panggilan HTTP yang tak terduga — persis yang diinginkan.
        $this->actingAs($this->admin())
            ->post(route('settings.crm.uji'))
            ->assertSessionHas('error');
    }

    public function test_uji_koneksi_melaporkan_nomor_bisnis(): void
    {
        CrmSetting::for('apicoid')->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci',
        ])->save();

        Http::fake(['*/phone-numbers' => Http::response([
            'success' => true,
            'data'    => [[
                'id' => 'n1', 'phone_number_id' => '111', 'display_phone_number' => '+62 855-7774-446',
                'verified_name' => 'WhatsApp Business', 'is_primary' => true,
            ]],
        ])]);

        $this->actingAs($this->admin())
            ->post(route('settings.crm.uji'))
            ->assertSessionHas('success', fn ($p) => str_contains($p, '+62 855-7774-446'));
    }

    public function test_aktifkan_ulang_hanya_menyentuh_endpoint_yang_mati(): void
    {
        CrmSetting::for('apicoid')->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci',
        ])->save();

        Http::fake([
            '*/webhooks/mati/enable' => Http::response(['success' => true]),
            '*/webhooks'             => Http::response([
                'success' => true,
                'data'    => [
                    ['id' => 'hidup', 'url' => 'https://a.test', 'is_active' => true],
                    ['id' => 'mati',  'url' => 'https://b.test', 'is_active' => false, 'failure_count' => 12],
                ],
            ]),
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.crm.aktifkan-webhook'))
            ->assertSessionHas('success', fn ($p) => str_contains($p, '1 endpoint'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhooks/mati/enable'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/webhooks/hidup/enable'));
    }

    /* ------------------------------------------------------------ jalur WAHA */

    /**
     * Kredensial WAHA WAJIB duduk di barisnya sendiri. Kalau menumpang baris
     * api.co.id, mematikan chat resmi ikut mematikan notifikasi — padahal
     * keduanya sengaja dipisah supaya bisa jatuh sendiri-sendiri.
     */
    public function test_kredensial_waha_disimpan_di_baris_sendiri(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'notifikasi_driver' => 'waha',
                'waha_enabled'      => '1',
                'waha_api_key'      => 'kunci-waha',
                'waha_base_url'     => 'http://127.0.0.1:3000',
                'waha_session'      => 'notifikasi',
            ]))
            ->assertRedirect(route('settings.crm.edit'));

        $waha = CrmSetting::for('waha');

        $this->assertTrue($waha->is_enabled);
        $this->assertSame('kunci-waha', $waha->api_key);
        $this->assertSame('notifikasi', $waha->config['session']);

        // Baris chat resmi tidak ikut tersentuh kuncinya.
        $this->assertSame('kunci-rahasia', CrmSetting::for('apicoid')->api_key);
    }

    /** Kunci dibiarkan kosong = jangan diubah, bukan dihapus. */
    public function test_api_key_waha_kosong_tidak_menghapus_yang_tersimpan(): void
    {
        CrmSetting::for('waha')->update(['api_key' => 'kunci-lama', 'is_enabled' => true]);

        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian([
                'waha_enabled'  => '1',
                'waha_api_key'  => '',
                'waha_base_url' => 'http://127.0.0.1:3000',
            ]));

        $this->assertSame('kunci-lama', CrmSetting::for('waha')->api_key);
    }

    /**
     * Pilihan jalur harus benar-benar sampai ke config yang dibaca pengirim.
     * Tersimpan di DB tapi tak pernah dibaca = pengaturan yang berbohong.
     */
    public function test_pilihan_jalur_sampai_ke_config_yang_dibaca_pengirim(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.update'), $this->isian(['notifikasi_driver' => 'waha']));

        CrmRuntimeConfig::apply(true);

        $this->assertSame('waha', config('crm.notifikasi.driver'));
    }

    public function test_jalur_bawaan_tetap_resmi_bila_tidak_dipilih(): void
    {
        $this->actingAs($this->admin())->post(route('settings.crm.update'), $this->isian());

        CrmRuntimeConfig::apply(true);

        $this->assertSame('resmi', config('crm.notifikasi.driver'));
    }

    public function test_uji_sesi_waha_menjawab_status_sesi(): void
    {
        CrmSetting::for('waha')->update(['api_key' => 'kunci-waha', 'is_enabled' => true]);

        Http::fake(['*/api/sessions/*' => Http::response(['status' => 'SCAN_QR_CODE'])]);

        $this->actingAs($this->admin())
            ->post(route('settings.crm.uji-waha'))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'SCAN_QR_CODE'));
    }

    public function test_uji_sesi_waha_menolak_saat_belum_dikonfigurasi(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.crm.uji-waha'))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'API Key WAHA'));
    }
}
