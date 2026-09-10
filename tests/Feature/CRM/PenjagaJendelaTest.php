<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Services\CrmReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penjaga jendela 24 jam yang bertanya ke vendor di ujung jendela.
 *
 * Catatan `window_expires_at` dihitung dari timestamp webhook, dan itu hampir
 * selalu cukup. Yang tidak cukup: webhook yang datang terlambat. Pesan
 * pelanggan pukul 09.00 yang baru sampai ke ERP pukul 09.20 tanpa membawa
 * timestamp membuat jendela versi kita berakhir dua puluh menit LEBIH LAMBAT
 * dari versi Meta — dan di dua puluh menit itu layar dengan yakin menampilkan
 * kotak ketik untuk pesan yang pasti ditolak.
 *
 * Yang diuji di sini bukan "apakah vendor ditanya", melainkan KAPAN: sekali
 * saja, di ujung jendela. Menanyakannya lebih sering berarti membayar satu
 * panggilan API untuk kepastian yang tak pernah dibutuhkan.
 */
class PenjagaJendelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        app(ChatManager::class)->fake()->windowOpen = true;
    }

    private function percakapan(int $sisaMenit): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');

        $p->forceFill([
            'window_expires_at' => now()->addMinutes($sisaMenit),
            'last_message_at'   => now(),
        ])->save();

        return $p->refresh();
    }

    public function test_di_ujung_jendela_jawaban_vendor_yang_menang_bukan_catatan_kita(): void
    {
        app(ChatManager::class)->fake()->windowOpen = false;

        $p = $this->percakapan(20);

        $hasil = app(CrmReplyService::class)->balas($p, 'lanjut ya kak');

        $this->assertFalse($hasil['success'], 'vendor bilang tutup, jadi tak satu huruf pun boleh berangkat');
        $this->assertStringContainsString('sudah tertutup', (string) $hasil['error']);
        $this->assertSame([], app(ChatManager::class)->fake()->sent);

        // Catatan kita ikut diluruskan, supaya daftar percakapan & thread tidak
        // terus-terusan menjanjikan jendela yang sebenarnya sudah habis.
        $this->assertFalse($p->refresh()->windowIsOpen());
    }

    public function test_di_tengah_jendela_vendor_tidak_ditanya_sama_sekali(): void
    {
        // Vendor "bilang tutup", tapi tak seorang pun bertanya kepadanya: lima
        // jam dari batas, selisih beberapa menit tak mengubah keputusan apa pun.
        app(ChatManager::class)->fake()->windowOpen = false;

        $p = $this->percakapan(5 * 60);

        $hasil = app(CrmReplyService::class)->balas($p, 'lanjut ya kak');

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));
        $this->assertTrue($p->refresh()->windowIsOpen());
    }

    public function test_vendor_yang_tak_terjangkau_tidak_menghalangi_balasan(): void
    {
        // Jaringan bermasalah bukan alasan menolak balasan yang menurut catatan
        // kita sah. Menutup layar karena vendor sedang lambat mengunci admin
        // dari pekerjaan yang sebenarnya boleh ia lakukan.
        app(ChatManager::class)->fake()->windowStatusError = 'cURL error 28: timeout';

        $p = $this->percakapan(20);

        $hasil = app(CrmReplyService::class)->balas($p, 'lanjut ya kak');

        $this->assertTrue($hasil['success'], (string) ($hasil['error'] ?? ''));
        $this->assertTrue($p->refresh()->windowIsOpen());
    }

    public function test_vendor_yang_bilang_masih_terbuka_memperpanjang_catatan_kita(): void
    {
        $p = $this->percakapan(20);

        app(CrmReplyService::class)->balas($p, 'lanjut ya kak');

        // Fake menjawab "terbuka, 24 jam lagi" — dan dialah yang berwenang,
        // bukan hitungan kita yang tinggal dua puluh menit.
        $this->assertGreaterThan(60, (int) $p->refresh()->windowMinutesLeft());
    }
}
