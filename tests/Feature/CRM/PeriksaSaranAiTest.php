<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Agents\AgenPrompt;
use App\Modules\CRM\Models\CrmAgent;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Models\CrmSaranAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pemeriksaan manual saran AI: koreksi tersimpan dan langsung terbaca agen. */
class PeriksaSaranAiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private CrmConversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->chat  = CrmConversation::findOrCreateFor('777', CrmConversation::KANAL_SHOPEE, '101', ['display_name' => 'pembeli_uji']);
    }

    private function saran(string $pesan = 'bisa ukuran 20x30 kak?', array $ubah = []): CrmSaranAi
    {
        $m = CrmMessage::create(['conversation_id' => $this->chat->id, 'direction' => CrmMessage::MASUK, 'message_type' => 'text',
            'content' => $pesan, 'sent_at' => now()]);

        return CrmSaranAi::create($ubah + ['conversation_id' => $this->chat->id, 'untuk_message_id' => $m->id,
            'butir' => 'A1', 'teks' => 'bisa kak', 'status' => CrmSaranAi::BARU]);
    }

    public function test_halaman_menampilkan_saran_belum_diperiksa_dengan_pesan_pembeli(): void
    {
        $this->saran();
        $this->saran('sudah dicek', ['teks' => 'sudah', 'tinjauan' => CrmSaranAi::TEPAT]);

        $this->actingAs($this->owner)->get(route('crm.agen.saran.index'))
            ->assertOk()
            ->assertSee('bisa ukuran 20x30 kak?')
            ->assertSee('bisa kak')
            ->assertDontSee('sudah dicek');

        $this->actingAs($this->owner)->get(route('crm.agen.saran.index', ['periksa' => 'tepat']))
            ->assertOk()->assertSee('sudah dicek')->assertDontSee('bisa ukuran 20x30 kak?');

        $this->actingAs($this->owner)->get(route('crm.agen.index'))->assertOk()->assertSee('Periksa Saran AI');
    }

    public function test_salah_wajib_ada_koreksi_dan_tepat_menghapus_koreksi(): void
    {
        $s = $this->saran();

        $this->actingAs($this->owner)->from(route('crm.agen.saran.index'))
            ->post(route('crm.agen.saran.tinjau', $s), ['tinjauan' => 'salah', 'koreksi' => ''])
            ->assertSessionHasErrors('koreksi');
        $this->assertNull($s->fresh()->tinjauan);

        $this->actingAs($this->owner)->post(route('crm.agen.saran.tinjau', $s), ['tinjauan' => 'salah', 'koreksi' => 'ukuran lain lempar ke owner']);
        $this->assertSame(['salah', 'ukuran lain lempar ke owner', $this->owner->id],
            [$s->fresh()->tinjauan, $s->fresh()->koreksi, $s->fresh()->ditinjau_oleh]);

        $this->actingAs($this->owner)->post(route('crm.agen.saran.tinjau', $s), ['tinjauan' => 'tepat', 'koreksi' => 'abaikan']);
        $this->assertNull($s->fresh()->koreksi);
    }

    public function test_koreksi_masuk_prompt_hanya_untuk_agen_cs_shopee(): void
    {
        $agen = CrmAgent::kode(CrmAgent::CS_SHOPEE);
        $this->assertStringNotContainsString('# Koreksi dari owner', AgenPrompt::system($agen, null));

        $this->saran('bisa ukuran 20x30 kak?', ['tinjauan' => CrmSaranAi::SALAH, 'koreksi' => 'ukuran lain lempar ke owner', 'ditinjau_at' => now()]);

        $teks = AgenPrompt::system($agen->fresh(), null);
        $this->assertStringContainsString('# Koreksi dari owner', $teks);
        $this->assertStringContainsString('bisa ukuran 20x30 kak?', $teks);
        $this->assertStringContainsString('Yang benar menurut owner: ukuran lain lempar ke owner', $teks);

        $lain = new CrmAgent(['kode' => 'penyambutan']);
        $this->assertStringNotContainsString('# Koreksi dari owner', AgenPrompt::system($lain, null));
    }

    public function test_migrasi_mengganti_persona_lama_yang_menyuruh_menyalin_kalimat(): void
    {
        $persona = (string) CrmAgent::kode(CrmAgent::CS_SHOPEE)->persona;

        $this->assertStringNotContainsString('boleh lebih dari 3 baris', $persona);
        $this->assertStringContainsString('Koreksi dari owner', $persona);
        $this->assertStringContainsString('JANGAN PERNAH memberi nomor WhatsApp', $persona);
    }
}
