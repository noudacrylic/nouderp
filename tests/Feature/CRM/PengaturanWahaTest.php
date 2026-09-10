<?php

namespace Tests\Feature\CRM;

use App\Models\CrmSetting;
use App\Models\User;
use App\Modules\CRM\Support\CrmRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Layar Pengaturan → WhatsApp Notifikasi (WAHA), terpisah dari api.co.id.
 *
 * Yang dijaga di sini adalah hal-hal yang kalau salah baru ketahuan setelah
 * notifikasi diam berhari-hari: kredensial yang bocor ke baris vendor lain,
 * kunci yang terhapus karena kolomnya dikosongkan, saran pemulihan yang
 * mengirim orang mengerjakan hal keliru, dan panel QR yang justru me-restart
 * sesi yang sedang sehat.
 */
class PengaturanWahaTest extends TestCase
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
            'waha_enabled'  => '1',
            'waha_api_key'  => 'kunci-waha',
            'waha_base_url' => 'http://127.0.0.1:3000',
            'waha_session'  => 'notifikasi',
        ], $ubah);
    }

    private function siapkanWaha(array $config = []): CrmSetting
    {
        $waha = CrmSetting::for('waha');
        $waha->forceFill([
            'is_enabled' => true,
            'api_key'    => 'kunci-waha',
            'config'     => array_merge(['session' => 'notifikasi'], $config),
        ])->save();

        return $waha;
    }

    public function test_layar_terbuka_tanpa_menyentuh_jaringan(): void
    {
        // WAHA duduk di 127.0.0.1 dan saat container-nya mati panggilan apa pun
        // menggantung sampai timeout — layar ini akan ikut menggantung persis
        // ketika dibuka untuk mencari tahu kenapa notifikasi berhenti.
        // preventStrayRequests() yang menjaganya.
        $this->siapkanWaha(['last_status' => 'WORKING']);

        $this->actingAs($this->admin())
            ->get(route('settings.waha.edit'))
            ->assertOk()
            ->assertSee('WORKING');
    }

    /**
     * Panel QR ikut dirender tanpa memanggil WAHA: gambarnya diambil <img>
     * lewat rute tersendiri, bukan saat halaman disusun. Kalau terbalik,
     * membuka layar ini saat container mati akan menggantung sampai timeout.
     */
    public function test_panel_qr_dirender_tanpa_memanggil_waha(): void
    {
        $this->siapkanWaha();

        $this->actingAs($this->admin())
            ->get(route('settings.waha.edit', ['qr' => 1]))
            ->assertOk()
            ->assertSee(route('settings.waha.qr'))
            ->assertSee('Menunggu dipindai', false);
    }

    /**
     * Kredensial WAHA WAJIB duduk di barisnya sendiri. Kalau menumpang baris
     * api.co.id, mematikan chat resmi ikut mematikan notifikasi — padahal
     * keduanya sengaja dipisah supaya bisa jatuh sendiri-sendiri.
     */
    public function test_kredensial_disimpan_di_baris_sendiri(): void
    {
        CrmSetting::for('apicoid')->forceFill(['api_key' => 'kunci-resmi'])->save();

        $this->actingAs($this->admin())
            ->post(route('settings.waha.update'), $this->isian(['notifikasi_driver' => 'waha']))
            ->assertRedirect(route('settings.waha.edit'));

        $waha = CrmSetting::for('waha');

        $this->assertTrue($waha->is_enabled);
        $this->assertSame('kunci-waha', $waha->api_key);
        $this->assertSame('notifikasi', $waha->config['session']);

        // Baris chat resmi tidak ikut tersentuh kuncinya.
        $this->assertSame('kunci-resmi', CrmSetting::for('apicoid')->api_key);
    }

    /** Kunci dibiarkan kosong = jangan diubah, bukan dihapus. */
    public function test_api_key_kosong_tidak_menghapus_yang_tersimpan(): void
    {
        $this->siapkanWaha();

        $this->actingAs($this->admin())
            ->post(route('settings.waha.update'), $this->isian(['waha_api_key' => '']));

        $this->assertSame('kunci-waha', CrmSetting::for('waha')->api_key);
    }

    /**
     * Pilihan jalur harus benar-benar sampai ke config yang dibaca pengirim.
     * Tersimpan di DB tapi tak pernah dibaca = pengaturan yang berbohong.
     */
    public function test_pilihan_jalur_sampai_ke_config_yang_dibaca_pengirim(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.waha.update'), $this->isian(['notifikasi_driver' => 'waha']));

        CrmRuntimeConfig::apply(true);

        $this->assertSame('waha', config('crm.notifikasi.driver'));
    }

    public function test_jalur_bawaan_tetap_resmi_bila_tidak_dipilih(): void
    {
        $this->actingAs($this->admin())->post(route('settings.waha.update'), $this->isian());

        CrmRuntimeConfig::apply(true);

        $this->assertSame('resmi', config('crm.notifikasi.driver'));
    }

    /**
     * Nilai lama dari zaman layarnya masih menyatu tetap dihormati selama
     * layar WAHA belum pernah disimpan — kalau tidak, jalur yang dulu dipilih
     * diam-diam kembali ke 'resmi' begitu kode ini naik, dan pesan berbayar
     * mengalir tanpa ada yang memutuskannya.
     */
    public function test_pilihan_lama_di_baris_apicoid_masih_dihormati(): void
    {
        CrmSetting::for('apicoid')->forceFill(['config' => ['notifikasi_driver' => 'waha']])->save();

        CrmRuntimeConfig::apply(true);

        $this->assertSame('waha', config('crm.notifikasi.driver'));
    }

    /** Setelah layar WAHA menyimpan, jawabannya cuma boleh ada di satu tempat. */
    public function test_menyimpan_membersihkan_nilai_lama_di_baris_apicoid(): void
    {
        CrmSetting::for('apicoid')->forceFill(['config' => ['notifikasi_driver' => 'waha']])->save();

        $this->actingAs($this->admin())
            ->post(route('settings.waha.update'), $this->isian(['notifikasi_driver' => 'resmi']));

        $this->assertArrayNotHasKey('notifikasi_driver', (array) CrmSetting::for('apicoid')->config);

        CrmRuntimeConfig::apply(true);

        $this->assertSame('resmi', config('crm.notifikasi.driver'));
    }

    public function test_uji_sesi_menjawab_status_sesi(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/api/sessions/*' => Http::response(['status' => 'SCAN_QR_CODE'])]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.uji'))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'SCAN_QR_CODE'));
    }

    /**
     * Kunci ditolak BEDA dari sesi putus, dan sarannya wajib ikut berbeda:
     * yang satu diperbaiki di layar ini, yang satu lagi menuntut orang
     * mengambil HP dan memindai QR. Satu kalimat untuk keduanya mengirim
     * orang ke pekerjaan yang keliru.
     */
    public function test_kunci_yang_ditolak_tidak_disuruh_scan_qr(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/api/sessions/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.uji'))
            ->assertSessionHas('error', function ($p) {
                return str_contains($p, 'KUNCI_DITOLAK')
                    && str_contains($p, 'API Key')
                    && ! str_contains($p, 'pindai QR-nya dari HP');
            });
    }

    /**
     * "Session not found" (404) berarti kunci & alamatnya SUDAH benar. Kalau
     * disamakan dengan "tak terjangkau", orang akan memeriksa terowongan dan
     * container yang sebenarnya sehat — dan tak pernah sampai ke sebab
     * sesungguhnya.
     */
    public function test_sesi_tak_ditemukan_dibedakan_dari_tak_terjangkau(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/api/sessions/*' => Http::response(['message' => 'Session not found'], 404)]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.uji'))
            ->assertSessionHas('error', function ($p) {
                return str_contains($p, 'SESI_TIDAK_ADA')
                    && str_contains($p, 'notifikasi')
                    && ! str_contains($p, 'terowongan');
            });
    }

    public function test_uji_sesi_menolak_saat_belum_dikonfigurasi(): void
    {
        $this->actingAs($this->admin())
            ->post(route('settings.waha.uji'))
            ->assertSessionHas('error', fn ($p) => str_contains($p, 'API Key WAHA'));
    }

    /* ------------------------------------------------------- penautan nomor */

    /**
     * Sesi yang belum pernah ada harus DIBUAT dulu; /start pada nama yang tak
     * dikenal menjawab 404, dan pemasangan nomor pertama kali selalu berakhir
     * dengan galat yang tak menjelaskan apa-apa.
     */
    public function test_tautkan_membuat_sesi_yang_belum_ada(): void
    {
        $this->siapkanWaha();

        Http::fake([
            '*/api/sessions/notifikasi' => Http::response(['message' => 'Session not found'], 404),
            '*/api/sessions'            => Http::response(['name' => 'notifikasi', 'status' => 'STARTING']),
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.tautkan'))
            ->assertRedirect(route('settings.waha.edit', ['qr' => 1]));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/sessions')
            && $r->method() === 'POST'
            && $r['name'] === 'notifikasi');
    }

    /**
     * Sesi yang sedang WORKING TIDAK boleh disentuh: me-restart-nya memutus
     * nomor yang sehat, dan itu persis kerusakan yang tombol ini seharusnya
     * perbaiki.
     */
    public function test_tautkan_tidak_mengganggu_sesi_yang_sedang_working(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/api/sessions/notifikasi' => Http::response(['status' => 'WORKING'])]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.tautkan'))
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/start'));
    }

    /** Ganti nomor = logout dulu; tanpa itu QR baru tak pernah terbit. */
    public function test_putuskan_logout_lalu_menghidupkan_lagi(): void
    {
        $this->siapkanWaha();

        Http::fake([
            '*/api/sessions/notifikasi/logout' => Http::response(['success' => true]),
            '*/api/sessions/notifikasi/start'  => Http::response(['success' => true]),
            '*/api/sessions/notifikasi'        => Http::response(['status' => 'STOPPED']),
        ]);

        $this->actingAs($this->admin())
            ->post(route('settings.waha.putuskan'))
            ->assertRedirect(route('settings.waha.edit', ['qr' => 1]));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/logout'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/start'));
    }

    /**
     * QR disajikan sebagai PNG dan TIDAK boleh di-cache: kodenya berputar tiap
     * ±20 detik, dan gambar basi membuat orang memindai kode kedaluwarsa
     * berkali-kali lalu menyimpulkan fiturnya rusak.
     */
    public function test_qr_disajikan_sebagai_png_tanpa_cache(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/auth/qr*' => Http::response('PNG-PALSU', 200, ['Content-Type' => 'image/png'])]);

        $this->actingAs($this->admin())
            ->get(route('settings.waha.qr'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    /**
     * Sesi yang sudah tertaut memang tidak punya QR. Itu keadaan normal, jadi
     * 404 — bukan 500 yang muncul sebagai halaman galat di tengah layar.
     */
    public function test_qr_menjawab_404_saat_tidak_tersedia(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/auth/qr*' => Http::response('', 422)]);

        $this->actingAs($this->admin())
            ->get(route('settings.waha.qr'))
            ->assertNotFound();
    }

    /**
     * QR = kunci untuk menautkan (atau mencuri) akun WhatsApp perusahaan.
     * Pintunya dikunci ke admin, bukan ke siapa pun yang punya menu Pengaturan.
     */
    public function test_qr_tertutup_untuk_non_admin(): void
    {
        $this->siapkanWaha();

        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);

        $this->actingAs($user)->get(route('settings.waha.qr'))->assertForbidden();
        $this->actingAs($user)->post(route('settings.waha.putuskan'))->assertForbidden();
    }

    /** Panel QR menanyakan status tiap beberapa detik supaya tahu kapan berhenti. */
    public function test_status_menjawab_json_dan_mencatat_hasilnya(): void
    {
        $this->siapkanWaha();

        Http::fake(['*/api/sessions/*' => Http::response(['status' => 'WORKING'])]);

        $this->actingAs($this->admin())
            ->getJson(route('settings.waha.status'))
            ->assertOk()
            ->assertJson(['siap' => true, 'status' => 'WORKING']);

        // Dicatat supaya kartu Integrasi & pita di layar bisa membacanya tanpa
        // menelepon WAHA sendiri.
        $this->assertSame('WORKING', CrmSetting::for('waha')->config['last_status']);
    }
}
