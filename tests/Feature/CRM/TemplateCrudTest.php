<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Membuat & mengubah template pesan dari layar.
 *
 * Satu daftar, dua macam, dan yang dijaga di sini justru batas di antara
 * keduanya. Template milik kita sendiri bebas diubah kapan saja; yang sudah
 * diajukan ke Meta TIDAK — sejak diajukan, yang benar-benar sampai ke pelanggan
 * adalah salinan milik Meta, dan layar yang membiarkan bodynya disunting akan
 * menampilkan satu kalimat sementara pelanggan menerima kalimat lain.
 */
class TemplateCrudTest extends TestCase
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

    /* ------------------------------------------------------------------ buat */

    public function test_template_milik_sendiri_langsung_bisa_dipakai(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.store'), [
                'title'     => 'Nomor rekening',
                'body'      => 'BCA 1234567890 a.n. Noud Acrylic.',
                'category'  => 'Pembayaran',
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $t = CrmTemplate::where('title', 'Nomor rekening')->firstOrFail();

        $this->assertNull($t->meta_name, 'Tanpa centang Meta, ia tetap milik kita — gratis & bisa diubah.');
        $this->assertFalse($t->bisaBukaChat());
    }

    public function test_template_meta_tersimpan_tapi_belum_diajukan(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.store'), [
                'title'     => 'Sapaan promo desain',
                'body'      => 'Halo Kak {{1}}, kami ingin melanjutkan pembahasan desain {{2}}.',
                'ke_meta'   => '1',
                'meta_name' => 'sapa_desain',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $t = CrmTemplate::where('meta_name', 'sapa_desain')->firstOrFail();

        // Tersimpan ≠ diajukan. Dua langkah, sengaja: bunyi yang sekali
        // disetujui akan dikirim ke ribuan pelanggan dan tak bisa ditarik.
        $this->assertNull($t->meta_status);
        $this->assertCount(0, app(ChatManager::class)->fake()->templateDiajukan);

        // Contoh tiap {{n}} dibuatkan sendiri — vendor MENOLAK pengajuan tanpa
        // mereka, dan memintanya diketik satu per satu cuma menambah langkah
        // yang pasti dilewati.
        $this->assertCount(2, (array) $t->meta_variables);
    }

    public function test_nama_meta_yang_tidak_sah_ditolak(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.store'), [
                'title'     => 'Salah nama',
                'body'      => 'Halo Kak {{1}}.',
                'ke_meta'   => '1',
                'meta_name' => 'Sapa Umum!',
            ])
            ->assertSessionHasErrors('meta_name');

        $this->assertSame(0, CrmTemplate::where('title', 'Salah nama')->count());
    }

    /**
     * Isian milik kita ({nama}, {nomor}) tidak dikenal Meta — yang sampai ke
     * pelanggan adalah kurung kurawal apa adanya. Ditolak di sini, bukan
     * setelah ditolak vendor dengan alasan yang tak menyebut sebabnya.
     */
    public function test_isian_milik_sendiri_ditolak_untuk_template_meta(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.store'), [
                'title'     => 'Campur aduk',
                'body'      => 'Halo {nama}, pesanan Kakak sudah siap.',
                'ke_meta'   => '1',
                'meta_name' => 'campur_aduk',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($p) => str_contains($p, '{nama}'));

        $this->assertSame(0, CrmTemplate::where('title', 'Campur aduk')->count());
    }

    /* ----------------------------------------------------------------- ubah */

    public function test_template_milik_sendiri_boleh_diubah_bunyinya(): void
    {
        $t = CrmTemplate::create(['title' => 'Alamat', 'body' => 'Jl. Lama', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('crm.template.update', $t), [
                'title'     => 'Alamat toko',
                'body'      => 'Jl. Baru No. 7',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('Jl. Baru No. 7', $t->refresh()->body);
    }

    public function test_bunyi_template_yang_sudah_diajukan_terkunci(): void
    {
        $t = CrmTemplate::create([
            'title'       => 'Sapaan uji',
            'body'        => 'Bunyi yang sudah disetujui Meta.',
            'meta_name'   => 'uji_terkunci',
            'meta_status' => CrmTemplate::META_APPROVED,
            'is_active'   => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('crm.template.update', $t), [
                'title'     => 'Sapaan uji (judul baru)',
                'body'      => 'KALIMAT SELUNDUPAN',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $t->refresh();

        // Judul kita sendiri, jadi boleh berubah. Bunyinya milik Meta.
        $this->assertSame('Sapaan uji (judul baru)', $t->title);
        $this->assertSame('Bunyi yang sudah disetujui Meta.', $t->body);
    }

    /* ---------------------------------------------------------------- hapus */

    public function test_template_meta_tidak_bisa_dihapus(): void
    {
        // Menghapus barisnya tidak menghapus templatenya di Meta, dan namanya
        // tetap terpakai — yang tersisa cuma template yang hidup di sana tapi
        // tak bisa lagi dipilih dari mana pun.
        $t = CrmTemplate::create([
            'title'       => 'Sudah di Meta',
            'body'        => 'x',
            'meta_name'   => 'sudah_di_meta',
            'meta_status' => CrmTemplate::META_APPROVED,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('crm.template.destroy', $t))
            ->assertSessionHas('error');

        $this->assertModelExists($t);
    }

    /* -------------------------------------------------------------- ke Meta */

    public function test_mengajukan_mengirim_bunyi_dari_basis_data(): void
    {
        $t = CrmTemplate::create([
            'title'          => 'Sapaan desain',
            'body'           => 'Halo Kak {{1}}, soal desain {{2}}.',
            'meta_name'      => 'sapa_desain',
            'meta_variables' => ['contoh 1', 'contoh 2'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('crm.template.ajukan', $t))
            ->assertRedirect()
            ->assertSessionHas('success');

        $diajukan = app(ChatManager::class)->fake()->templateDiajukan;

        $this->assertCount(1, $diajukan);
        $this->assertSame('sapa_desain', $diajukan[0]['nama']);
        $this->assertSame('Halo Kak {{1}}, soal desain {{2}}.', $diajukan[0]['body']);
        $this->assertSame(CrmTemplate::META_PENDING, $t->refresh()->meta_status);
    }

    public function test_pengajuan_kedua_ditolak_karena_namanya_sudah_terpakai(): void
    {
        $t = CrmTemplate::create([
            'title'       => 'Sudah diajukan',
            'body'        => 'x',
            'meta_name'   => 'sudah_diajukan',
            'meta_status' => CrmTemplate::META_PENDING,
        ]);

        $this->actingAs($this->admin())
            ->post(route('crm.template.ajukan', $t))
            ->assertSessionHas('error');

        $this->assertCount(0, app(ChatManager::class)->fake()->templateDiajukan);
    }

    public function test_template_milik_sendiri_tidak_bisa_diajukan(): void
    {
        $t = CrmTemplate::create(['title' => 'Nomor rekening', 'body' => 'BCA 123']);

        $this->actingAs($this->admin())
            ->post(route('crm.template.ajukan', $t))
            ->assertSessionHas('error');

        $this->assertCount(0, app(ChatManager::class)->fake()->templateDiajukan);
    }

    /* ------------------------------------------------------- pintasan "/" chat */

    public function test_pintasan_chat_hanya_menawarkan_yang_milik_sendiri(): void
    {
        // Template Meta bertebaran {{1}} di dalam bodynya. Disisipkan mentah ke
        // kotak ketik, yang sampai ke pelanggan adalah kalimat berkurung kurawal.
        CrmTemplate::create(['title' => 'Nomor rekening', 'body' => 'BCA 123', 'is_active' => true]);
        CrmTemplate::create([
            'title'       => 'Sapaan bermeta',
            'body'        => 'Halo Kak {{1}}.',
            'meta_name'   => 'sapaan_bermeta',
            'meta_status' => CrmTemplate::META_APPROVED,
            'is_active'   => true,
        ]);

        // Daftarnya hidup di kotak ketik, jadi percakapannya harus dibuka dulu.
        $percakapan = CrmConversation::findOrCreateFor('628998844666');
        $percakapan->forceFill(['window_expires_at' => now()->addHours(5)])->save();

        $html = $this->actingAs($this->admin())
            ->get(route('crm.inbox.show', $percakapan->id))
            ->assertOk()
            ->getContent();

        /*
         * Diperiksa pada MUATAN kotak ketik, bukan pada seluruh halaman.
         * Template bermeta memang sah muncul di layar yang sama — popup
         * "Mulai Chat" justru hanya menawarkan yang bermeta. Yang dijaga di
         * sini cuma satu: ia tidak boleh ikut ke daftar pintasan "/", karena
         * bodynya bertebaran {{1}} dan yang sampai ke pelanggan adalah kalimat
         * berkurung kurawal.
         */
        $this->assertSame(1, preg_match('/komposerCrm\((.*?)\)"/s', $html, $cocok),
            'muatan pintasan "/" tidak ditemukan di kotak ketik');

        $pintasan = html_entity_decode($cocok[1], ENT_QUOTES);

        $this->assertStringContainsString('Nomor rekening', $pintasan);
        $this->assertStringNotContainsString('Sapaan bermeta', $pintasan);
    }
}
