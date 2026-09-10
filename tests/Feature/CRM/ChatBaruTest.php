<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memulai percakapan ke nomor yang belum menghubungi kita.
 *
 * Satu-satunya jalan sahnya template yang sudah disetujui Meta — pesan bebas ke
 * nomor dingin SELALU ditolak, tak peduli disusun manusia atau robot. Yang
 * dijaga di sini justru batas-batasnya: yang belum disetujui tidak boleh
 * ditawarkan, dan bunyinya tidak boleh berasal dari form.
 */
class ChatBaruTest extends TestCase
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

    /**
     * Template pembuka chat kini baris basis data, bukan entri di kode —
     * manusia yang memilih & mengirimnya, jadi ia boleh disusun dari layar.
     */
    private function template(string $status = CrmTemplate::META_APPROVED): CrmTemplate
    {
        return CrmTemplate::create([
            'title'       => 'Sapaan uji',
            'body'        => 'Selamat siang Kak, kami dari Noud Acrylic. Mohon balas pesan ini ya.',
            // Namanya sendiri, bukan 'sapa_umum': yang itu sudah lahir dari
            // migrasi seed, dan meta_name unik.
            'meta_name'   => 'uji_sapa',
            'meta_status' => $status,
            'is_active'   => true,
        ]);
    }

    public function test_percakapan_baru_lahir_dari_template(): void
    {
        $t = $this->template();

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '0855-777-4446',
                'template' => $t->id,
            ])
            ->assertRedirect();

        // Nomor dinormalkan saat percakapan dibuat, kalau tidak pelanggan yang
        // sama akan punya thread kedua begitu ia membalas.
        $percakapan = CrmConversation::firstOrFail();
        $this->assertSame('628557774446', $percakapan->contact_key);
        $this->assertSame(CrmConversation::QUEUE_PELANGGAN, $percakapan->queue_state);

        $pesan = CrmMessage::firstOrFail();
        $this->assertSame('template', $pesan->message_type);
        $this->assertSame(CrmMessage::KELUAR, $pesan->direction);

        // Yang tercatat di thread adalah BUNYI-nya, bukan sekadar nama template —
        // riwayat yang cuma berisi "sapa_umum" tak berarti apa pun bulan depan.
        $this->assertStringContainsString('Noud Acrylic', (string) $pesan->content);

        $this->assertCount(1, app(ChatManager::class)->fake()->sentOfKind('template'));

        // Yang berangkat ke vendor adalah NAMA META-nya, bukan judul kita.
        $this->assertSame('uji_sapa', app(ChatManager::class)->fake()->sentOfKind('template')[0]['template']);
    }

    /**
     * Jendela 24 jam TIDAK menghalangi — template justru satu-satunya cara
     * membukanya. Kalau penjaga jendela ikut dipasang di sini, fitur ini tidak
     * akan pernah bisa dipakai sama sekali.
     */
    public function test_template_boleh_dikirim_walau_jendela_tertutup(): void
    {
        $t = $this->template();

        $percakapan = CrmConversation::findOrCreateFor('628557774446');
        $percakapan->forceFill(['window_expires_at' => now()->subDays(3)])->save();

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => $t->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(1, CrmConversation::count());
    }

    public function test_template_yang_belum_disetujui_ditolak(): void
    {
        $t = $this->template(CrmTemplate::META_PENDING);

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => $t->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, CrmMessage::count());
    }

    /**
     * Template milik kita sendiri (tanpa nama Meta) tidak bisa dipakai membuka
     * chat sama sekali — gratis, tapi hanya sah di dalam jendela 24 jam.
     */
    public function test_template_tanpa_nama_meta_tidak_bisa_membuka_chat(): void
    {
        $t = CrmTemplate::create([
            'title'     => 'Nomor rekening',
            'body'      => 'BCA 1234567890 a.n. Noud Acrylic.',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => $t->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_nomor_tidak_masuk_akal_ditolak_sebelum_dikirim(): void
    {
        $t = $this->template();

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), ['nomor' => '123', 'template' => $t->id])
            ->assertSessionHas('error');

        $this->assertEmpty(app(ChatManager::class)->fake()->sent);
    }

    /**
     * Bunyi template diambil dari barisnya di basis data, BUKAN dari form.
     * Kalau dari form, siapa pun yang mengubah HTML bisa menyimpan kalimat
     * palsu ke riwayat — thread menampilkan sesuatu yang tak pernah dikirim.
     */
    public function test_bunyi_template_tidak_bisa_dititipkan_lewat_form(): void
    {
        $t = $this->template();

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => $t->id,
                'body'     => 'kalimat karangan yang tidak pernah dikirim',
            ])
            ->assertRedirect();

        $this->assertStringNotContainsString(
            'kalimat karangan',
            (string) CrmMessage::firstOrFail()->content
        );
    }

    public function test_layar_template_menampilkan_yang_tersimpan(): void
    {
        $this->template();

        CrmTemplate::create([
            'title'     => 'Nomor rekening',
            'body'      => 'BCA 1234567890 a.n. Noud Acrylic.',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.template.index'))
            ->assertOk()
            ->assertSee('Sapaan uji')
            ->assertSee('Nomor rekening')
            // Penandanya wajib terbaca: satu daftar berisi dua macam, dan yang
            // memilih salah akan ditolak API tanpa tahu sebabnya.
            ->assertSee('balasan cepat');
    }

    public function test_form_chat_baru_hanya_menawarkan_template_disetujui(): void
    {
        $this->template();

        CrmTemplate::create([
            'title'       => 'Masih ditinjau',
            'body'        => 'x',
            'meta_name'   => 'masih_ditinjau',
            'meta_status' => CrmTemplate::META_PENDING,
            'is_active'   => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('crm.template.baru'))
            ->assertOk()
            ->assertSee('uji_sapa')
            ->assertDontSee('masih_ditinjau');
    }
}
