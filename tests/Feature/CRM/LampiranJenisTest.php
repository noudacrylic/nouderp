<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\ChatManager;
use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Support\MediaKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Jenis lampiran keluar.
 *
 * WhatsApp tidak mengenal "lampiran" sebagai satu benda: gambar, video, audio,
 * dan dokumen adalah empat jenis pesan berbeda dengan batas ukuran yang jauh
 * berbeda. Mengirim PDF sebagai 'image' ditolak Meta — dan penolakan itu datang
 * SETELAH berkasnya terlanjur terunggah, jenis kegagalan yang paling melelahkan.
 */
class LampiranJenisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['crm.dry_run' => true]);
        app(ChatManager::class)->fake()->reset();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function percakapan(): CrmConversation
    {
        $p = CrmConversation::findOrCreateFor('628998844666');
        $p->forceFill(['window_expires_at' => now()->addHours(5), 'status' => CrmConversation::STATUS_AKTIF])->save();

        return $p;
    }

    public function test_mime_dipetakan_ke_jenis_pesan_whatsapp(): void
    {
        $this->assertSame('image', MediaKind::for('image/png')['type']);
        $this->assertSame('image', MediaKind::for('image/jpeg; charset=binary')['type']);
        $this->assertSame('video', MediaKind::for('video/mp4')['type']);
        $this->assertSame('audio', MediaKind::for('audio/ogg')['type']);
        $this->assertSame('document', MediaKind::for('application/pdf')['type']);

        // webp & gif SENGAJA jatuh ke dokumen: WhatsApp menolak keduanya sebagai
        // gambar biasa (webp diperlakukan sebagai sticker, gif tak didukung).
        $this->assertSame('document', MediaKind::for('image/webp')['type']);
        $this->assertSame('document', MediaKind::for('image/gif')['type']);

        // Berkas tanpa mime yang dikenal (mis. DXF, RLD) tetap terkirim sebagai
        // dokumen, bukan ditolak — itu justru berkas yang paling sering dikirim.
        $this->assertSame('document', MediaKind::for('application/octet-stream')['type']);
        $this->assertSame('document', MediaKind::for(null)['type']);
    }

    public function test_dokumen_dikirim_sebagai_document_bukan_image(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'teks'   => 'ini gambar kerjanya kak',
                'gambar' => [UploadedFile::fake()->create('BL.DXF', 40, 'application/octet-stream')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('document', CrmMessage::where('direction', CrmMessage::KELUAR)->value('message_type'));

        $kirim = app(ChatManager::class)->fake()->sentOfKind('media');
        $this->assertSame('document', $kirim[0]['type']);
    }

    /**
     * Batas diperiksa PER JENIS. Gambar 8 MB ditolak (batasnya 5 MB) sementara
     * dokumen 8 MB lolos (batasnya 100 MB) — satu aturan tunggal akan salah di
     * salah satu sisi.
     */
    public function test_batas_ukuran_mengikuti_jenis_berkasnya(): void
    {
        $percakapan = $this->percakapan();

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'gambar' => [UploadedFile::fake()->create('besar.png', 8 * 1024, 'image/png')],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, CrmMessage::count());

        $this->actingAs($this->admin())
            ->post(route('crm.inbox.balas', $percakapan), [
                'gambar' => [UploadedFile::fake()->create('gambar-kerja.pdf', 8 * 1024, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmMessage::count());
    }
}
