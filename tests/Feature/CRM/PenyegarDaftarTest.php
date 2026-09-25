<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyegar kolom kiri: chat baru harus muncul TANPA muat ulang.
 *
 * Ditulis meniru persis apa yang dilakukan pemolling di layar — ambil sidik,
 * lalu tanya lagi dengan sidik itu — karena di situlah letak kegagalannya
 * kalau ada: server yang menjawab `sama: true` padahal ada pesan baru tidak
 * menimbulkan galat apa pun, layarnya cuma diam.
 */
class PenyegarDaftarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_pesan_masuk_baru_mengubah_sidik_daftar(): void
    {
        $admin = $this->admin();
        $p     = CrmConversation::findOrCreateFor('628111222333');
        $p->forceFill(['last_message_at' => now()->subHour()])->save();

        $sidik = $this->actingAs($admin)
            ->getJson(route('crm.inbox.daftar-segar'))
            ->assertOk()->json('sidik');

        // Tak ada yang berubah: server wajib bilang sama.
        $this->actingAs($admin)
            ->getJson(route('crm.inbox.daftar-segar', ['sidik' => $sidik]))
            ->assertOk()->assertJson(['sama' => true]);

        // Pesan masuk baru, persis seperti yang ditulis webhook.
        CrmMessage::create([
            'conversation_id' => $p->id,
            'direction'       => CrmMessage::MASUK,
            'message_type'    => 'text',
            'content'         => 'Halo kak',
            'sent_at'         => now(),
        ]);
        $p->forceFill(['last_message_at' => now(), 'unread_count' => 1])->save();

        $jawab = $this->actingAs($admin)
            ->getJson(route('crm.inbox.daftar-segar', ['sidik' => $sidik]))
            ->assertOk();

        $jawab->assertJson(['sama' => false]);
        $this->assertNotEmpty($jawab->json('html'), 'HTML pengganti wajib ikut');
        $this->assertStringContainsString('Halo kak', $jawab->json('html'));
    }

    /** Percakapan yang BARU LAHIR juga harus memunculkan dirinya. */
    public function test_percakapan_baru_muncul_tanpa_muat_ulang(): void
    {
        $admin = $this->admin();
        CrmConversation::findOrCreateFor('628111222333');

        $sidik = $this->actingAs($admin)
            ->getJson(route('crm.inbox.daftar-segar'))
            ->assertOk()->json('sidik');

        CrmConversation::findOrCreateFor('628999888777');

        $this->actingAs($admin)
            ->getJson(route('crm.inbox.daftar-segar', ['sidik' => $sidik]))
            ->assertOk()->assertJson(['sama' => false]);
    }
}
