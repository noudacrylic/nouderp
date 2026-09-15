<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode WhatsApp Web: layar kerja tanpa kolom thread.
 *
 * Mode darurat selama Coexistence belum keluar di akun Meta — chat betulan
 * dilayani dari jendela web.whatsapp.com di sebelah, ERP tinggal jadi alat
 * bantu yang isinya disalin ke papan klip.
 *
 * Dua hal yang dijaga di sini, keduanya gagal TANPA GEJALA kalau lepas:
 * kolom thread yang tetap ikut tergambar (mode ini jadi sia-sia, layar tidak
 * muat di setengah monitor), dan sesi mode yang bocor ke PWA CS — satu klik
 * di desktop membuat layar chat di HP orang lain tampak kosong melompong.
 */
class ModeWhatsappWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function percakapanBerisi(): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');

        $p->forceFill([
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
            'queue_state'       => CrmConversation::QUEUE_KITA,
        ])->save();

        CrmMessage::create([
            'conversation_id' => $p->id,
            'direction'       => 'in',
            'message_type'    => 'text',
            'content'         => 'Halo kak, acrylic A3 ready?',
            'sent_at'         => now(),
        ]);

        return $p;
    }

    /* ------------------------------------------------------------------ sakelar */

    public function test_sakelar_menyalakan_lalu_mematikan_mode(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->from(route('crm.inbox.index'))
            ->post(route('crm.inbox.mode-wa'))
            ->assertRedirect(route('crm.inbox.index'));

        $this->assertTrue(session('crm.mode_wa'));

        $this->actingAs($admin)
            ->from(route('crm.inbox.index'))
            ->post(route('crm.inbox.mode-wa'));

        $this->assertFalse(session('crm.mode_wa'));
    }

    public function test_sakelar_butuh_login(): void
    {
        $this->post(route('crm.inbox.mode-wa'))->assertRedirect(route('login'));
    }

    /* ---------------------------------------------------------------- tata letak */

    public function test_mode_mati_menggambar_kolom_thread(): void
    {
        $percakapan = $this->percakapanBerisi();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('Halo kak, acrylic A3 ready?', false)
            ->assertSee('Mode WhatsApp Web');
    }

    /*
     * Yang diperiksa BUKAN sekadar hilangnya kotak ketik, melainkan hilangnya
     * ISI pesan: gelembung yang cuma disembunyikan lewat CSS tetap menyeret
     * seluruh thread berikut lampiran & kutipannya ke kolom yang tak terlihat.
     */
    public function test_mode_nyala_tidak_menggambar_thread_tapi_panel_tetap_hidup(): void
    {
        $percakapan = $this->percakapanBerisi();
        $admin      = $this->admin();

        $this->actingAs($admin)->post(route('crm.inbox.mode-wa'));

        $r = $this->actingAs($admin)->get(route('crm.inbox.show', $percakapan));

        $r->assertOk()
          ->assertDontSee('Halo kak, acrylic A3 ready?', false)
          // Pendengar papan klip pengganti kotak ketik.
          ->assertSee('modeWaCrm', false)
          // Panel kanan utuh: di situlah ongkir, stok, dan Buat SO hidup.
          ->assertSee('Buat SO Draft')
          // Jalan pulang harus selalu terlihat, kalau tidak mode ini jadi kurungan.
          ->assertSee('Kembali ke tampilan chat penuh');
    }

    /*
     * Daftar kontak disembunyikan di balik tombol, bukan dibuang: tanpa dia
     * tidak ada cara memilih kontak, dan tab Pesanan / Buat SO jadi mati.
     */
    public function test_mode_nyala_menyembunyikan_daftar_di_balik_tombol(): void
    {
        $percakapan = $this->percakapanBerisi();
        $admin      = $this->admin();

        $this->actingAs($admin)->post(route('crm.inbox.mode-wa'));

        $this->actingAs($admin)
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('Pilih kontak')
            ->assertSee('x-show="daftar"', false)
            ->assertSee($percakapan->contact_key)
            ->assertDontSee('Pilih percakapan di sebelah kiri.');
    }

    /* --------------------------------------------------------------------- PWA */

    public function test_mode_desktop_tidak_mengosongkan_chat_di_pwa(): void
    {
        $percakapan = $this->percakapanBerisi();

        $admin = $this->admin();
        $admin->forceFill(['pwa_crm' => true])->save();

        $this->actingAs($admin)->post(route('crm.inbox.mode-wa'));

        $this->actingAs($admin)
            ->get(route('cs.thread', $percakapan))
            ->assertOk()
            ->assertSee('Halo kak, acrylic A3 ready?', false);
    }
}
