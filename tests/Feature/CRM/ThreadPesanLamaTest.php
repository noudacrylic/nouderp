<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Controllers\CrmInboxController;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Thread dibuka dengan pesan TERBARU saja; riwayat lebih tua dimuat saat
 * digulir ke atas. Chat panjang dulu digambar utuh (355 pesan = 1,3 MB HTML)
 * setiap kali dibuka di HP.
 */
class ThreadPesanLamaTest extends TestCase
{
    use RefreshDatabase;

    private const N = CrmInboxController::PESAN_SEKALI_MUAT;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
    }

    private function cs(): User
    {
        return User::factory()->create(['role' => 'user', 'is_active' => true, 'pwa_crm' => true]);
    }

    /** @return array{0: CrmConversation, 1: \Illuminate\Support\Collection<int, CrmMessage>} */
    private function chatPanjang(int $jumlah): array
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill(['window_expires_at' => now()->addHours(5), 'last_message_at' => now()])->save();

        $awal  = now()->subDays(3);
        $pesan = collect(range(1, $jumlah))->map(fn ($i) => CrmMessage::create([
            'conversation_id' => $p->id,
            'direction'       => $i % 2 ? CrmMessage::MASUK : CrmMessage::KELUAR,
            'message_type'    => 'text',
            'content'         => 'Pesan nomor ' . $i . '.',
            // Dua pesan sedetik: urutan sent_at kembar harus tetap utuh lewat id.
            'sent_at'         => $awal->copy()->addSeconds(intdiv($i, 2)),
        ]));

        return [$p, $pesan];
    }

    public function test_thread_panjang_hanya_menggambar_pesan_terbaru(): void
    {
        [$p] = $this->chatPanjang(self::N + 15);

        $this->actingAs($this->cs())
            ->get(route('cs.thread', $p))
            ->assertOk()
            ->assertSee('Pesan nomor ' . (self::N + 15) . '.')
            ->assertSee('Pesan nomor 16.')
            ->assertDontSee('Pesan nomor 15.')
            ->assertSee(', true)"', false)          // adaLama menyala
            ->assertSee('Muat pesan sebelumnya');
    }

    public function test_thread_pendek_tidak_menawarkan_pesan_lama(): void
    {
        [$p] = $this->chatPanjang(5);

        $this->actingAs($this->cs())
            ->get(route('cs.thread', $p))
            ->assertOk()
            ->assertSee('Pesan nomor 1.')
            ->assertSee(', false)"', false);
    }

    public function test_pesan_lama_menyambung_tanpa_celah_dan_tanpa_dobel(): void
    {
        [$p, $pesan] = $this->chatPanjang(self::N + 15);

        $r = $this->actingAs($this->cs())
            ->getJson(route('crm.inbox.pesan-lama', $p) . '?sebelum=' . $pesan[15]->id)
            ->assertOk()
            ->assertJson(['ada_lagi' => false]);

        $html = $r->json('html');
        for ($i = 1; $i <= 15; $i++) {
            $this->assertStringContainsString('Pesan nomor ' . $i . '.', $html);
        }
        $this->assertStringNotContainsString('Pesan nomor 16.', $html);
        // Urut lama → baru.
        $this->assertLessThan(strpos($html, 'Pesan nomor 2.'), strpos($html, 'Pesan nomor 1.'));
    }

    public function test_pesan_lama_dibatasi_satu_potongan_dan_mengabarkan_sisa(): void
    {
        [$p, $pesan] = $this->chatPanjang(self::N * 2 + 10);
        $teratas = $pesan[self::N + 10]; // gelembung teratas di layar

        $r = $this->actingAs($this->cs())
            ->getJson(route('crm.inbox.pesan-lama', $p) . '?sebelum=' . $teratas->id)
            ->assertOk()
            ->assertJson(['ada_lagi' => true]);

        $this->assertSame(self::N, substr_count($r->json('html'), 'data-mid="'));
    }

    public function test_lompat_ke_kutipan_memuat_sampai_pesan_itu(): void
    {
        [$p, $pesan] = $this->chatPanjang(self::N * 3);
        $teratas = $pesan[self::N * 2];
        $dikutip = $pesan[4]; // "Pesan nomor 5."

        $r = $this->actingAs($this->cs())
            ->getJson(route('crm.inbox.pesan-lama', $p) . '?sebelum=' . $teratas->id . '&sampai=' . $dikutip->id)
            ->assertOk()
            ->assertJson(['ada_lagi' => true]);

        $this->assertStringContainsString('data-mid="' . $dikutip->id . '"', $r->json('html'));
        $this->assertStringNotContainsString('data-mid="' . $pesan[3]->id . '"', $r->json('html'));
    }

    public function test_pesan_dari_percakapan_lain_ditolak(): void
    {
        [$p] = $this->chatPanjang(3);
        $lain = CrmConversation::findOrCreateFor('628111222333');
        $asing = CrmMessage::create([
            'conversation_id' => $lain->id, 'direction' => CrmMessage::MASUK,
            'message_type' => 'text', 'content' => 'rahasia', 'sent_at' => now(),
        ]);

        $this->actingAs($this->cs())
            ->getJson(route('crm.inbox.pesan-lama', $p) . '?sebelum=' . $asing->id)
            ->assertNotFound();
    }
}
