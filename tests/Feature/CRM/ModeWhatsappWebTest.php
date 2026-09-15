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
     * Daftar percakapan dibuang (chat masuk tak lewat ERP); penggantinya kotak
     * kontak yang membuka percakapan dari nomor.
     */
    public function test_mode_nyala_mengganti_daftar_dengan_kotak_kontak(): void
    {
        $percakapan = $this->percakapanBerisi();
        $admin      = $this->admin();

        $this->actingAs($admin)->post(route('crm.inbox.mode-wa'));

        $this->actingAs($admin)
            ->get(route('crm.inbox.show', $percakapan))
            ->assertOk()
            ->assertSee('kontakModeWa', false)
            ->assertSee(route('crm.inbox.buka-kontak'), false)
            ->assertSee($percakapan->contact_key)
            ->assertDontSee('Pilih percakapan di sebelah kiri.');
    }

    /* ------------------------------------------------------------ buka kontak */

    public function test_buka_kontak_nomor_baru_membuat_percakapan_tanpa_mengirim(): void
    {
        $r = $this->actingAs($this->admin())
            ->post(route('crm.inbox.buka-kontak'), ['nomor' => '0812-3456-7890']);

        $p = CrmConversation::where('contact_key', '6281234567890')->first();

        $this->assertNotNull($p);
        $r->assertRedirect(route('crm.inbox.show', $p));
        $this->assertSame(0, CrmMessage::count());
    }

    public function test_buka_kontak_nomor_lama_memakai_percakapan_yang_ada(): void
    {
        $lama = $this->percakapanBerisi();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.buka-kontak'), ['nomor' => '+62 899-8844-666'])
            ->assertRedirect(route('crm.inbox.show', $lama));

        $this->assertSame(1, CrmConversation::count());
    }

    public function test_buka_kontak_menautkan_pelanggan_yang_dipilih(): void
    {
        $pelanggan = \App\Models\Customer::create([
            'code' => 'CUST-WA01', 'name' => 'Alfira', 'phone' => '081299990000', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.buka-kontak'), [
                'nomor' => '6281299990000', 'nama' => 'Alfira', 'customer_id' => $pelanggan->id,
            ]);

        $p = CrmConversation::where('contact_key', '6281299990000')->firstOrFail();

        $this->assertSame($pelanggan->id, (int) $p->customer_id);
        // Pelanggan tertaut: nama master yang dipakai, bukan disalin jadi nama manual.
        $this->assertNull($p->name_source);
    }

    /*
     * Alur tombol "＋ Pelanggan": simpan lewat store-ajax lalu buka-kontak
     * dengan id & nomor hasilnya — dua langkah yang sama dengan yang dijalankan
     * browser.
     */
    public function test_tambah_pelanggan_lalu_langsung_terpilih(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('crm.inbox.mode-wa'));

        $this->actingAs($admin)
            ->get(route('crm.inbox.index'))
            ->assertSee('＋ Pelanggan')
            ->assertSee('/erp/customers/store-ajax', false);

        $baru = $this->actingAs($admin)
            ->postJson('/erp/customers/store-ajax', ['name' => 'Alissa WA', 'phone' => '0812 8899 1122'])
            ->assertOk()
            ->json();

        $this->actingAs($admin)
            ->post(route('crm.inbox.buka-kontak'), ['nomor' => $baru['phone'], 'customer_id' => $baru['id']]);

        $p = CrmConversation::where('contact_key', '6281288991122')->firstOrFail();
        $this->assertSame($baru['id'], (int) $p->customer_id);
        $this->assertSame('Alissa WA', $p->namaTampil());
    }

    public function test_pencarian_mode_wa_hanya_menampilkan_pelanggan(): void
    {
        $this->percakapanBerisi(); // lead tanpa pelanggan, nomor 628998844666

        \App\Models\Customer::create([
            'code' => 'CUST-WA02', 'name' => 'Toko Alfira', 'phone' => '081277776666', 'is_active' => true,
        ]);

        $hasil = $this->actingAs($this->admin())
            ->getJson(route('crm.kontak.cari', ['hanya' => 'pelanggan', 'q' => 'a']))
            ->assertOk()
            ->json('hasil');

        $this->assertSame(['pelanggan'], array_values(array_unique(array_column($hasil, 'sumber'))));
        $this->assertSame('Toko Alfira', $hasil[0]['nama']);
    }

    public function test_buka_kontak_menolak_nomor_ngawur(): void
    {
        $this->actingAs($this->admin())
            ->from(route('crm.inbox.index'))
            ->post(route('crm.inbox.buka-kontak'), ['nomor' => '123'])
            ->assertRedirect(route('crm.inbox.index'));

        $this->assertSame(0, CrmConversation::count());
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
