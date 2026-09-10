<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lapisan akses PWA CRM (`/cs`).
 *
 * Yang dijaga di sini bukan sekadar "layarnya terbuka", melainkan jebakan yang
 * memakan korban saat dirancang: layar chat duduk di `/cs`, tapi SELURUH isinya
 * memanggil `erp/crm/*` yang dijaga EnsureMenuAccess. Akun CS chat-saja bisa
 * lolos ke layarnya lalu menemukan setiap panggilan balik 403 — aplikasi yang
 * tampak hidup dengan isi yang mati, gejala yang sangat sulit dibaca dari HP.
 */
class CrmPwaAccessTest extends TestCase
{
    use RefreshDatabase;

    private function csChatSaja(): User
    {
        // Persis akun yang jadi alasan flag ini ada: role 'user', nol izin menu.
        return User::factory()->create([
            'role'      => 'user',
            'is_active' => true,
            'pwa_crm'   => true,
        ]);
    }

    public function test_akun_cs_chat_saja_bisa_membuka_pwa(): void
    {
        $this->actingAs($this->csChatSaja());

        $this->get('/cs')->assertOk();
        $this->get('/cs/notifikasi')->assertOk();
        $this->get('/cs/profil')->assertOk();
    }

    public function test_akun_cs_chat_saja_bisa_memanggil_endpoint_chat_di_erp(): void
    {
        $this->actingAs($this->csChatSaja());

        // Tanpa jalan lewat di EnsureMenuAccess ini balik 403 dan seluruh
        // aplikasi chat jadi cangkang kosong.
        $this->get('/erp/crm')->assertOk();
    }

    public function test_user_tanpa_flag_dan_tanpa_izin_menu_ditolak(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'user', 'is_active' => true, 'pwa_crm' => false,
        ]));

        $this->get('/cs')->assertRedirect();
        $this->get('/erp/crm')->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/cs')->assertRedirect(route('login'));
    }

    public function test_pemegang_izin_menu_inbox_tetap_boleh_tanpa_dicentang_flag(): void
    {
        $user = User::factory()->create([
            'role' => 'user', 'is_active' => true, 'pwa_crm' => false,
        ]);
        $user->menuPermissions()->create(['menu_key' => 'crm.inbox']);

        $this->actingAs($user);

        $this->get('/cs')->assertOk();
    }

    public function test_akun_cs_chat_saja_mendarat_di_pwa_setelah_login(): void
    {
        $this->actingAs($this->csChatSaja());

        $this->assertSame(url('/cs'), user_landing_url());
    }

    public function test_pemegang_menu_erp_tidak_terlempar_ke_pwa(): void
    {
        // Admin yang dicentang flag ini TIDAK boleh kehilangan ERP-nya saat login.
        $user = User::factory()->create([
            'role' => 'user', 'is_active' => true, 'pwa_crm' => true,
        ]);
        $user->menuPermissions()->create(['menu_key' => 'crm.inbox']);

        $this->actingAs($user);

        $this->assertNotSame(url('/cs'), user_landing_url());
    }

    public function test_service_worker_dilayani_dengan_scope_yang_benar(): void
    {
        // Scope salah = service worker tidak pernah menguasai /cs dan aplikasinya
        // gagal dipasang ke layar utama — tanpa pesan kesalahan apa pun.
        $this->get('/cs/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/cs/');
    }
}
