<?php

namespace Tests\Feature\CRM;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Popup "Mulai Chat" — satu-satunya jalan bicara di LUAR jendela 24 jam.
 *
 * Dua pintu masuknya (tombol ＋ di kepala daftar, dan tombol di kotak
 * jendela-tertutup) memakai popup yang sama, dan popup itu tidak boleh
 * memindahkan halaman: memulai chat selalu terjadi di tengah kerja, dan
 * meninggalkan layar berarti membuang thread yang sedang dibaca beserta
 * seluruh filter kolom kirinya.
 *
 * Yang dijaga di sini bukan tampilannya melainkan daftar kontaknya: nomor yang
 * salah pada template BERBAYAR berarti uang keluar untuk menyapa orang asing,
 * dan itu tidak bisa ditarik kembali.
 */
class MulaiChatPopupTest extends TestCase
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

    private function templateMeta(): CrmTemplate
    {
        return CrmTemplate::create([
            'title'       => 'Pembuka chat',
            'body'        => 'Halo Kak {{1}}, ada yang bisa kami bantu?',
            'meta_name'   => 'pembuka_chat',
            'meta_status' => CrmTemplate::META_APPROVED,
            'is_active'   => true,
        ]);
    }

    /* ------------------------------------------------------------ popup ada */

    /**
     * Popupnya ikut dirender di halaman inbox — bukan ditaut ke halaman lain.
     * Kalau ini merah, tombol ＋ kembali memindahkan halaman tanpa ada yang
     * menyadarinya.
     */
    public function test_popup_mulai_chat_ikut_dirender_di_inbox(): void
    {
        $this->templateMeta();

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertSee('mulaiChatCrm(', false)
            ->assertSee('pembuka_chat');
    }

    /**
     * Yang belum disetujui Meta TIDAK boleh muncul sebagai pilihan: vendor
     * menolaknya saat dikirim, dan gagalnya baru ketahuan sesudah admin
     * mengetik seluruh isian.
     */
    public function test_template_yang_belum_disetujui_tidak_ditawarkan(): void
    {
        CrmTemplate::create([
            'title'       => 'Masih ditinjau',
            'body'        => 'Halo Kak {{1}}.',
            'meta_name'   => 'masih_ditinjau',
            'meta_status' => CrmTemplate::META_PENDING,
            'is_active'   => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertDontSee('masih_ditinjau');
    }

    /* ---------------------------------------------------------- cari kontak */

    public function test_kontak_butuh_login(): void
    {
        $this->getJson(route('crm.kontak.cari', ['q' => 'sumarno']))->assertUnauthorized();
    }

    /**
     * Percakapan lama DAN pelanggan ERP, dua-duanya. Pelanggan yang jendelanya
     * sudah tutup hidup di crm_conversations; yang meninggalkan nomor di toko
     * baru ada di master pelanggan. Menyediakan satu saja memaksa admin
     * menyalin nomor dari layar lain.
     */
    public function test_kontak_menggabungkan_percakapan_dan_pelanggan(): void
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill(['display_name' => 'Fahri', 'last_message_at' => now()])->save();

        Customer::create(['code' => 'CUST-SUM', 'name' => 'Sumarno Toko', 'phone' => '085577744466', 'is_active' => true]);

        $hasil = $this->actingAs($this->admin())
            ->getJson(route('crm.kontak.cari', ['q' => 'a']))
            ->assertOk()
            ->json('hasil');

        $sumber = collect($hasil)->pluck('sumber', 'nama');

        $this->assertSame('chat', $sumber['Fahri'] ?? null);
        $this->assertSame('pelanggan', $sumber['Sumarno Toko'] ?? null);
    }

    /**
     * Nomor pelanggan dinormalkan sebelum dipakai — "0855…" yang dikirim mentah
     * ke Meta adalah pesan berbayar yang tidak sampai ke mana pun.
     */
    public function test_nomor_pelanggan_dinormalkan(): void
    {
        Customer::create(['code' => 'CUST-SUM', 'name' => 'Sumarno Toko', 'phone' => '0855-777-444-66', 'is_active' => true]);

        $hasil = $this->actingAs($this->admin())
            ->getJson(route('crm.kontak.cari', ['q' => 'Sumarno']))
            ->assertOk()
            ->json('hasil');

        $this->assertSame('6285577744466', $hasil[0]['nomor']);
    }

    /**
     * Satu orang, satu baris. Pelanggan ERP yang nomornya sudah punya
     * percakapan tidak boleh muncul dua kali — admin jadi menebak mana yang
     * benar, dan tebakan pada template berbayar itu mahal.
     */
    public function test_kontak_yang_sama_tidak_muncul_dua_kali(): void
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill(['display_name' => 'Fahri', 'last_message_at' => now()])->save();

        Customer::create(['code' => 'CUST-FAH', 'name' => 'Fahri', 'phone' => '08998844666', 'is_active' => true]);

        $hasil = $this->actingAs($this->admin())
            ->getJson(route('crm.kontak.cari', ['q' => 'Fahri']))
            ->assertOk()
            ->json('hasil');

        $this->assertCount(1, $hasil);
        $this->assertSame('chat', $hasil[0]['sumber']);
    }

    /** Pelanggan tanpa nomor tidak ada gunanya di daftar tujuan. */
    public function test_pelanggan_tanpa_nomor_dilewati(): void
    {
        Customer::create(['code' => 'CUST-TN', 'name' => 'Tanpa Nomor', 'phone' => null, 'is_active' => true]);

        $hasil = $this->actingAs($this->admin())
            ->getJson(route('crm.kontak.cari', ['q' => 'Tanpa Nomor']))
            ->assertOk()
            ->json('hasil');

        $this->assertSame([], $hasil);
    }
}
