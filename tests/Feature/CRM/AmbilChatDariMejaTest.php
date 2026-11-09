<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chat dibuka dari tombol kartu Meja Kerja (?meja=<kartu>) → pemegang kartu otomatis jadi
 * pemilik chat, tanpa oper manual (9 Nov 2026).
 */
class AmbilChatDariMejaTest extends TestCase
{
    use RefreshDatabase;

    private User $saiful;
    private User $farid;
    private CrmConversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saiful = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->farid  = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->chat = CrmConversation::findOrCreateFor('628998844666');
        $this->chat->forceFill(['owner_user_id' => $this->saiful->id, 'last_message_at' => now()])->save();
    }

    private function kartu(User $u, string $status = 'open'): Task
    {
        return Task::create(['title' => 'Desain pesanan', 'status' => $status, 'assignee_user_id' => $u->id, 'creator_user_id' => $u->id, 'skill' => 'desain', 'peran' => 'desain']);
    }

    public function test_pemegang_kartu_membuka_chat_dari_meja_menjadi_pemilik(): void
    {
        $t = $this->kartu($this->farid);

        $this->actingAs($this->farid)->get(route('crm.inbox.show', $this->chat) . '?meja=' . $t->id)
            ->assertRedirect(route('crm.inbox.show', $this->chat))
            ->assertSessionHas('success');

        $this->assertSame($this->farid->id, (int) $this->chat->fresh()->owner_user_id);
    }

    public function test_tanpa_kartu_milik_sendiri_chat_tidak_berpindah(): void
    {
        $milikSaiful = $this->kartu($this->saiful);
        $selesai = $this->kartu($this->farid, 'done');

        // Kartu orang lain (mis. owner melihat meja Farid) atau kartu yang sudah selesai.
        $this->actingAs($this->farid)->get(route('crm.inbox.show', $this->chat) . '?meja=' . $milikSaiful->id)->assertOk();
        $this->actingAs($this->farid)->get(route('crm.inbox.show', $this->chat) . '?meja=' . $selesai->id)->assertOk();
        // Dibuka biasa dari Inbox.
        $this->actingAs($this->farid)->get(route('crm.inbox.show', $this->chat))->assertOk();

        $this->assertSame($this->saiful->id, (int) $this->chat->fresh()->owner_user_id);
    }
}
