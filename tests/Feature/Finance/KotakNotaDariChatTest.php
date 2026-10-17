<?php

namespace Tests\Feature\Finance;

use App\Models\DocumentAttachment;
use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmAttachment;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nota pemasok yang dikirim lewat WA langsung masuk Kotak Nota dari menu
 * pesan CRM — tanpa unduh lalu unggah ulang (17 Okt 2026).
 */
class KotakNotaDariChatTest extends TestCase
{
    use RefreshDatabase;

    private CrmConversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');

        $this->chat = CrmConversation::create(['channel' => 'whatsapp', 'contact_key' => '6285643038038',
            'display_name' => 'Yoyok Kardus', 'status' => CrmConversation::STATUS_AKTIF, 'unread_count' => 0]);
    }

    private function pesanDenganLampiran(string $mime = 'application/pdf', string $nama = 'TAGIHAN ACRYLIC -03.pdf'): CrmMessage
    {
        $pesan = CrmMessage::create(['conversation_id' => $this->chat->id, 'direction' => 'masuk',
            'message_type' => 'document']);

        $path = 'crm/2026/10/' . uniqid() . '.bin';
        Storage::disk('local')->put($path, '%PDF-1.4 isi nota');

        CrmAttachment::create(['message_id' => $pesan->id, 'disk' => 'local', 'path' => $path,
            'original_name' => $nama, 'mime' => $mime, 'size_bytes' => 18, 'downloaded_at' => now()]);

        return $pesan;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_lampiran_pdf_masuk_kotak_nota(): void
    {
        $pesan = $this->pesanDenganLampiran();

        $this->actingAs($this->admin())->postJson(route('crm.pesan.kotak-nota', $pesan))
            ->assertOk()->assertJson(['success' => true]);

        $nota = DocumentAttachment::sole();
        $this->assertNull($nota->doc_type, 'Masuk Kotak Nota, belum menempel ke dokumen');
        $this->assertSame($pesan->attachments->first()->id, (int) $nota->crm_attachment_id);
        $this->assertStringContainsString('Yoyok Kardus', $nota->catatan);
        $this->assertSame('TAGIHAN ACRYLIC -03.pdf', $nota->original_name);
        Storage::disk('local')->assertExists($nota->path);
    }

    public function test_tidak_masuk_dua_kali(): void
    {
        $pesan = $this->pesanDenganLampiran();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('crm.pesan.kotak-nota', $pesan))->assertOk();
        $this->actingAs($admin)->postJson(route('crm.pesan.kotak-nota', $pesan))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(1, DocumentAttachment::count());
    }

    public function test_lampiran_bukan_foto_atau_pdf_ditolak(): void
    {
        $pesan = $this->pesanDenganLampiran('audio/ogg', 'pesan-suara.ogg');

        $this->actingAs($this->admin())->postJson(route('crm.pesan.kotak-nota', $pesan))->assertStatus(422);

        $this->assertSame(0, DocumentAttachment::count());
    }

    public function test_menu_ke_kotak_nota_muncul_di_gelembung(): void
    {
        $this->pesanDenganLampiran();

        $this->actingAs($this->admin())->get(route('crm.inbox.show', $this->chat))
            ->assertOk()->assertSee('data-bisa-nota="1"', false)->assertSee('Ke Kotak Nota');
    }
}
