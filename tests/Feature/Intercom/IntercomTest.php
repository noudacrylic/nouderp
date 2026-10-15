<?php

namespace Tests\Feature\Intercom;

use App\Models\IntercomCall;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Intercom divisi: suaranya WebRTC di peramban (tak bisa diuji di sini). Yang
 * dijaga: stasiun terbuka untuk akun TANPA menu (stasiun terikat perangkat,
 * bukan hak akun), log panggilan tervalidasi, saluran sinyal menolak akun
 * nonaktif, dan semuanya hilang rapi bila Reverb belum diatur.
 */
class IntercomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'intercom.enabled'             => true,
            'reverb.apps.apps.0.key'       => 'kunci-uji',
            'reverb.apps.apps.0.options'   => ['host' => 'erp.test', 'port' => 443, 'scheme' => 'https'],
        ]);
    }

    private function akunTanpaMenu(): User
    {
        return User::factory()->create(['role' => 'user', 'is_active' => true]);
    }

    public function test_akun_tanpa_menu_bisa_membuka_layar_stasiun(): void
    {
        $this->actingAs($this->akunTanpaMenu())
            ->get(route('intercom.index'))
            ->assertOk()
            ->assertSee('Intercom Divisi')
            ->assertSee('window.INTERCOM_CFG', false)
            ->assertSee('kunci-uji')
            ->assertSee('data-ic-isi="besar"', false);
    }

    public function test_log_panggilan_tersimpan_dengan_waktu_mulai_mundur_sebesar_durasi(): void
    {
        $this->travelTo(now()->setTime(10, 0, 0));
        $user = $this->akunTanpaMenu();

        $this->actingAs($user)
            ->postJson(route('intercom.log'), [
                'dari' => 'admin', 'ke' => 'cnc', 'status' => 'tersambung', 'durasi_detik' => 75,
            ])
            ->assertOk();

        $log = IntercomCall::sole();
        $this->assertSame('admin', $log->dari);
        $this->assertSame('cnc', $log->ke);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(75, $log->durasi_detik);
        $this->assertSame('09:58:45', $log->mulai_at->format('H:i:s'));

        // Riwayat tampil di layar stasiun.
        $this->actingAs($user)->get(route('intercom.index'))->assertSee('Tersambung')->assertSee('01:15');
    }

    public function test_log_menolak_stasiun_asing_status_asing_dan_memanggil_diri_sendiri(): void
    {
        $user = $this->akunTanpaMenu();

        $this->actingAs($user)->postJson(route('intercom.log'), ['dari' => 'admin', 'ke' => 'gudang', 'status' => 'tersambung'])
            ->assertUnprocessable()->assertJsonValidationErrors('ke');
        $this->actingAs($user)->postJson(route('intercom.log'), ['dari' => 'cnc', 'ke' => 'cnc', 'status' => 'tersambung'])
            ->assertUnprocessable()->assertJsonValidationErrors('ke');
        $this->actingAs($user)->postJson(route('intercom.log'), ['dari' => 'admin', 'ke' => 'cnc', 'status' => 'ngobrol'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame(0, IntercomCall::count());
    }

    public function test_tamu_tidak_bisa_mencatat_log(): void
    {
        $this->postJson(route('intercom.log'), ['dari' => 'admin', 'ke' => 'cnc', 'status' => 'tersambung'])
            ->assertUnauthorized();
    }

    public function test_saluran_sinyal_hanya_untuk_akun_aktif(): void
    {
        $saluran = app(BroadcastManager::class)->driver()->getChannels()->get('intercom');
        $this->assertNotNull($saluran, 'Saluran intercom tidak terdaftar di routes/channels.php');

        $this->assertTrue($saluran(User::factory()->make(['is_active' => true])));
        $this->assertFalse($saluran(User::factory()->make(['is_active' => false])));
    }

    public function test_tanpa_reverb_intercom_tidak_dirender_dan_layarnya_404(): void
    {
        config(['intercom.enabled' => false]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('intercom.index'))->assertNotFound();
        $this->actingAs($user)->get('/erp/health')->assertOk()->assertDontSee('INTERCOM_CFG');
    }
}
