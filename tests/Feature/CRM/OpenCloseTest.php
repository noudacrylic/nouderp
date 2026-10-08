<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\IncomingWebhookService;
use App\Modules\CRM\Services\TutupOtomatisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM Tahap 1 — chat Open / Close (disepakati 8 Okt 2026).
 *
 * Yang dijaga: antrean "Belum dioper" hanya berisi pekerjaan yang benar-benar
 * belum dipegang, chat lama kembali ke orang yang tepat saat pelanggan menulis
 * lagi, dan agen tidak bisa menjawab chat yang sedang dipegang rekannya.
 */
class OpenCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
    }

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function chat(string $nomor = '628111000111', array $attrs = []): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor($nomor);

        $p->forceFill($attrs + [
            'status'            => CrmConversation::STATUS_AKTIF,
            'window_expires_at' => now()->addHours(5),
            'last_message_at'   => now(),
            'unread_count'      => 0,
        ])->save();

        return $p->fresh();
    }

    private function pesanMasuk(string $nomor = '628111000111'): void
    {
        app(IncomingWebhookService::class)->tangani([
            'event_type' => 'message.received',
            'event_id'   => 'evt-' . uniqid(),
            'timestamp'  => now()->toIso8601String(),
            'data'       => [
                'customer_phone' => $nomor,
                'text'           => 'halo kak',
                'raw'            => ['id' => 'wamid.' . uniqid()],
            ],
        ]);
    }

    /* ------------------------------------------------------ buka kembali */

    public function test_close_kurang_30_hari_kembali_ke_pemilik_dan_label(): void
    {
        $munif = $this->user();
        $p = $this->chat(attrs: [
            'status' => CrmConversation::STATUS_ARSIP, 'owner_user_id' => $munif->id, 'queue_state' => 'cetak', 'closed_at' => now()->subDays(10),
        ]);

        $this->pesanMasuk();
        $p->refresh();

        $this->assertTrue($p->terbuka());
        $this->assertSame($munif->id, $p->owner_user_id);
        $this->assertSame('cetak', $p->queue_state);
        $this->assertNull($p->closed_at);
    }

    public function test_close_30_hari_lebih_jadi_pesanan_baru_di_belum_dioper(): void
    {
        $p = $this->chat(attrs: [
            'status' => CrmConversation::STATUS_ARSIP, 'owner_user_id' => $this->user()->id, 'queue_state' => 'cetak', 'closed_at' => now()->subDays(31),
        ]);

        $this->pesanMasuk();
        $p->refresh();

        $this->assertTrue($p->terbuka());
        $this->assertNull($p->owner_user_id);
        $this->assertSame($p->labelAwal(), $p->queue_state);
    }

    public function test_distributor_selalu_kembali_ke_pemilik_terakhir(): void
    {
        $burhan = $this->user();
        $p = $this->chat(attrs: [
            'status' => CrmConversation::STATUS_ARSIP, 'owner_user_id' => $burhan->id, 'is_distributor' => true,
            'queue_state' => CrmConversation::LABEL_DISTRIBUTOR, 'closed_at' => now()->subDays(90),
        ]);

        $this->pesanMasuk();
        $p->refresh();

        $this->assertTrue($p->terbuka());
        $this->assertSame($burhan->id, $p->owner_user_id);
        $this->assertSame(CrmConversation::LABEL_DISTRIBUTOR, $p->queue_state);
    }

    public function test_nomor_selalu_tutup_tetap_close_tapi_pesannya_tercatat(): void
    {
        $p = $this->chat(attrs: ['selalu_tutup' => true, 'status' => CrmConversation::STATUS_ARSIP, 'closed_at' => now()]);

        $this->pesanMasuk();
        $p->refresh();

        $this->assertFalse($p->terbuka());
        $this->assertSame(1, $p->messages()->count());
    }

    /* ----------------------------------------------------- tutup otomatis */

    public function test_tutup_otomatis_selesai_dan_distributor_yang_sepi_3_hari(): void
    {
        $selesai     = $this->chat('628111000001', ['queue_state' => CrmConversation::LABEL_SELESAI, 'last_message_at' => now()->subDays(4)]);
        $distributor = $this->chat('628111000002', ['is_distributor' => true, 'last_message_at' => now()->subDays(4)]);
        $masihRamai  = $this->chat('628111000003', ['queue_state' => CrmConversation::LABEL_SELESAI, 'last_message_at' => now()->subDay()]);
        $biasa       = $this->chat('628111000004', ['queue_state' => 'cetak', 'last_message_at' => now()->subDays(10)]);
        // Tanya Harga ikut Close otomatis (ditambahkan 8 Okt 2026).
        $tanyaHarga  = $this->chat('628111000005', ['queue_state' => 'tanya_harga', 'last_message_at' => now()->subDays(4)]);
        $tanyaBaru   = $this->chat('628111000006', ['queue_state' => 'tanya_harga', 'last_message_at' => now()->subDays(2)]);

        $this->assertSame(3, app(TutupOtomatisService::class)->jalankan());

        $this->assertFalse($selesai->fresh()->terbuka());
        $this->assertFalse($distributor->fresh()->terbuka());
        $this->assertFalse($tanyaHarga->fresh()->terbuka());
        $this->assertTrue($tanyaBaru->fresh()->terbuka());
        $this->assertTrue($masihRamai->fresh()->terbuka());
        $this->assertTrue($biasa->fresh()->terbuka());
    }

    /* -------------------------------------------------------------- daftar */

    public function test_tab_bawaan_belum_dioper_hanya_chat_open_tanpa_pemilik(): void
    {
        $belum   = $this->chat('628111000001');
        $dipegang = $this->chat('628111000002', ['owner_user_id' => $this->user()->id]);
        $tutup   = $this->chat('628111000003', CrmConversation::nilaiTutup());

        $this->actingAs($this->user('super_admin'))
            ->get(route('crm.inbox.index'))
            ->assertOk()
            ->assertViewHas('percakapan', fn ($daftar) => $daftar->pluck('id')->all() === [$belum->id]);
    }

    public function test_tab_semua_ikut_menampilkan_yang_close(): void
    {
        $this->chat('628111000001');
        $tutup = $this->chat('628111000002', CrmConversation::nilaiTutup());

        $this->actingAs($this->user())
            ->get(route('crm.inbox.index', ['pemilik' => 'semua']))
            ->assertOk()
            ->assertViewHas('percakapan', fn ($daftar) => $daftar->count() === 2 && $daftar->contains('id', $tutup->id));
    }

    /* ------------------------------------------------------------ hak balas */

    public function test_agen_tidak_bisa_membalas_chat_milik_agen_lain(): void
    {
        $p = $this->chat(attrs: ['owner_user_id' => $this->user()->id]);

        $this->actingAs($this->user())
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertSessionHas('error');

        $this->assertSame(0, $p->messages()->count());
    }

    /**
     * Chat yang belum dioper tidak bisa dibalas siapa pun — super admin pun
     * tidak. Harus dioper dulu ("biar disiplin", 8 Okt 2026).
     */
    public function test_chat_belum_dioper_tidak_bisa_dibalas_bahkan_oleh_super_admin(): void
    {
        $p = $this->chat();

        $this->actingAs($this->user('super_admin'))
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertSessionHas('error');

        $this->assertSame(0, $p->messages()->count());
    }

    /** Ambil sendiri (oper ke diri sendiri) lalu balas: chat Close ikut terbuka lagi. */
    public function test_ambil_chat_lalu_balas_membuka_chat_yang_close(): void
    {
        $sari = $this->user();
        $p = $this->chat(attrs: CrmConversation::nilaiTutup());

        $this->actingAs($sari)
            ->post(route('crm.inbox.oper', $p->id), ['owner_user_id' => $sari->id]);

        $this->actingAs($sari)
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo kak, masih ingat kami?'])
            ->assertSessionHas('success');

        $p->refresh();
        $this->assertSame($sari->id, $p->owner_user_id);
        $this->assertTrue($p->terbuka());
    }

    public function test_layar_chat_belum_dioper_menawarkan_tombol_ambil(): void
    {
        $p = $this->chat();

        $this->actingAs($this->user())
            ->get(route('crm.inbox.show', $p->id))
            ->assertOk()
            ->assertSee('Chat ini belum dioper.')
            ->assertSee('Ambil chat ini')
            ->assertDontSee('placeholder="Tulis balasan…"', false);
    }

    public function test_super_admin_boleh_membalas_chat_siapa_pun(): void
    {
        $munif = $this->user();
        $p = $this->chat(attrs: ['owner_user_id' => $munif->id]);

        $this->actingAs($this->user('super_admin'))
            ->post(route('crm.inbox.balas', $p->id), ['teks' => 'halo'])
            ->assertSessionHas('success');

        // Pemiliknya tidak berpindah hanya karena super admin ikut menjawab.
        $this->assertSame($munif->id, $p->fresh()->owner_user_id);
    }

    /* --------------------------------------------------------------- tombol */

    public function test_selalu_tutup_menutup_sekarang_dan_bisa_dicabut(): void
    {
        $p = $this->chat(attrs: ['unread_count' => 3]);
        $admin = $this->user('super_admin');

        $this->actingAs($admin)->post(route('crm.inbox.selalu-tutup', $p->id));
        $p->refresh();
        $this->assertTrue($p->selalu_tutup);
        $this->assertFalse($p->terbuka());
        $this->assertSame(0, $p->unread_count);

        $this->actingAs($admin)->post(route('crm.inbox.selalu-tutup', $p->id));
        $this->assertFalse($p->fresh()->selalu_tutup);
    }

    public function test_tandai_distributor_memasang_labelnya(): void
    {
        $p = $this->chat(attrs: ['queue_state' => 'cetak']);

        $this->actingAs($this->user())->post(route('crm.inbox.distributor', $p->id));
        $p->refresh();

        $this->assertTrue($p->is_distributor);
        $this->assertSame(CrmConversation::LABEL_DISTRIBUTOR, $p->queue_state);
    }
}
