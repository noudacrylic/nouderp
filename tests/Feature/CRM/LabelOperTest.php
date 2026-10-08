<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\IncomingWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRM Tahap 2 — label ikut berpindah saat chat dioper (disepakati 8 Okt 2026).
 *
 * Aturannya: label TERAKHIR chat itu di tangan agen tujuan; kalau belum
 * pernah dipegang agen itu, label dasarnya; distributor tetap Distributor.
 */
class LabelOperTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $burhan;
    private User $munif;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();

        $this->admin  = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->burhan = User::factory()->create(['name' => 'Burhan', 'role' => 'admin', 'is_active' => true]);
        $this->munif  = User::factory()->create(['name' => 'Munif', 'role' => 'admin', 'is_active' => true]);

        // Label kerja ini dibuat lewat layar di server, bukan migrasi.
        foreach (['tanya_harga' => 'Tanya Harga', 'cetak' => 'Print', 'desain' => 'Desain'] as $kode => $nama) {
            \App\Modules\CRM\Models\CrmLabel::create(['kode' => $kode, 'nama' => $nama, 'warna' => 'blue', 'urutan' => 10, 'aktif' => true]);
        }

        $this->dasar($this->burhan, 'tanya_harga');
        $this->dasar($this->munif, 'cetak');
    }

    private function dasar(User $u, string $kode): void
    {
        DB::table('crm_label_dasar')->updateOrInsert(['user_id' => $u->id], ['kode' => $kode]);
    }

    private function chat(array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628111000111');
        $p->forceFill($attrs + [
            'status'            => CrmConversation::STATUS_AKTIF,
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
        ])->save();

        return $p->fresh();
    }

    private function oper(CrmConversation $p, ?User $ke): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $ke?->id ?? '']);
    }

    public function test_oper_pertama_memasang_label_dasar_agen_tujuan(): void
    {
        $p = $this->chat();

        $this->oper($p, $this->munif);
        $this->assertSame('cetak', $p->fresh()->queue_state);

        $this->oper($p, $this->burhan);
        $this->assertSame('tanya_harga', $p->fresh()->queue_state);
    }

    /** Pekerjaan yang sudah sampai Desain tidak boleh mundur ke label dasar. */
    public function test_oper_balik_memakai_label_terakhir_di_agen_itu(): void
    {
        $p = $this->chat();

        $this->oper($p, $this->burhan);
        $this->actingAs($this->burhan)->post(route('crm.inbox.antrean', $p->id), ['queue_state' => 'desain']);

        $this->oper($p, $this->munif);
        $this->assertSame('cetak', $p->fresh()->queue_state);

        $this->oper($p, $this->burhan);
        $this->assertSame('desain', $p->fresh()->queue_state);
    }

    public function test_agen_tanpa_label_dasar_tidak_mengubah_label(): void
    {
        $sari = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $p = $this->chat(['queue_state' => 'desain']);

        $this->oper($p, $sari);

        $this->assertSame('desain', $p->fresh()->queue_state);
    }

    public function test_distributor_tetap_berlabel_distributor(): void
    {
        $p = $this->chat(['is_distributor' => true, 'queue_state' => CrmConversation::LABEL_DISTRIBUTOR]);

        $this->oper($p, $this->munif);

        $this->assertSame(CrmConversation::LABEL_DISTRIBUTOR, $p->fresh()->queue_state);
    }

    public function test_dilepas_ke_belum_dioper_kembali_ke_label_awal(): void
    {
        $p = $this->chat();
        $this->oper($p, $this->munif);

        $this->oper($p, null);

        $p->refresh();
        $this->assertNull($p->owner_user_id);
        $this->assertSame($p->labelAwal(), $p->queue_state);
    }

    public function test_ambil_sendiri_juga_memasang_label_dasar(): void
    {
        $p = $this->chat();

        $this->actingAs($this->munif)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $this->munif->id]);

        $this->assertSame('cetak', $p->fresh()->queue_state);
    }

    /** Pesanan baru (chat dibuka lagi ≥ 30 hari) tidak mewarisi label lamanya. */
    public function test_pesanan_baru_tidak_mewarisi_label_lama(): void
    {
        $p = $this->chat();
        $this->oper($p, $this->munif);
        $this->actingAs($this->munif)->post(route('crm.inbox.antrean', $p->id), ['queue_state' => 'selesai']);

        $p->fresh()->forceFill(['status' => CrmConversation::STATUS_ARSIP, 'closed_at' => now()->subDays(40)])->save();

        app(IncomingWebhookService::class)->tangani([
            'event_type' => 'message.received',
            'event_id'   => 'evt-' . uniqid(),
            'timestamp'  => now()->toIso8601String(),
            'data'       => ['customer_phone' => '628111000111', 'text' => 'mau pesan lagi', 'raw' => ['id' => 'wamid.' . uniqid()]],
        ]);

        $this->oper($p, $this->munif);
        $this->assertSame('cetak', $p->fresh()->queue_state);
    }

    /**
     * Sesudah "Ambil chat ini" daftar pindah ke Milik Saya — di Belum dioper
     * chatnya sudah tak ada, dan label barunya tak terlihat di mana pun.
     * Kepala chat juga menampilkan label & pemiliknya.
     */
    public function test_ambil_chat_pindah_ke_milik_saya_dan_label_tampil_di_kepala(): void
    {
        $p = $this->chat();
        $asal = route('crm.inbox.show', $p->id) . '?pemilik=belum&muat=1';

        $this->actingAs($this->munif)
            ->from($asal)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $this->munif->id])
            ->assertRedirect(route('crm.inbox.show', $p->id) . '?pemilik=' . $this->munif->id . '&muat=1');

        $this->actingAs($this->munif)
            ->get(route('crm.inbox.show', $p->id))
            ->assertSeeInOrder(['title="Label">Print<', 'title="Pemegang chat">Munif<'], false);
    }

    /** Label yang digerakkan sistem tidak boleh dihapus walau belum dipakai chat mana pun. */
    public function test_label_sistem_tidak_bisa_dihapus(): void
    {
        $label = \App\Modules\CRM\Models\CrmLabel::where('kode', CrmConversation::LABEL_SELESAI)->first();

        $this->actingAs($this->admin)
            ->delete(route('crm.label.destroy', $label->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('crm_labels', ['id' => $label->id]);
    }

    public function test_label_dasar_disimpan_dari_halaman_label(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.label.dasar'), ['dasar' => [
                $this->burhan->id => 'desain',
                $this->munif->id  => '',
            ]])
            ->assertSessionHas('success');

        $this->assertSame('desain', DB::table('crm_label_dasar')->where('user_id', $this->burhan->id)->value('kode'));
        $this->assertFalse(DB::table('crm_label_dasar')->where('user_id', $this->munif->id)->exists());
    }
}
