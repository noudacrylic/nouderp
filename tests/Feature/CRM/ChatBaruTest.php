<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memulai percakapan dari ERP (template) + layar Template Pesan.
 *
 * Aturan yang mendasari seluruh berkas ini: ke nomor yang belum menghubungi
 * kita dalam 24 jam terakhir, pesan bebas SELALU ditolak Meta — tidak peduli
 * disusun manusia atau robot. Jadi jalur satu-satunya adalah template yang
 * sudah disetujui, dan itu yang diuji di sini.
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

    public function test_percakapan_baru_lahir_dari_template(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '0855-777-4446',
                'template' => 'sapa_umum',
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
    }

    /**
     * Jendela 24 jam TIDAK menghalangi — template justru satu-satunya cara
     * membukanya. Kalau penjaga jendela ikut dipasang di sini, fitur ini tidak
     * akan pernah bisa dipakai sama sekali.
     */
    public function test_template_boleh_dikirim_walau_jendela_tertutup(): void
    {
        $percakapan = CrmConversation::findOrCreateFor('628557774446');
        $percakapan->forceFill(['window_expires_at' => now()->subDays(3)])->save();

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => 'sapa_umum',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmMessage::count());
        $this->assertSame(1, CrmConversation::count());
    }

    public function test_template_yang_belum_disetujui_ditolak(): void
    {
        $fake = app(ChatManager::class)->fake();
        $fake->templateList[0]['status'] = 'PENDING';

        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => 'sapa_umum',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, CrmMessage::count());
    }

    public function test_nomor_tidak_masuk_akal_ditolak_sebelum_dikirim(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), ['nomor' => '123', 'template' => 'sapa_umum'])
            ->assertSessionHas('error');

        $this->assertEmpty(app(ChatManager::class)->fake()->sent);
    }

    /**
     * Bunyi template diambil dari daftar vendor, BUKAN dari form. Kalau dari
     * form, siapa pun yang mengubah HTML bisa menyimpan kalimat palsu ke
     * riwayat — thread menampilkan sesuatu yang tak pernah dikirim.
     */
    public function test_bunyi_template_tidak_bisa_dititipkan_lewat_form(): void
    {
        $this->actingAs($this->admin())
            ->post(route('crm.template.mulai'), [
                'nomor'    => '628557774446',
                'template' => 'sapa_umum',
                'body'     => 'kalimat karangan yang tidak pernah dikirim',
            ])
            ->assertRedirect();

        $this->assertStringNotContainsString(
            'kalimat karangan',
            (string) CrmMessage::firstOrFail()->content
        );
    }

    public function test_layar_template_menampilkan_bunyi_yang_disepakati(): void
    {
        $this->actingAs($this->admin())
            ->get(route('crm.template.index'))
            ->assertOk()
            ->assertSee('sapa_umum')
            ->assertSee('pesanan_siap_diambil');
    }

    public function test_form_chat_baru_hanya_menawarkan_template_disetujui(): void
    {
        $fake = app(ChatManager::class)->fake();
        $fake->templateList[] = [
            'id' => 'tpl-2', 'name' => 'masih_ditinjau', 'language' => 'id',
            'category' => 'UTILITY', 'status' => 'PENDING', 'body' => 'x', 'variables' => 0,
        ];

        $this->actingAs($this->admin())
            ->get(route('crm.template.baru'))
            ->assertOk()
            ->assertSee('sapa_umum')
            ->assertDontSee('masih_ditinjau');
    }
}
